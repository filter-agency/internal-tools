<?php

namespace App\Domain\TimeTracking;

use App\Models\MonthLock;
use App\Models\MonthLockOverride;
use App\Models\MonthLockOverrideChange;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use WeakMap;

/**
 * The one place that decides whether a time entry write may touch a locked
 * client-month, and that logs writes an admin makes under an override.
 * Bound as scoped, so the project-to-client memo resets per request or job.
 */
final class MonthLockGuard
{
    /** Columns that feed issued figures. Asana sync columns and invoiced_at stay writable. */
    public const LOCKED_FIELDS = [
        'user_id', 'project_id', 'task_id', 'spent_on', 'hours', 'notes',
        'is_billable', 'billable_rate_snapshot', 'billable_amount',
    ];

    /**
     * Values kept in the override change log. is_running is logged so a timer
     * started under an override shows up, but it is only a locked write when a
     * timer starts (see startsTimer()).
     */
    private const LOGGED_FIELDS = [...self::LOCKED_FIELDS, 'is_running'];

    /** @var array<int, int|null> project id => client id */
    private array $projectClients = [];

    /**
     * Overrides that authorised a write in flight, so record() logs against
     * the same overrides without looking them up again.
     *
     * @var WeakMap<TimeEntry, list<MonthLockOverride>>
     */
    private WeakMap $authorised;

    public function __construct()
    {
        $this->authorised = new WeakMap;
    }

    /**
     * @param  'created'|'updated'|'deleted'  $action
     *
     * @throws ValidationException when the write touches a locked client-month
     *                             and the actor is not an admin with an open override
     */
    public function assertWritable(TimeEntry $entry, string $action): void
    {
        $this->authorised[$entry] = $this->overridesFor($entry, $action);
    }

    /**
     * Log a completed write once per override that allowed it.
     *
     * @param  'created'|'updated'|'deleted'  $action
     */
    public function record(TimeEntry $entry, string $action): void
    {
        $overrides = $this->authorised[$entry] ?? $this->overridesFor($entry, $action);
        unset($this->authorised[$entry]);

        if ($overrides === []) {
            return;
        }

        $before = $action === 'created' ? null : $this->lockedValues($entry, original: true);
        $after = $action === 'deleted' ? null : $this->lockedValues($entry, original: false);

        foreach ($overrides as $override) {
            MonthLockOverrideChange::create([
                'month_lock_override_id' => $override->id,
                'time_entry_id' => $entry->id,
                'action' => $action,
                'before' => $before,
                'after' => $after,
            ]);
        }
    }

    /**
     * Re-pointing a project to another client moves its hours between clients
     * without touching an entry, so it is refused while the project has time
     * in a locked month of either client.
     */
    public function assertProjectClientChangeAllowed(Project $project): void
    {
        if (! $project->exists || ! $project->isDirty('client_id')) {
            return;
        }

        $clientIds = array_values(array_unique([(int) $project->getOriginal('client_id'), (int) $project->client_id]));

        $locks = MonthLock::query()
            ->with('client')
            ->whereIn('client_id', $clientIds)
            ->orderBy('month')
            ->orderBy('client_id')
            ->get();

        foreach ($locks as $lock) {
            $start = CarbonImmutable::instance($lock->month)->startOfMonth();

            $hasTime = TimeEntry::query()
                ->where('project_id', $project->id)
                ->where('spent_on', '>=', $start->toDateString())
                ->where('spent_on', '<', $start->addMonth()->toDateString())
                ->exists();

            if ($hasTime) {
                throw ValidationException::withMessages([
                    'client_id' => "This project has time in {$lock->label()}, which is locked for {$lock->client->name}, so its client cannot be changed.",
                ]);
            }
        }

        unset($this->projectClients[$project->id]);
    }

    public function lockFor(int $clientId, CarbonInterface $date): ?MonthLock
    {
        $start = CarbonImmutable::instance($date)->startOfMonth();

        // A range, not an equality: SQLite stores the date cast as "Y-m-d 00:00:00".
        return MonthLock::query()
            ->where('client_id', $clientId)
            ->where('month', '>=', $start->toDateString())
            ->where('month', '<', $start->addMonth()->toDateString())
            ->first();
    }

