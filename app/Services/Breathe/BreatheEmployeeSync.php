<?php

namespace App\Services\Breathe;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Copies start date, leave date and employment type from Breathe HR onto users.
 * Breathe is the source of truth: its values, including blanks, replace what is saved here.
 */
final class BreatheEmployeeSync
{
    public function __construct(private readonly BreatheClient $client) {}

    public function run(bool $dryRun = false): BreatheSyncResult
    {
        $problems = [];
        /** @var array<int, array{id: int, label: string, email: string, started_on: string|null, left_on: string|null, is_contractor: bool|null}> $byId */
        $byId = [];
        /** @var array<string, list<int>> $idsByEmail */
        $idsByEmail = [];

        foreach ($this->client->employees() as $record) {
            $employee = $this->parse($record, $problems);
            if ($employee === null) {
                continue;
            }

            $byId[$employee['id']] = $employee;
            if ($employee['email'] !== '') {
                $idsByEmail[$employee['email']][] = $employee['id'];
            }
        }

        // Already-linked users first, so an email match can never take someone else's record.
        $users = User::query()
            ->orderByRaw('breathe_employee_id is null')
            ->orderBy('id')
            ->get();

        $claimed = [];
        $chosenBy = [];
        $writes = [];
        $updated = [];
        $unchanged = 0;

        foreach ($users as $user) {
            $candidateIds = $idsByEmail[mb_strtolower(trim($user->email))] ?? [];
            if ($user->breathe_employee_id !== null) {
                if (! isset($byId[$user->breathe_employee_id])) {
                    $problems[] = "{$user->name} is linked to Breathe employee #{$user->breathe_employee_id}, which Breathe no longer returns.";

                    continue;
                }
                $candidateIds[] = $user->breathe_employee_id;
            }

            $candidateIds = array_values(array_unique($candidateIds));
            if ($candidateIds === []) {
                continue;
            }

            array_push($claimed, ...$candidateIds);

            // A rejoiner has an old and a new record; the latest join is their current one.
            usort($candidateIds, fn (int $a, int $b): int => [$byId[$b]['started_on'] ?? '', $b] <=> [$byId[$a]['started_on'] ?? '', $a]);
            $employee = $byId[$candidateIds[0]];

            if (isset($chosenBy[$employee['id']])) {
                $problems[] = "{$employee['label']} in Breathe matches both {$chosenBy[$employee['id']]} and {$user->name}, so {$user->name} was not updated.";

                continue;
            }
            $chosenBy[$employee['id']] = $user->name;

            $values = [
                'breathe_employee_id' => $employee['id'],
                'started_on' => $employee['started_on'],
                'left_on' => $employee['left_on'],
            ];
            if ($employee['is_contractor'] !== null) {
                $values['is_contractor'] = $employee['is_contractor'];
            }

            $changes = $this->changes($user, $values);
            if ($changes === []) {
                $unchanged++;
            } else {
                $updated[] = ['name' => $user->name, 'changes' => $changes];
            }

            $writes[] = [$user, $values];
        }

        $today = CarbonImmutable::today()->toDateString();
        $unmatched = [];
        foreach ($byId as $id => $employee) {
            $isCurrent = $employee['left_on'] === null || $employee['left_on'] >= $today;
            if ($isCurrent && ! in_array($id, $claimed, true)) {
                $unmatched[] = $employee['label'];
            }
        }

        if (! $dryRun) {
            $syncedAt = now();
            DB::transaction(function () use ($writes, $syncedAt): void {
                foreach ($writes as [$user, $values]) {
                    $user->forceFill($values + ['breathe_synced_at' => $syncedAt])->save();
                }
            });
        }

        return new BreatheSyncResult($updated, $unchanged, $unmatched, $problems);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $problems
     * @return array{id: int, label: string, email: string, started_on: string|null, left_on: string|null, is_contractor: bool|null}|null
     */
    private function parse(array $record, array &$problems): ?array
    {
        $id = filter_var($record['id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim(((string) ($record['first_name'] ?? '')).' '.((string) ($record['last_name'] ?? '')));
        $email = mb_strtolower(trim((string) ($record['email'] ?? '')));
        $label = ($name !== '' ? $name : 'Unnamed employee').($email !== '' ? " <{$email}>" : '');

        if ($id === false) {
            $problems[] = "{$label}: Breathe record has no employee id.";

            return null;
        }

        $label .= " (Breathe #{$id})";

        $startedOn = $this->date($record['join_date'] ?? null);
        $leftOn = $this->date($record['leaving_date'] ?? null);
        if ($startedOn === false || $leftOn === false) {
            $problems[] = "{$label}: join or leaving date is not a YYYY-MM-DD date.";

            return null;
        }
        if ($startedOn !== null && $leftOn !== null && $leftOn < $startedOn) {
            $problems[] = "{$label}: leaving date {$leftOn} is before join date {$startedOn}.";

            return null;
        }

        $personType = trim((string) ($record['person_type'] ?? ''));
        $isContractor = match (mb_strtolower($personType)) {
            'contractor' => true,
            'employee' => false,
            default => null,
        };
        // Former staff are never alerted on, so an odd type on an old record stays quiet.
        $isCurrent = $leftOn === null || $leftOn >= CarbonImmutable::today()->toDateString();
        if ($isContractor === null && $isCurrent) {
            $problems[] = "{$label}: person type \"{$personType}\" is not Employee or Contractor, so employment type was left as it is.";
        }

        return [
            'id' => $id,
            'label' => $label,
            'email' => $email,
            'started_on' => $startedOn,
            'left_on' => $leftOn,
            'is_contractor' => $isContractor,
        ];
    }

    /** Null for a blank value, false for one that is not a real YYYY-MM-DD date. */
    private function date(mixed $value): string|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : false;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array{from: string|null, to: string|null}>
     */
    private function changes(User $user, array $values): array
    {
        $current = [
            'breathe_employee_id' => $user->breathe_employee_id,
            'started_on' => $user->started_on?->toDateString(),
            'left_on' => $user->left_on?->toDateString(),
            'is_contractor' => (bool) $user->is_contractor,
        ];

        $changes = [];
        foreach ($values as $field => $value) {
            if ($current[$field] !== $value) {
                $changes[$field] = ['from' => $this->display($current[$field]), 'to' => $this->display($value)];
            }
        }

        return $changes;
    }

    private function display(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'Contractor' : 'Employee',
            default => (string) $value,
        };
    }
}
