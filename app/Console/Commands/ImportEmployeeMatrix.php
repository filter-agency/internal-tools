<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use stdClass;

class ImportEmployeeMatrix extends Command
{
    /** Columns 0-4 of the header row. */
    private const HEADER = ['First name', 'Surname', 'Join date', 'Status', 'Person type'];

    /** Month columns start here, one per calendar month from February 2024. */
    private const FIRST_MONTH_COLUMN = 5;

    private const FIRST_MONTH = '2024-02-01';

    /** Highest serial number Excel can hold (31 December 9999). */
    private const MAX_EXCEL_SERIAL = 2958465;

    protected $signature = 'users:import-employee-matrix
        {path : Path to the HR "Employee Matrix" spreadsheet (.xlsx)}
        {--dry-run : Show what would change without writing anything}';

    protected $description = 'Set each matched user\'s start date, leave date and person type from the HR Employee Matrix spreadsheet.';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $sheets = Excel::toArray(new stdClass, $path);

        /** @var array<int, array<int, mixed>> $rows */
        $rows = array_values($sheets[0] ?? []);

        $headerIndex = $this->headerRowIndex($rows);
        if ($headerIndex === null) {
            $this->error('Could not find a header row starting with "First name" on the first sheet. Nothing was written.');

            return self::FAILURE;
        }

        $found = array_map(fn (mixed $cell): string => $this->text($cell), array_slice($rows[$headerIndex], 0, count(self::HEADER)));
        if (array_map('mb_strtolower', $found) !== array_map('mb_strtolower', self::HEADER)) {
            $this->error('Unexpected header row. Expected "'.implode(', ', self::HEADER).'" in columns A to E but found "'.implode(', ', $found).'". Nothing was written.');

            return self::FAILURE;
        }

        $usersByName = $this->usersByName();

