<?php

namespace App\Domain\Reporting;

use App\Models\MonthLock;
use App\Models\MonthLockOverrideChange;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Compares the hours issued for a locked month with the hours booked today,
 * and lists the overridden changes made to that month since.
 */
final class MonthReconciliation
{
    /**
     * Every client locked for the month: the union of the combinations snapshotted
     * at lock time and those booked now, sorted by client, project and task.
     * $month may be any date in the month.
     *
     * @return Collection<int, ReconciliationRow>
     */
    public static function rows(CarbonImmutable $month): Collection
    {
        $start = $month->startOfMonth();
        $locks = self::locksFor($start)->with(['client', 'snapshots'])->get();

        $figures = [];
        $projectIds = [];
        $taskIds = [];

        foreach ($locks as $lock) {
            $issued = [];
            foreach ($lock->snapshots as $snapshot) {
                $issued["{$snapshot->project_id}|{$snapshot->task_id}"] = (float) $snapshot->hours;
            }
            $current = ClientMonthTotals::byProjectTask($lock->client_id, $start);

            foreach (array_keys($issued + $current) as $key) {
                [$projectId, $taskId] = array_map('intval', explode('|', $key));
                $projectIds[] = $projectId;
                $taskIds[] = $taskId;
                $figures[] = [
                    'lock' => $lock,
                    'projectId' => $projectId,
                    'taskId' => $taskId,
                    'issued' => $issued[$key] ?? 0.0,
                    'current' => $current[$key] ?? 0.0,
                ];
            }
        }

        $projectNames = Project::query()->whereIn('id', array_unique($projectIds))->pluck('name', 'id');
        $taskNames = Task::query()->whereIn('id', array_unique($taskIds))->pluck('name', 'id');

        return collect($figures)
            ->map(fn (array $figure): ReconciliationRow => new ReconciliationRow(
                clientName: $figure['lock']->client->name,
                projectName: $projectNames[$figure['projectId']],
                taskName: $taskNames[$figure['taskId']],
                issuedHours: $figure['issued'],
                currentHours: $figure['current'],
                difference: round($figure['current'] - $figure['issued'], 2),
                clientId: $figure['lock']->client_id,
                projectId: $figure['projectId'],
            ))
            ->sort(fn (ReconciliationRow $a, ReconciliationRow $b): int => strcasecmp($a->clientName, $b->clientName)
                ?: $a->clientId <=> $b->clientId
                ?: strcasecmp($a->projectName, $b->projectName)
                ?: $a->projectId <=> $b->projectId
                ?: strcasecmp($a->taskName, $b->taskName))
            ->values();
    }

    /**
     * Changes made under an override on any lock for the month, newest first,
     * with the override, its user and its lock's client loaded.
     *
     * @return Collection<int, MonthLockOverrideChange>
     */
    public static function changes(CarbonImmutable $month): Collection
    {
        $start = $month->startOfMonth();

        return MonthLockOverrideChange::query()
            ->whereHas('override.monthLock', fn (Builder $query) => $query->whereDate('month', $start->toDateString()))
            ->with(['override.user', 'override.monthLock.client'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Builder<MonthLock> */
    private static function locksFor(CarbonImmutable $start): Builder
    {
        // SQLite stores the date cast with a time part, so never compare for equality.
        return MonthLock::query()->whereDate('month', $start->toDateString());
    }
}
