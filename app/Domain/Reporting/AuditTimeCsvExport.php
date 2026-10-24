<?php

namespace App\Domain\Reporting;

use App\Domain\TimeTracking\HoursFormatter;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * The audit export: one row per person, filter role, contract position, month,
 * client, project and task, with hours. It carries no notes, rates or amounts.
 * Archived projects are always included so the totals agree with the time report.
 */
final class AuditTimeCsvExport
{
    public const HEADERS = ['Name', 'Filter role', 'Contract position', 'Month', 'Client', 'Project', 'Task', 'Hours'];

    /** @param  array<int, int>  $clientIds  an empty list exports no rows, not every client */
    public function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly array $clientIds,
    ) {}

    /**
     * Sorted by name, month, client, project and task, ignoring case.
     *
     * @return Collection<int, array{Name: string, 'Filter role': string, 'Contract position': string, Month: string, Client: string, Project: string, Task: string, Hours: float}>
     */
    public function rows(): Collection
    {
        if ($this->clientIds === []) {
            return collect();
        }

        $positions = ContractPositionTimeline::load();
        $folded = [];

        // The position is looked up per booked day, so a month is split when it
        // changes part-way through; month and day come from PHP to keep the SQL portable.
        foreach ($this->dailyTotals() as $day) {
            $spentOn = substr((string) $day->spent_on, 0, 10);

            $row = [
                'Name' => (string) $day->user_name,
                'Filter role' => (string) $day->role_title,
                'Contract position' => $positions->labelOn((int) $day->user_id, $spentOn),
                'Month' => substr($spentOn, 0, 7),
                'Client' => (string) $day->client_name,
                'Project' => (string) $day->project_name,
                'Task' => (string) $day->task_name,
                'Hours' => 0.0,
            ];

            $key = implode("\x1f", array_slice($row, 0, 7));
            $folded[$key] ??= $row;
            $folded[$key]['Hours'] += (float) $day->hours;
        }

        return collect(array_values($folded))
            ->map(function (array $row): array {
                $row['Hours'] = round($row['Hours'], 2);

                return $row;
            })
            ->sort(fn (array $a, array $b): int => $this->compare($a, $b))
            ->values();
    }

    /**
     * Write the full CSV (headers + rows) to the given output stream.
     * Uses \r\n line endings per RFC 4180. No BOM.
     *
     * @param  resource  $handle  A writable stream (e.g. php://output or fopen())
     */
    public function writeTo(mixed $handle): void
    {
        fwrite($handle, implode(',', self::HEADERS)."\r\n");

        foreach ($this->rows() as $row) {
            fwrite($handle, implode(',', [
                CsvFormatter::text($row['Name']),
                CsvFormatter::text($row['Filter role']),
                CsvFormatter::text($row['Contract position']),
                $row['Month'],
                CsvFormatter::text($row['Client']),
                CsvFormatter::text($row['Project']),
                CsvFormatter::text($row['Task']),
                HoursFormatter::asReportDecimal($row['Hours']),
            ])."\r\n");
        }
    }

    /**
     * Return the full CSV as a string (for testing; avoid for large exports).
     */
    public function toCsv(): string
    {
        $handle = fopen('php://memory', 'r+');
        assert($handle !== false);
        $this->writeTo($handle);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    /**
     * Hours per person, day, project and task for the chosen clients. No
     * archived filter and no notes, rates or amounts are read.
     *
     * @return LazyCollection<int, \stdClass>
     */
    private function dailyTotals(): LazyCollection
    {
        return TimeEntry::query()
            ->toBase()
            ->join('users', 'users.id', '=', 'time_entries.user_id')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->join('clients', 'clients.id', '=', 'projects.client_id')
            ->join('tasks', 'tasks.id', '=', 'time_entries.task_id')
            ->where('time_entries.spent_on', '>=', $this->from->toDateString())
            ->where('time_entries.spent_on', '<', $this->to->addDay()->toDateString())
            ->whereIn('projects.client_id', $this->clientIds)
            ->select([
                'time_entries.user_id',
                'time_entries.spent_on',
                'users.name as user_name',
                'users.role_title',
                'clients.name as client_name',
                'projects.name as project_name',
                'tasks.name as task_name',
            ])
            ->selectRaw('SUM(time_entries.hours) as hours')
            ->groupBy(
                'time_entries.user_id',
                'time_entries.spent_on',
                'time_entries.project_id',
                'time_entries.task_id',
                'users.name',
                'users.role_title',
                'clients.name',
                'projects.name',
                'tasks.name',
            )
            ->cursor();
    }

    /**
     * @param  array{Name: string, 'Filter role': string, 'Contract position': string, Month: string, Client: string, Project: string, Task: string, Hours: float}  $a
     * @param  array{Name: string, 'Filter role': string, 'Contract position': string, Month: string, Client: string, Project: string, Task: string, Hours: float}  $b
     */
    private function compare(array $a, array $b): int
    {
        foreach (['Name', 'Month', 'Client', 'Project', 'Task', 'Filter role', 'Contract position'] as $column) {
            $order = strcasecmp($a[$column], $b[$column]);

            if ($order !== 0) {
                return $order;
            }
        }

        return 0;
    }
}