        /** @var list<array{user: User, changes: array<string, mixed>}> $updates */
        $updates = [];
        $upToDate = 0;
        $unmatched = [];
        $ambiguous = [];
        $skipped = [];
        $approximated = [];
        $noLeaveMonth = [];
        $currentWithLeaveMarker = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $row) {
            $first = $this->text($row[0] ?? null);
            $surname = $this->text($row[1] ?? null);

            // Total rows and spacer rows have no name.
            if ($first === '' || $surname === '') {
                continue;
            }

            $label = "{$first} {$surname}";
            $rowNumber = $index + 1;

            $startedOn = $this->parseDate($row[2] ?? null);
            if ($startedOn === null) {
                $skipped[] = "Row {$rowNumber} {$label}: join date {$this->describe($row[2] ?? null)} is not a date this command can read";

                continue;
            }

            $status = $this->text($row[3] ?? null);
            $isExEmployee = strcasecmp($status, 'Ex-employee') === 0;
            if (! $isExEmployee && strcasecmp($status, 'Current employee') !== 0) {
                $skipped[] = "Row {$rowNumber} {$label}: status {$this->describe($row[3] ?? null)} is not \"Current employee\" or \"Ex-employee\"";

                continue;
            }

            $isContractor = match (mb_strtolower($this->text($row[4] ?? null))) {
                'contractor' => true,
                'employee' => false,
                default => null,
            };
            if ($isContractor === null) {
                $skipped[] = "Row {$rowNumber} {$label}: person type {$this->describe($row[4] ?? null)} is not \"Employee\" or \"Contractor\"";

                continue;
            }

            $matches = $usersByName[$this->normalise($label)] ?? [];
            if ($matches === []) {
                $unmatched[] = $label;

                continue;
            }
            if (count($matches) > 1) {
                $ambiguous[] = $label.' ('.count($matches).' users)';

                continue;
            }
            $user = $matches[0];

            $changes = [
                'started_on' => $startedOn->toDateString(),
                'is_contractor' => $isContractor,
            ];

            $leaveMonth = $this->lastLeaveMonth($row);
            if ($isExEmployee) {
                if ($leaveMonth === null) {
                    $noLeaveMonth[] = $user->name;
                } else {
                    $changes['left_on'] = $leaveMonth->endOfMonth()->toDateString();
                    $approximated[] = "{$user->name}: {$changes['left_on']} (end of {$leaveMonth->format('M Y')})";
                }
            } elseif ($leaveMonth !== null) {
                $currentWithLeaveMarker[] = "{$user->name} (leave marker in {$leaveMonth->format('M Y')})";
            }

            if ($this->wouldChange($user, $changes)) {
                $updates[] = ['user' => $user, 'changes' => $changes];
            } else {
                $upToDate++;
            }
        }

        if (! $this->option('dry-run')) {
            DB::transaction(function () use ($updates): void {
                foreach ($updates as $update) {
                    $update['user']->update($update['changes']);
                }
            });
        }

        $this->report($updates, $upToDate, $unmatched, $ambiguous, $skipped, $approximated, $noLeaveMonth, $currentWithLeaveMarker);

        return self::SUCCESS;
    }

    /**
     * @param  list<array{user: User, changes: array<string, mixed>}>  $updates
     * @param  list<string>  $unmatched
     * @param  list<string>  $ambiguous
     * @param  list<string>  $skipped
     * @param  list<string>  $approximated
     * @param  list<string>  $noLeaveMonth
     * @param  list<string>  $currentWithLeaveMarker
     */
    private function report(array $updates, int $upToDate, array $unmatched, array $ambiguous, array $skipped, array $approximated, array $noLeaveMonth, array $currentWithLeaveMarker): void
    {
        if ($updates !== []) {
            $this->table(['Name', 'Start', 'Leave', 'Person type'], array_map(function (array $update): array {
                $user = $update['user'];
                $changes = $update['changes'];

                return [
                    $user->name,
                    $changes['started_on'],
                    $changes['left_on'] ?? $user->left_on?->toDateString() ?? '',
                    $changes['is_contractor'] ? 'Contractor' : 'Employee',
                ];
            }, $updates));
        }

        $this->info(sprintf(
            'Updated: %d. Already up to date: %d. Unmatched: %d. Ambiguous: %d. Skipped rows: %d.',
            count($updates),
            $upToDate,
            count($unmatched),
            count($ambiguous),
            count($skipped),
        ));

        $this->section('Unmatched names (no user with that name)', $unmatched);
        $this->section('Ambiguous matches (more than one user has that name, skipped)', $ambiguous);
        $this->section('Skipped rows', $skipped);
        $this->section('Approximated leave dates (end of the last month marked p)', $approximated);
        $this->section('Warning: ex-employee with no leave month found (leave date left unchanged)', $noLeaveMonth);
        $this->section('Warning: current employee with a leave marker (leave date not set)', $currentWithLeaveMarker);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->warn('Dry run: nothing written.');
        }
    }

    /** @param  list<string>  $lines */
    private function section(string $title, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $this->newLine();
        $this->comment($title.':');
        foreach ($lines as $line) {
            $this->line("  - {$line}");
        }
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    private function headerRowIndex(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            if (strcasecmp($this->text($row[0] ?? null), self::HEADER[0]) === 0) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Archived users are included: leavers are usually archived.
     *
     * @return array<string, list<User>>
     */
    private function usersByName(): array
    {
        $byName = [];
        foreach (User::query()->get() as $user) {
            $byName[$this->normalise($user->name)][] = $user;
        }

        return $byName;
    }

    /** @param  array<string, mixed>  $changes */
    private function wouldChange(User $user, array $changes): bool
    {
        if ($user->started_on?->toDateString() !== $changes['started_on'] || $user->is_contractor !== $changes['is_contractor']) {
            return true;
        }

        return isset($changes['left_on']) && $user->left_on?->toDateString() !== $changes['left_on'];
    }

    /**
     * First day of the last month whose cell is marked "p".
     *
     * @param  array<int, mixed>  $row
     */
    private function lastLeaveMonth(array $row): ?CarbonImmutable
    {
        $last = null;
        foreach ($row as $column => $cell) {
            if ($column >= self::FIRST_MONTH_COLUMN && strcasecmp($this->text($cell), 'p') === 0) {
                $last = $column;
            }
        }

        if ($last === null) {
            return null;
        }

        return CarbonImmutable::parse(self::FIRST_MONTH)->addMonthsNoOverflow($last - self::FIRST_MONTH_COLUMN);
    }

    /**
     * Accepts an Excel serial number, a date object, or a Y-m-d or d/m/Y string.
     */
    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (is_string($value)) {
            $value = trim($value);

            foreach (['!Y-m-d', '!d/m/Y'] as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $value);
                $errors = DateTimeImmutable::getLastErrors();

                if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return CarbonImmutable::instance($date);
                }
            }

            if (! is_numeric($value)) {
                return null;
            }
            $value += 0;
        }

        if ((is_int($value) || is_float($value)) && $value >= 1 && $value <= self::MAX_EXCEL_SERIAL) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject($value))->startOfDay();
        }

        return null;
    }

    private function normalise(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }

    private function text(mixed $cell): string
    {
        return is_scalar($cell) ? trim((string) $cell) : '';
    }

    private function describe(mixed $cell): string
    {
        $text = $this->text($cell);

        return $text === '' ? '(blank)' : "\"{$text}\"";
    }
}