    /**
     * Locks for every month that overlaps the range.
     *
     * @return array<string, MonthLock> keyed "{clientId}|Y-m"
     */
    public function lockedClientMonths(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return MonthLock::query()
            ->where('month', '>=', $from->startOfMonth()->toDateString())
            ->where('month', '<', $to->startOfMonth()->addMonth()->toDateString())
            ->get()
            ->keyBy(fn (MonthLock $lock): string => $lock->client_id.'|'.$lock->month->format('Y-m'))
            ->all();
    }

    /**
     * @return list<MonthLockOverride>
     */
    private function overridesFor(TimeEntry $entry, string $action): array
    {
        $locks = $this->touchedLocks($entry, $action);

        if ($locks === []) {
            return [];
        }

        $actor = $this->actor();
        $overrides = [];

        foreach ($locks as $lock) {
            $override = $actor?->isAdmin() ? $lock->activeOverrideFor($actor) : null;

            if ($override === null) {
                throw ValidationException::withMessages(['month_lock' => $this->refusal($lock, $actor)]);
            }

            $overrides[] = $override;
        }

        return $overrides;
    }

    /**
     * The locks on the client-months this write reads from or writes to:
     * the new values for a create, the stored values for a delete, and both
     * for an update that changes a locked field or starts a timer.
     *
     * @return list<MonthLock>
     */
    private function touchedLocks(TimeEntry $entry, string $action): array
    {
        $stored = [$entry->getOriginal('project_id'), $entry->getOriginal('spent_on')];
        $current = [$entry->project_id, $entry->spent_on];

        $points = match ($action) {
            'created' => [$current],
            'deleted' => [$stored],
            'updated' => $entry->isDirty(self::LOCKED_FIELDS) || $this->startsTimer($entry) ? [$stored, $current] : [],
            default => throw new InvalidArgumentException("Unknown time entry action [{$action}]."),
        };

        $clientMonths = [];
        foreach ($points as [$projectId, $spentOn]) {
            $clientId = $projectId === null ? null : $this->clientIdFor((int) $projectId);

            if ($clientId !== null && $spentOn instanceof CarbonInterface) {
                $clientMonths[$clientId.'|'.$spentOn->format('Y-m')] = [$clientId, $spentOn];
            }
        }

        $locks = [];
        foreach ($clientMonths as [$clientId, $spentOn]) {
            $lock = $this->lockFor($clientId, $spentOn);

            if ($lock !== null) {
                $locks[] = $lock;
            }
        }

        return $locks;
    }

    /**
     * A timer started on a locked entry could never be stopped, because
     * stopping changes hours. Stopping stays governed by the hours rule.
     */
    private function startsTimer(TimeEntry $entry): bool
    {
        return $entry->isDirty('is_running') && ! $entry->getOriginal('is_running') && $entry->is_running;
    }

    private function clientIdFor(int $projectId): ?int
    {
        if (! array_key_exists($projectId, $this->projectClients)) {
            $clientId = Project::query()->whereKey($projectId)->value('client_id');
            $this->projectClients[$projectId] = $clientId === null ? null : (int) $clientId;
        }

        return $this->projectClients[$projectId];
    }

    /** No actor (CLI, queue) counts as a standard user. */
    private function actor(): ?User
    {
        $user = auth()->user() ?? auth('api')->user();

        return $user instanceof User ? $user : null;
    }

    private function refusal(MonthLock $lock, ?User $actor): string
    {
        $client = $lock->client->name;

        if ($actor?->isAdmin()) {
            return "{$lock->label()} is locked for {$client}. Open an override in Admin > Month locks first.";
        }

        return "{$lock->label()} is locked for {$client}: the report was issued on {$lock->issued_on->format('j F Y')}. Ask an admin to make this change.";
    }

    /**
     * @return array<string, mixed>
     */
    private function lockedValues(TimeEntry $entry, bool $original): array
    {
        $values = [];

        foreach (self::LOGGED_FIELDS as $field) {
            $value = $original ? $entry->getOriginal($field) : $entry->getAttribute($field);

            $values[$field] = $value === null ? null : match ($field) {
                'user_id', 'project_id', 'task_id' => (int) $value,
                'spent_on' => $value instanceof CarbonInterface ? $value->toDateString() : (string) $value,
                'hours', 'billable_rate_snapshot', 'billable_amount' => (float) $value,
                'is_billable', 'is_running' => (bool) $value,
                default => $value,
            };
        }

        return $values;
    }
}
