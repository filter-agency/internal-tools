<?php

namespace App\Domain\TimeTracking;

use App\Domain\Billing\RateResolution;
use App\Domain\Billing\RateResolver;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            $this->ensureAssignment($user, $project, $task);

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
        return app(MonthLockGuard::class)->withEntryWrites([$entry], function () use ($entry, $data): TimeEntry {
            $projectId = $data['project_id'] ?? $entry->project_id;
            $taskId = $data['task_id'] ?? $entry->task_id;
            $hours = $data['hours'] ?? (float) $entry->hours;

            $project = Project::with(['tasks', 'users'])->findOrFail($projectId);
            $task = Task::findOrFail($taskId);
            $user = $entry->user;

            $destinationChanged = $project->id !== $entry->project_id || $task->id !== $entry->task_id;
            if ($destinationChanged) {
                $this->ensureAssignment($user, $project, $task);
            }

            $resolution = $destinationChanged
                ? $this->rateResolver->resolveWithHours($project, $task, $user, $hours)
                : $this->savedBilling($entry, $hours);

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
        }, isset($data['project_id']) ? [(int) $data['project_id']] : []);
    }

    public function delete(TimeEntry $entry): void
    {
        // One transaction, so a refused delete does not leave the timer stopped.
        app(MonthLockGuard::class)->withEntryWrite($entry, function () use ($entry): void {
            if ($entry->is_running) {
                $entry->update(['is_running' => false, 'timer_started_at' => null]);
            }
            $entry->delete();
        });
    }

    public function startTimer(TimeEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            // Starts and stops share this lock, including starts on different entries.
            app(MonthLockGuard::class)->lockUsers([$entry->user_id], exclusive: true);
            $target = TimeEntry::find($entry->id);
            if ($target === null) {
                $entry->exists = false;

                return;
            }
            $running = TimeEntry::query()->where('user_id', $target->user_id)
                ->where('is_running', true)->where('id', '!=', $target->id)->get();

            // Acquire every affected client before locking any entry, including
            // the previous timer when it belongs to a different client.
            app(MonthLockGuard::class)->withEntryWrites([$target, ...$running->all()], function () use ($target, $running): void {
                if ($target->is_running) {
                    return;
                }
                foreach ($running as $other) {
                    $this->stopTimer($other);
                }
                $target->update(['is_running' => true, 'timer_started_at' => Carbon::now()]);
            });

            $entry->setRawAttributes($target->getAttributes(), sync: true)->unsetRelations();
        });
    }

    public function stopTimer(TimeEntry $entry): ?TimeEntry
    {
        try {
            return DB::transaction(function () use ($entry): ?TimeEntry {
                app(MonthLockGuard::class)->lockUsers([$entry->user_id], exclusive: true);
                $current = TimeEntry::find($entry->id);
                if ($current === null) {
                    $entry->exists = false;

                    return null;
                }
                if ($current->is_running && $current->timer_started_at !== null) {
                    app(MonthLockGuard::class)->withEntryWrite($current, function () use ($current): void {
                        $elapsedSeconds = $current->timer_started_at->diffInSeconds(Carbon::now(), false);
                        $newHours = round((float) $current->hours + max(0.0, $elapsedSeconds) / 3600, 2);
                        $newHours = max(0.01, min(24.0, $newHours));
                        $resolution = $this->savedBilling($current, $newHours);
                        $current->update([
                            'hours' => $newHours, 'is_running' => false, 'timer_started_at' => null,
                            'is_billable' => $resolution->isBillable,
                            'billable_rate_snapshot' => $resolution->rateSnapshot,
                            'billable_amount' => $resolution->billableAmount,
                        ]);
                    });
                }
                $entry->setRawAttributes($current->getAttributes(), sync: true)->unsetRelations();

                return $entry;
            });
        } catch (ModelNotFoundException $exception) {
            if ($exception->getModel() !== TimeEntry::class) {
                throw $exception;
            }
            $entry->exists = false;

            return null;
        }
    }

    private function ensureAssignment(User $user, Project $project, Task $task): void
    {
        $actor = auth()->user() ?? auth('api')->user();
        if (! $project->users->contains('id', $user->id) && ! ($actor instanceof User && $actor->is_active && $actor->isAdmin())) {
            throw new AuthorizationException('The time entry user is not assigned to this project.');
        }

        if (! $project->tasks->contains('id', $task->id)) {
            throw ValidationException::withMessages(['task_id' => 'The selected task is not assigned to this project.']);
        }
    }

    private function savedBilling(TimeEntry $entry, float $hours): RateResolution
    {
        $rate = $entry->billable_rate_snapshot === null ? null : (float) $entry->billable_rate_snapshot;

        return new RateResolution(
            isBillable: $entry->is_billable,
            rateSnapshot: $rate,
            billableAmount: $hours === (float) $entry->hours
                ? (float) $entry->billable_amount
                : ($entry->is_billable && $rate !== null ? round($hours * $rate, 2) : 0.0),
        );
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
