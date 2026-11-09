<?php

namespace App\Livewire\Integrations;

use App\Domain\TimeTracking\AsanaProjectAssociationService;
use App\Domain\TimeTracking\HoursFormatter;
use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Compact log-time form for the Asana browser-extension overlay. Rendered
 * chrome-free (layouts.embed) inside an iframe on app.asana.com, fixed to
 * one Asana task: the user picks the internal project/task (preselected
 * from their last choice for that board), enters hours and saves.
 */
#[Layout('layouts.embed')]
class AsanaLogTime extends Component
{
    public string $taskGid = '';

    public string $taskName = '';

    /** 'ok' renders the form; 'missing' and 'unmapped' render notices. */
    public string $status = 'ok';

    public ?int $selectedProjectId = null;

    public ?int $selectedTaskId = null;

    public string $entryDate = '';

    public string $hoursInput = '';

    public string $notes = '';

    public string $hoursError = '';

    public string $savedSummary = '';

    public bool $timerStarted = false;

    public function mount(string $taskGid): void
    {
        $this->taskGid = $taskGid;
        $this->entryDate = now()->toDateString();

        $asanaTask = AsanaTask::find($taskGid);
        if ($asanaTask === null) {
            $this->status = 'missing';

            return;
        }

        $this->taskName = $asanaTask->name;

        $linked = $this->linkedProjects();
        if ($linked->isEmpty()) {
            $this->status = 'unmapped';

            return;
        }

        $remembered = app(AsanaProjectAssociationService::class)
            ->lookup($this->user(), $asanaTask->asana_project_gid);
        $project = ($remembered !== null ? $linked->firstWhere('id', $remembered['project_id']) : null)
            ?? $linked->first();

        $this->selectedProjectId = $project->id;
        if ($remembered !== null
            && $remembered['project_id'] === $project->id
            && $project->tasks->contains('id', $remembered['task_id'])) {
            $this->selectedTaskId = $remembered['task_id'];
        }
    }

    public function updatedSelectedProjectId(): void
    {
        // The task list belongs to the project; a stale selection from the
        // previous project must not survive the switch.
        $project = $this->linkedProjects()->firstWhere('id', $this->selectedProjectId);
        if ($project === null || ! $project->tasks->contains('id', $this->selectedTaskId)) {
            $this->selectedTaskId = null;
        }
    }

    public function save(): void
    {
        $this->hoursError = '';

        $this->validate([
            'selectedProjectId' => 'required|integer',
            'selectedTaskId' => 'required|integer',
            'hoursInput' => 'required|string',
            'entryDate' => 'required|date',
        ]);

        try {
            $hours = HoursParser::parse($this->hoursInput);
        } catch (InvalidArgumentException $e) {
            $this->hoursError = $e->getMessage();

            return;
        }

        $entry = $this->createEntry($hours);

        $user = $this->user();
        $this->savedSummary = sprintf(
            '%s logged to %s — %s.',
            HoursFormatter::format((float) $entry->hours, $user->hoursDisplayFormat()),
            $entry->project->name,
            $entry->task->name,
        );
        $this->timerStarted = false;
        $this->hoursInput = '';
        $this->notes = '';

        $this->dispatch('asana-entry-saved');
    }

    public function startTimer(): void
    {
        $this->hoursError = '';

        $this->validate([
            'selectedProjectId' => 'required|integer',
            'selectedTaskId' => 'required|integer',
            'entryDate' => 'required|date',
        ]);

        $hours = 0.0;
        if ($this->hoursInput !== '') {
            try {
                $hours = HoursParser::parse($this->hoursInput);
            } catch (InvalidArgumentException $e) {
                $this->hoursError = $e->getMessage();

                return;
            }
        }

        $entry = $this->createEntry($hours);
        app(TimeEntryService::class)->startTimer($entry);

        $this->savedSummary = sprintf(
            'Timer running on %s — %s.',
            $entry->project->name,
            $entry->task->name,
        );
        $this->timerStarted = true;
        $this->hoursInput = '';
        $this->notes = '';

        $this->dispatch('asana-entry-saved');
    }

    public function stopRunningTimer(): void
    {
        $entry = $this->runningEntry();
        if ($entry === null) {
            return;
        }

        $entry = app(TimeEntryService::class)->stopTimer($entry);
        if ($entry === null) {
            $this->timerStarted = false;
            $this->savedSummary = 'This time entry was already deleted.';

            return;
        }

        $this->savedSummary = sprintf(
            'Timer stopped — %s logged to %s — %s.',
            HoursFormatter::format((float) $entry->hours, $this->user()->hoursDisplayFormat()),
            $entry->project->name,
            $entry->task->name,
        );
        $this->timerStarted = false;

        $this->dispatch('asana-entry-saved');
    }

    public function render(): View
    {
        $running = null;
        if ($this->status === 'ok' && ($entry = $this->runningEntry()) !== null) {
            $running = [
                'label' => $entry->project->name.' — '.$entry->task->name,
                'notes' => $entry->notes,
                // The Alpine ticker resumes from hours banked before this
                // run plus the wall-clock elapsed since the timer started.
                'base_seconds' => (int) round((float) $entry->hours * 3600),
                'started_at_ms' => $entry->timer_started_at?->getTimestampMs() ?? now()->getTimestampMs(),
            ];
        }

        return view('livewire.integrations.asana-log-time', [
            'projects' => $this->status === 'ok' ? $this->linkedProjects() : collect(),
            'running' => $running,
        ]);
    }

    private function runningEntry(): ?TimeEntry
    {
        return TimeEntry::with(['project', 'task'])
            ->where('user_id', $this->user()->id)
            ->where('is_running', true)
            ->where('asana_task_gid', $this->taskGid)
            ->first();
    }

    private function createEntry(float $hours): TimeEntry
    {
        $projectId = (int) $this->selectedProjectId;
        $taskId = (int) $this->selectedTaskId;
        $user = $this->user();

        $entry = app(TimeEntryService::class)->create($user, [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'spent_on' => $this->entryDate,
            'hours' => $hours,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'asana_task_gid' => $this->taskGid,
        ]);

        $boardGid = AsanaTask::find($this->taskGid)?->asana_project_gid;
        if ($boardGid !== null) {
            app(AsanaProjectAssociationService::class)
                ->remember($user, $boardGid, $projectId, $taskId);
        }

        return $entry;
    }

    /** @return Collection<int, Project> */
    private function linkedProjects(): Collection
    {
        $boardGid = AsanaTask::find($this->taskGid)?->asana_project_gid;
        if ($boardGid === null) {
            return collect();
        }

        return Project::with(['tasks' => fn ($q) => $q->orderBy('name')])
            ->where('is_archived', false)
            ->whereHas('users', fn ($q) => $q->where('users.id', $this->user()->id))
            ->whereHas('asanaProjects', fn ($q) => $q->where('gid', $boardGid))
            ->orderBy('name')
            ->get();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
