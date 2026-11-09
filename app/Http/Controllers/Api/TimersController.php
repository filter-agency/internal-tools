<?php

namespace App\Http\Controllers\Api;

use App\Domain\TimeTracking\TimeEntryService;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TimersController
{
    /**
     * Start a timer. Same shape as creating an entry but with hours=0; the
     * entry is created in 'is_running' mode and any other running timer for
     * this user is auto-stopped (TimeEntryService handles the race).
     */
    public function start(Request $request, TimeEntryService $service): JsonResponse
    {
        $data = $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'task_id' => 'required|integer|exists:tasks,id',
            'spent_on' => 'required|date_format:Y-m-d',
            'notes' => 'nullable|string|max:2000',
            'asana_task_gid' => 'nullable|string',
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var Project $project */
        $project = Project::with(['users', 'tasks'])->findOrFail($data['project_id']);
        if (! $project->users->contains('id', $user->id)) {
            return response()->json(['error' => 'project_not_assigned'], 403);
        }
        if (! $project->tasks->contains('id', $data['task_id'])) {
            return response()->json(['error' => 'task_not_on_project'], 422);
        }
        $linkedBoardGids = $project->asanaProjects()->pluck('gid')->all();
        if ($linkedBoardGids !== []) {
            $gid = $data['asana_task_gid'] ?? null;
            $hasGid = $gid !== null && $gid !== '';
            if ($project->asana_task_required && ! $hasGid) {
                return response()->json(['error' => 'asana_task_required'], 422);
            }
            if ($hasGid) {
                $valid = AsanaTask::where('gid', $gid)
                    ->whereIn('asana_project_gid', $linkedBoardGids)
                    ->exists();
                if (! $valid) {
                    return response()->json(['error' => 'asana_task_invalid'], 422);
                }
            }
        }

        $entry = $service->create($user, [
            'project_id' => $data['project_id'],
            'task_id' => $data['task_id'],
            'spent_on' => $data['spent_on'],
            'hours' => 0.01,
            'notes' => $data['notes'] ?? null,
            'asana_task_gid' => $data['asana_task_gid'] ?? null,
        ]);

        $service->startTimer($entry);
        if (! $entry->exists) {
            return response()->json(['error' => 'entry_deleted'], 404);
        }

        return response()->json($this->serialize($entry), 201);
    }

    public function stop(Request $request, TimeEntryService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $running = TimeEntry::where('user_id', $user->id)
            ->where('is_running', true)
            ->first();

        if ($running === null) {
            return response()->json(['error' => 'no_running_timer'], 404);
        }

        $stopped = $service->stopTimer($running);
        if ($stopped === null) {
            return response()->json(['error' => 'no_running_timer'], 404);
        }

        return response()->json($this->serialize($stopped));
    }

    public function running(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $running = TimeEntry::with(['project.client', 'task'])
            ->where('user_id', $user->id)
            ->where('is_running', true)
            ->first();

        return response()->json([
            'running' => $running === null ? null : $this->serialize($running),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'project_id' => $entry->project_id,
            'task_id' => $entry->task_id,
            'spent_on' => $entry->spent_on->toDateString(),
            'hours' => (float) $entry->hours,
            'is_running' => (bool) $entry->is_running,
            'timer_started_at' => $entry->timer_started_at?->toIso8601String(),
            'notes' => $entry->notes,
            'asana_task_gid' => $entry->asana_task_gid,
            'is_billable' => (bool) $entry->is_billable,
            'elapsed_seconds' => $entry->is_running && $entry->timer_started_at
                ? $entry->timer_started_at->diffInSeconds(Carbon::now()) + (int) ((float) $entry->hours * 3600)
                : (int) ((float) $entry->hours * 3600),
        ];
    }
}
