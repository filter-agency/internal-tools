<?php

namespace App\Domain\Reporting;

use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

/**
 * Hours booked against a client in one calendar month. Shared by the month
 * lock snapshot and the reconciliation report so both read the same figures.
 */
final class ClientMonthTotals
{
    /**
     * Includes entries on archived projects. $month may be any date in the month.
     *
     * @return array<string, float> hours keyed "{projectId}|{taskId}"
     */
    public static function byProjectTask(int $clientId, CarbonImmutable $month, bool $currentRead = false): array
    {
        $start = $month->startOfMonth();

        $query = TimeEntry::query()
            ->toBase()
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->where('projects.client_id', $clientId)
            ->where('time_entries.spent_on', '>=', $start->toDateString())
            ->where('time_entries.spent_on', '<', $start->addMonth()->toDateString());

        // A month may close inside an existing transaction. Read current rows
        // after acquiring the client mutex, not that transaction's old snapshot.
        if ($currentRead) {
            $rows = $query->select(['time_entries.project_id', 'time_entries.task_id', 'time_entries.hours'])
                ->orderBy('time_entries.id')->sharedLock()->get();
            $totals = [];
            foreach ($rows as $row) {
                $key = "{$row->project_id}|{$row->task_id}";
                $totals[$key] = ($totals[$key] ?? 0.0) + (float) $row->hours;
            }

            return array_map(fn (float $hours): float => round($hours, 2), $totals);
        }

        $rows = $query->groupBy('time_entries.project_id', 'time_entries.task_id')
            ->orderBy('time_entries.project_id')
            ->orderBy('time_entries.task_id')
            ->selectRaw('time_entries.project_id, time_entries.task_id, SUM(time_entries.hours) as total_hours')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals["{$row->project_id}|{$row->task_id}"] = round((float) $row->total_hours, 2);
        }

        return $totals;
    }
}
