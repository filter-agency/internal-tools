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
    public static function byProjectTask(int $clientId, CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();

        $rows = TimeEntry::query()
            ->toBase()
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->where('projects.client_id', $clientId)
            ->where('time_entries.spent_on', '>=', $start->toDateString())
            ->where('time_entries.spent_on', '<', $start->addMonth()->toDateString())
            ->groupBy('time_entries.project_id', 'time_entries.task_id')
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
