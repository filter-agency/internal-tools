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

        if (! $this->monthColumnsStartAtFebruary2024($rows, $headerIndex)) {
            $this->error("Month columns don't start at Feb 2024; check the sheet layout. Nothing was written.");

            return self::FAILURE;
        }

        $usersByName = $this->usersByName();

        /** @var list<array{user: User, changes: array<string, mixed>}> $updates */
        $updates = [];
        $upToDate = 0;
        $lists = [
            'unmatched' => [],
            'ambiguous' => [],
            'duplicates' => [],
            'conflicts' => [],
            'skipped' => [],
            'approximated' => [],
            'noLeaveMonth' => [],
            'currentWithLeaveMarker' => [],
        ];

        // Match every named row to a user first, so a person listed twice can be spotted.
        /** @var array<int, list<array{number: int, label: string, cells: array<int, mixed>}>> $rowsByUser */
        $rowsByUser = [];
        /** @var array<int, User> $usersById */
        $usersById = [];

        foreach (array_slice($rows, $headerIndex + 1, null, true) as $index => $row) {
            $first = $this->text($row[0] ?? null);
            $surname = $this->text($row[1] ?? null);

            // Total rows and spacer rows have no name.
            if ($first === '' || $surname === '') {
                continue;
            }

            $label = "{$first} {$surname}";
            $matches = $usersByName[$this->normalise($label)] ?? [];

            if ($matches === []) {
                $lists['unmatched'][] = $label;

                continue;
            }
            if (count($matches) > 1) {
                $lists['ambiguous'][] = $label.' ('.count($matches).' users)';

                continue;
            }

            $user = $matches[0];
            $rowsByUser[$user->id][] = ['number' => $index + 1, 'label' => $label, 'cells' => $row];
            $usersById[$user->id] = $user;
        }

        foreach ($rowsByUser as $userId => $userRows) {
            $user = $usersById[$userId];

            // Which row wins is a judgement call (a rejoiner has two), so write nothing.
            if (count($userRows) > 1) {
                $lists['duplicates'][] = $user->name.' (rows '.implode(', ', array_column($userRows, 'number')).')';

                continue;
            }

            ['number' => $rowNumber, 'label' => $label, 'cells' => $row] = $userRows[0];

            $startedOn = $this->parseDate($row[2] ?? null);
            if ($startedOn === null) {
                $lists['skipped'][] = "Row {$rowNumber} {$label}: join date {$this->describe($row[2] ?? null)} is not a date this command can read";

                continue;
            }

            $status = $this->text($row[3] ?? null);
            $isExEmployee = strcasecmp($status, 'Ex-employee') === 0;
            if (! $isExEmployee && strcasecmp($status, 'Current employee') !== 0) {
                $lists['skipped'][] = "Row {$rowNumber} {$label}: status {$this->describe($row[3] ?? null)} is not \"Current employee\" or \"Ex-employee\"";

                continue;
            }

            $isContractor = match (mb_strtolower($this->text($row[4] ?? null))) {
                'contractor' => true,
                'employee' => false,
                default => null,
            };
            if ($isContractor === null) {
                $lists['skipped'][] = "Row {$rowNumber} {$label}: person type {$this->describe($row[4] ?? null)} is not \"Employee\" or \"Contractor\"";

                continue;
            }

            $leaveMarker = $this->lastLeaveMonth($row);
            $leaveOn = $isExEmployee ? $leaveMarker?->endOfMonth() : null;

            // The leave that will stand after this run: the derived one, else the saved one.
            $startDate = $startedOn->toDateString();
            $effectiveLeave = $leaveOn?->toDateString() ?? $user->left_on?->toDateString();
            if ($effectiveLeave !== null && $effectiveLeave < $startDate) {
                $origin = $leaveOn !== null ? "end of {$leaveOn->format('M Y')}" : 'already saved';
                $lists['conflicts'][] = "{$user->name}: leave date {$effectiveLeave} ({$origin}) is before start date {$startDate}";

                continue;
            }

            $changes = [
                'started_on' => $startDate,
                'is_contractor' => $isContractor,
            ];

            if ($leaveOn !== null) {
                $changes['left_on'] = $leaveOn->toDateString();
                $lists['approximated'][] = "{$user->name}: {$changes['left_on']} (end of {$leaveOn->format('M Y')})";
            } elseif ($isExEmployee) {
                $lists['noLeaveMonth'][] = $user->name;
            } elseif ($leaveMarker !== null) {
                $lists['currentWithLeaveMarker'][] = "{$user->name} (leave marker in {$leaveMarker->format('M Y')})";
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

        $this->report($updates, $upToDate, $lists);

        return self::SUCCESS;
    }

    /**
     * @param  list<array{user: User, changes: array<string, mixed>}>  $updates
     * @param  array{unmatched: list<string>, ambiguous: list<string>, duplicates: list<string>, conflicts: list<string>, skipped: list<string>, approximated: list<string>, noLeaveMonth: list<string>, currentWithLeaveMarker: list<string>}  $lists
     */
    private function report(array $updates, int $upToDate, array $lists): void
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
            'Updated: %d. Already up to date: %d. Unmatched: %d. Ambiguous: %d. Listed more than once: %d. Leave before start: %d. Skipped rows: %d.',
            count($updates),
            $upToDate,
            count($lists['unmatched']),
            count($lists['ambiguous']),
            count($lists['duplicates']),
            count($lists['conflicts']),
            count($lists['skipped']),
        ));

        $this->section('Unmatched names (no user with that name)', $lists['unmatched']);
        $this->section('Ambiguous matches (more than one user has that name, skipped)', $lists['ambiguous']);
        $this->section('Listed more than once (not updated)', $lists['duplicates']);
        $this->section('Leave date before start date (not updated)', $lists['conflicts']);
        $this->section('Skipped rows', $lists['skipped']);
        $this->section('Approximated leave dates (end of the last month marked p)', $lists['approximated']);
        $this->section('Warning: ex-employee with no leave month found (leave date left unchanged)', $lists['noLeaveMonth']);
        $this->section('Warning: current employee with a leave marker (leave date not set)', $lists['currentWithLeaveMarker']);

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

    /**
     * Every derived leave date depends on the month columns starting at Feb 2024:
     * the header must read "Feb" and the year row just above it must read 2024.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function monthColumnsStartAtFebruary2024(array $rows, int $headerIndex): bool
    {
        $firstMonth = $this->text($rows[$headerIndex][self::FIRST_MONTH_COLUMN] ?? null);
        $firstYear = trim($this->text($rows[$headerIndex - 1][self::FIRST_MONTH_COLUMN] ?? null), "'");

        return strcasecmp($firstMonth, 'Feb') === 0 && $firstYear === '2024';
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
