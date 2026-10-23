<?php

namespace App\Domain\TimeTracking;

use App\Domain\Billing\RateResolver;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TimeEntryService
{
    public function __construct(private readonly RateResolver $rateResolver) {}

    /**
     * @param  array{project_id: int, task_id: int, spent_on: string, hours: float, notes: string|null, asana_task_gid?: string|null}  $data
     */
    public function create(User $user, array $data): TimeEntry
    {
        // Transactions here and in update()/delete() keep a write made under a
        // month lock override atomic with the change log the observer writes.
        return DB::transaction(function () use ($user, $data): TimeEntry {
            $project = Project::with(['tasks', 'users'])->findOrFail($data['project_id']);
            $task = Task::findOrFail($data['task_id']);

            $resolution = $this->rateResolver->resolveWithHours($project, $task, $user, $data['hours']);

            return TimeEntry::create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'task_id' => $task->id,
                'spent_on' => $data['spent_on'],
                'hours' => $data['hours'],
                'notes' => $data['notes'] ?? null,
                'is_running' => false,
                'is_billable' => $resolution->isBillable,
                'billable_rate_snapshot' => $resolution->rateSnapshot,
                'billable_amount' => $resolution->billableAmount,
                'asana_task_gid' => $data['asana_task_gid'] ?? null,
            ]);
        });
    }

    /**
     * @param  array{project_id?: int, task_id?: int, spent_on?: string, hours?: float, notes?: string|null, asana_task_gid?: string|null}  $data
     */
    public function update(TimeEntry $entry, array $data): TimeEntry
    {
        return DB::transaction(function () use ($entry, $data): TimeEntry {
            $projectId = $data['project_id'] ?? $entry->project_id;
            $taskId = $data['task_id'] ?? $entry->task_id;
            $hours = $data['hours'] ?? (float) $entry->hours;

            $project = Project::with(['tasks', 'users'])->findOrFail($projectId);
            $task = Task::findOrFail($taskId);
            $user = $entry->user;

            $resolution = $this->rateResolver->resolveWithHours($project, $task, $user, $hours);

            $update = [
                'project_id' => $project->id,
                'task_id' => $task->id,
                'spent_on' => $data['spent_on'] ?? $entry->spent_on->toDateString(),
                'hours' => $hours,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $entry->notes,
                'is_billable' => $resolution->isBillable,
                'billable_rate_snapshot' => $resolution->rateSnapshot,
                'billable_amount' => $resolution->billableAmount,
            ];

            if (array_key_exists('asana_task_gid', $data)) {
                $update['asana_task_gid'] = $data['asana_task_gid'];
            }

            $entry->update($update);

            $entry->refresh();

            return $entry;
        });
    }

    public function delete(TimeEntry $entry): void
    {
        // One transaction, so a refused delete does not leave the timer stopped.
        DB::transaction(function () use ($entry): void {
            if ($entry->is_running) {
                $entry->update(['is_running' => false, 'timer_started_at' => null]);
            }
            $entry->delete();
        });
    }

    public function startTimer(TimeEntry $entry): void
    {
        // Transaction + row lock: prevents two concurrent requests racing to
        // start a second timer for the same user simultaneously.
        DB::transaction(function () use ($entry): void {
            // Re-fetch with lock so concurrent calls queue behind this one
            $locked = TimeEntry::lockForUpdate()->find($entry->id);
            if ($locked === null || $locked->is_running) {
                return; // already running or deleted
            }

            // Auto-stop any other running timer for this user
            TimeEntry::where('user_id', $entry->user_id)
                ->where('is_running', true)
                ->where('id', '!=', $entry->id)
                ->lockForUpdate()
                ->each(fn (TimeEntry $running) => $this->stopTimer($running));

            $locked->update([
                'is_running' => true,
                'timer_started_at' => Carbon::now(),
            ]);
        });

        $entry->refresh();
    }

    public function stopTimer(TimeEntry $entry): void
    {
        if (! $entry->is_running || $entry->timer_started_at === null) {
            return;
        }

        // A transaction, like the other writes, so a stop made under a month
        // lock override is atomic with its change log.
        DB::transaction(function () use ($entry): void {
            // Signed diff so clock skew (timer_started_at in the future) yields a negative
            // elapsed instead of being silently flipped positive.
            $elapsedSeconds = $entry->timer_started_at->diffInSeconds(Carbon::now(), false);
            $elapsed = max(0.0, $elapsedSeconds) / 3600;
            $newHours = round((float) $entry->hours + $elapsed, 2);
            $newHours = max(0.01, min(24.0, $newHours));

            /** @var Project $project */
            $project = $entry->project()->with(['tasks', 'users'])->firstOrFail();
            $task = $entry->task;
            $user = $entry->user;

            $resolution = $this->rateResolver->resolveWithHours($project, $task, $user, $newHours);

            $entry->update([
                'hours' => $newHours,
                'is_running' => false,
                'timer_started_at' => null,
                'is_billable' => $resolution->isBillable,
                'billable_rate_snapshot' => $resolution->rateSnapshot,
                'billable_amount' => $resolution->billableAmount,
            ]);
        });
    }

    public function currentHours(TimeEntry $entry, ?Carbon $now = null): float
    {
        $hours = (float) $entry->hours;

        if (! $entry->is_running || $entry->timer_started_at === null) {
            return $hours;
        }

        $elapsedSeconds = $entry->timer_started_at->diffInSeconds($now ?? Carbon::now(), false);
        $elapsedHours = max(0.0, $elapsedSeconds) / 3600;

        return min(24.0, $hours + $elapsedHours);
    }
}
