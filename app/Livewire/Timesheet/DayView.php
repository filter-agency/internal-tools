<?php

namespace App\Livewire\Timesheet;

use App\Domain\TimeTracking\AsanaProjectAssociationService;
use App\Domain\TimeTracking\CalendarEventAssociationService;
use App\Domain\TimeTracking\HoursFormatter;
use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\MonthLockGuard;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\AsanaTask;
use App\Models\MonthLock;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Asana\AsanaTaskRefreshDispatcher;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class DayView extends Component
{
    #[Url(as: 'date')]
    public string $selectedDate = '';

    // Modal state
    public bool $showModal = false;

    public ?int $editingEntryId = null;

    public ?int $selectedProjectId = null;

    public ?int $selectedTaskId = null;

    public string $selectedAsanaTaskGid = '';

    #[Locked]
    public ?int $lastSavedProjectId = null;

    #[Locked]
    public ?int $lastSavedTaskId = null;

    // Entry form fields
    public string $hoursInput = '';

    public string $notes = '';

    public string $entryDate = '';

    public string $hoursError = '';

    // Calendar panel state. Events themselves are prefetched per-week in
    // render() and passed to the view; only the panel toggle and the
    // 'no_token' error live as Livewire state.
    public bool $showCalendarPanel = false;

    public ?string $calendarError = null;

    // Title of the calendar event the user pulled in for the current modal session.
    // When set, save() will upsert a CalendarEventAssociation so the same event auto-fills next time.
    public ?string $lastCalendarPullTitle = null;

    // Admin impersonation: when set, the admin is editing this user's timesheet.
    #[Locked]
    public ?int $viewedUserId = null;

    // True when an admin is editing another user's timesheet. Some actions
    // (calendar pull, timer) are blocked because they need the target's
    // Google token; create/edit/delete are still allowed.
    #[Locked]
    public bool $isImpersonating = false;

    // True when a manager is viewing a direct report's timesheet. Strictly
    // read-only: every write action returns early and the UI hides edit
    // affordances. Locked so a tampered wire payload can't flip it to false.
    #[Locked]
    public bool $isReadOnly = false;

    // Where the "← back" link in the impersonation/read-only banner should
    // point. Set at mount() based on which route the user entered through:
    // /admin/timesheets/* → admin index; /team/* (or anywhere else) → my
    // timesheet. Locked so it can't be tampered with.
    #[Locked]
    public string $backUrl = '';

    #[Locked]
    public string $backLabel = '';

    private ?User $viewedUserCache = null;

    public function mount(?User $user = null): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->selectedDate)) {
            $this->selectedDate = Carbon::today()->toDateString();
        }
        $this->entryDate = $this->selectedDate;

        if ($user !== null && $user->exists && $user->id !== auth()->id()) {
            /** @var User $authUser */
            $authUser = auth()->user();
            $cameFromAdmin = (bool) (request()->route()?->getName() === 'admin.timesheets.user');

            if ($authUser->isAdmin() && $cameFromAdmin) {
                // Admin via /admin/timesheets/{user} — full impersonation.
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('admin.timesheets');
                $this->backLabel = 'Back to admin index';
            } elseif ($authUser->isAdmin() && $user->reports_to_user_id === $authUser->id) {
                // Admin who is also this user's manager, arriving via /team/{user}
                // — let them edit (admin can always edit), but route the back
                // link to their own timesheet because that's where they came
                // from.
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } elseif ($user->reports_to_user_id === $authUser->id) {
                // Manager viewing a direct report — read-only.
                $this->viewedUserId = $user->id;
                $this->isReadOnly = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } elseif ($authUser->isAdmin()) {
                // Admin viewing someone who isn't their report, but didn't come
                // through the admin route (e.g. crafted URL). Still allow edit
                // but back to their own timesheet.
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } else {
                abort(403);
            }
        }

        // Deep link from the Asana widget card (/timesheet?log_asana={gid}):
        // once the card occupies the app's slot on a task the in-Asana form is
        // unreachable, so repeat logging lands here with the modal prefilled.
        $logAsanaGid = (string) request()->query('log_asana', '');
        if ($logAsanaGid !== '' && $this->viewedUserId === null) {
            $this->openModalForAsanaTask($logAsanaGid);
        }
    }

    private function openModalForAsanaTask(string $asanaTaskGid): void
    {
        $asanaTask = AsanaTask::find($asanaTaskGid);
        if ($asanaTask === null) {
            return;
        }

        $user = $this->viewedUser();
        $linked = Project::with('tasks')
            ->where('is_archived', false)
            ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
            ->whereHas('asanaProjects', fn ($q) => $q->where('gid', $asanaTask->asana_project_gid))
            ->orderBy('name')
            ->get();

        if ($linked->isEmpty()) {
            return;
        }

        $remembered = app(AsanaProjectAssociationService::class)->lookup($user, $asanaTask->asana_project_gid);
        $project = ($remembered !== null ? $linked->firstWhere('id', $remembered['project_id']) : null)
            ?? $linked->first();

        $this->resetModal();
        $this->selectedProjectId = $project->id;
        if ($remembered !== null
            && $remembered['project_id'] === $project->id
            && $project->tasks->contains('id', $remembered['task_id'])) {
            $this->selectedTaskId = $remembered['task_id'];
        }
        $this->selectedAsanaTaskGid = $asanaTaskGid;
        $this->showModal = true;
    }

    protected function viewedUser(): User
    {
        if ($this->viewedUserCache !== null) {
            return $this->viewedUserCache;
        }

        if ($this->viewedUserId !== null) {
            $user = User::find($this->viewedUserId);
            if ($user) {
                return $this->viewedUserCache = $user;
            }
        }

        /** @var User $authUser */
        $authUser = auth()->user();

        return $this->viewedUserCache = $authUser;
    }

    public function selectDate(string $date): void
    {
        $this->selectedDate = $date;
    }

    public function previousWeek(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->subWeek()->toDateString();
    }

    public function nextWeek(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->addWeek()->toDateString();
    }

    public function openNewModal(): void
    {
        if ($this->isReadOnly) {
            return;
        }

        $this->resetModal();
        $this->restoreLastSavedProjectAndTask();
        $this->entryDate = $this->selectedDate;
        $this->showModal = true;

        // Auto-open the calendar panel by default when starting a new entry.
        // The events themselves are already prefetched & cached in render(),
        // so this is just a UI toggle — no extra API hit.
        if (! $this->isImpersonating && app(CalendarService::class)->hasToken($this->viewedUser())) {
            $this->showCalendarPanel = true;
            $this->calendarError = null;
        }
    }

    public function openEditModal(int $entryId): void
    {
        if ($this->isReadOnly) {
            return;
        }
        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        $this->resetModal();
        $this->editingEntryId = $entryId;
        $this->selectedProjectId = $entry->project_id;
        $this->selectedTaskId = $entry->task_id;
        $this->selectedAsanaTaskGid = $entry->asana_task_gid ?? '';
        $this->hoursInput = HoursFormatter::format((float) $entry->hours, $this->currentHoursFormat());
        $this->notes = $entry->notes ?? '';
        $this->entryDate = $entry->spent_on->toDateString();
        $this->lastCalendarPullTitle = $this->calendarTitleForEntry($entry);
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->showCalendarPanel = false;
        $this->resetModal();
    }

    /**
     * Load the compact task picker data only after the user has chosen a project.
     *
     * @return array<int, array{gid: string, name: string, search_text: string|null, board_name: string}>
     */
    public function loadAsanaTasksForProject(int $projectId): array
    {
        $project = Project::query()
            ->whereKey($projectId)
            ->where('is_archived', false)
            ->whereHas('users', fn ($query) => $query->whereKey($this->viewedUser()->id))
            ->with('asanaProjects')
            ->firstOrFail();

        $boardNames = $project->asanaProjects->pluck('name', 'gid');

        return AsanaTask::query()
            ->whereIn('asana_project_gid', $boardNames->keys())
            ->where('is_completed', false)
            ->orderBy('name')
            ->get(['gid', 'asana_project_gid', 'name', 'search_text'])
            ->map(fn (AsanaTask $task) => [
                'gid' => $task->gid,
                'name' => $task->name,
                'search_text' => $task->search_text,
                'board_name' => (string) $boardNames->get($task->asana_project_gid),
            ])
            ->all();
    }

    public function startTimerFromModal(): void
    {
        if ($this->isImpersonating || $this->isReadOnly) {
            return;
        }

        $this->hoursError = '';

        $this->validate([
            'selectedProjectId' => 'required|integer',
            'selectedTaskId' => 'required|integer',
            'entryDate' => 'required|date',
        ]);

        if (! $this->validateAsanaTaskRequirement()) {
            return;
        }

        $hours = 0.0;
        if ($this->hoursInput !== '' && $this->hoursInput !== '0:00') {
            try {
                $hours = HoursParser::parse($this->hoursInput);
            } catch (InvalidArgumentException $e) {
                $this->hoursError = $e->getMessage();

                return;
            }
        }

        $user = $this->viewedUser();
        $service = app(TimeEntryService::class);

        $entry = $service->create($user, [
            'project_id' => (int) $this->selectedProjectId,
            'task_id' => (int) $this->selectedTaskId,
            'spent_on' => $this->entryDate,
            'hours' => $hours,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'asana_task_gid' => $this->selectedAsanaTaskGid !== '' ? $this->selectedAsanaTaskGid : null,
        ]);

        $this->rememberLastSavedProjectAndTask((int) $this->selectedProjectId, (int) $this->selectedTaskId);
        $service->startTimer($entry);
        $this->closeModal();
        $this->dispatch('day-entry-timer-started');
    }

    public function save(): void
    {
        if ($this->isReadOnly) {
            return;
        }
        $this->hoursError = '';

        $this->validate([
            'selectedProjectId' => 'required|integer',
            'selectedTaskId' => 'required|integer',
            'hoursInput' => 'required|string',
            'entryDate' => 'required|date',
        ]);

        if (! $this->validateAsanaTaskRequirement()) {
            return;
        }

        try {
            $hours = HoursParser::parse($this->hoursInput);
        } catch (InvalidArgumentException $e) {
            $this->hoursError = $e->getMessage();

            return;
        }

        // Validation above guarantees these are non-null integers
        $projectId = (int) $this->selectedProjectId;
        $taskId = (int) $this->selectedTaskId;

        $user = $this->viewedUser();
        $service = app(TimeEntryService::class);

        $data = [
            'project_id' => $projectId,
            'task_id' => $taskId,
            'spent_on' => $this->entryDate,
            'hours' => $hours,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'asana_task_gid' => $this->selectedAsanaTaskGid !== '' ? $this->selectedAsanaTaskGid : null,
        ];

        $isEdit = $this->editingEntryId !== null;
        $saved = false;
        if ($isEdit && $this->editingEntryId !== null) {
            $entry = $this->guardEntry($this->editingEntryId);
            if ($entry) {
                $service->update($entry, $data);
                $saved = true;
            }
        } else {
            $service->create($user, $data);
            $saved = true;
        }

        if ($saved) {
            $this->rememberLastSavedProjectAndTask($projectId, $taskId);

            // Remember the project/task per Asana board so the widget deep
            // link (?log_asana=...) can preselect them next time.
            if ($this->selectedAsanaTaskGid !== '') {
                $boardGid = AsanaTask::find($this->selectedAsanaTaskGid)?->asana_project_gid;
                if ($boardGid !== null) {
                    app(AsanaProjectAssociationService::class)
                        ->remember($user, $boardGid, $projectId, $taskId);
                }

                // Lets the extension overlay on app.asana.com know it can
                // close (forwarded as a postMessage from the blade).
                $this->dispatch('asana-entry-saved');
            }
        } else {
            return;
        }

        if ($this->lastCalendarPullTitle !== null) {
            app(CalendarEventAssociationService::class)
                ->remember($user, $this->lastCalendarPullTitle, $projectId, $taskId);
        }

        $this->resetEntryFormAfterSave();
    }

    private function validateAsanaTaskRequirement(): bool
    {
        if ($this->selectedProjectId === null) {
            return true;
        }

        $project = Project::find($this->selectedProjectId);
        if ($project === null || ! $project->asanaLinked()) {
            return true;
        }

        $taskGidProvided = $this->selectedAsanaTaskGid !== '';
        $required = (bool) $project->asana_task_required;
        $fromCalendar = $this->lastCalendarPullTitle !== null;

        // Accept with no Asana task when either the project allows it, or the
        // entry was pulled from a calendar invite (meetings like standups get
        // logged against the project code directly).
        if ((! $required || $fromCalendar) && ! $taskGidProvided) {
            return true;
        }

        if (! $this->asanaIntegrationAvailable()) {
            $this->addError(
                'selectedAsanaTaskGid',
                'Asana integration is not active. An admin needs to connect Asana before time can be logged on linked projects.'
            );

            return false;
        }

        if ($required && ! $taskGidProvided) {
            $this->addError('selectedAsanaTaskGid', 'Pick the Asana task this time relates to.');

            return false;
        }

        $linkedBoardGids = $project->asanaProjects()->pluck('gid')->all();
        $exists = AsanaTask::where('gid', $this->selectedAsanaTaskGid)
            ->whereIn('asana_project_gid', $linkedBoardGids)
            ->exists();

        if (! $exists) {
            $this->addError('selectedAsanaTaskGid', 'That Asana task is no longer in this project. Refresh tasks and try again.');

            return false;
        }

        return true;
    }

    private function calendarTitleForEntry(TimeEntry $entry): ?string
    {
        $title = trim((string) $entry->notes);
        if ($title === '') {
            return null;
        }

        $association = app(CalendarEventAssociationService::class)->lookup($this->viewedUser(), $title);
        if ($association === null) {
            return null;
        }

        if ($association['project_id'] !== $entry->project_id || $association['task_id'] !== $entry->task_id) {
            return null;
        }

        return $title;
    }

    private function asanaIntegrationAvailable(): bool
    {
        return User::query()
            ->whereNotNull('asana_access_token')
            ->whereNotNull('asana_user_gid')
            ->where('is_active', true)
            ->exists();
    }

    public function refreshSelectedProjectAsanaTasks(AsanaTaskRefreshDispatcher $dispatcher): void
    {
        if ($this->isReadOnly || $this->selectedProjectId === null) {
            return;
        }

        $project = Project::query()
            ->whereKey($this->selectedProjectId)
            ->whereHas('users', fn ($query) => $query->where('users.id', $this->viewedUser()->id))
            ->first();

        if ($project === null || ! $project->asanaLinked()) {
            return;
        }

        if ($dispatcher->dispatchForProject($project) === 0) {
            $this->addError('selectedAsanaTaskGid', 'No connected Asana user is available to refresh tasks for this project.');

            return;
        }

        session()->flash('asana_task_refresh_message', 'Refreshing Asana tasks in the background.');
    }

    public function copyRowsFromMostRecent(): void
    {
        if ($this->isReadOnly) {
            return;
        }

        $user = $this->viewedUser();

        $alreadyHasEntries = TimeEntry::where('user_id', $user->id)
            ->where('spent_on', $this->selectedDate)
            ->exists();
        if ($alreadyHasEntries) {
            return;
        }

        $mostRecentPrior = TimeEntry::where('user_id', $user->id)
            ->where('spent_on', '<', $this->selectedDate)
            ->orderByDesc('spent_on')
            ->value('spent_on');

        if (! $mostRecentPrior) {
            return;
        }

        $sourceEntries = TimeEntry::with(['project.tasks', 'project.users', 'project.asanaProjects'])
            ->where('user_id', $user->id)
            ->where('spent_on', CarbonImmutable::parse($mostRecentPrior)->toDateString())
            ->orderBy('created_at')
            ->get();

        $service = app(TimeEntryService::class);
        $copied = 0;
        $needsAsanaTask = 0;

        foreach ($sourceEntries as $source) {
            $project = $source->project;
            if ($project->is_archived) {
                continue;
            }
            if (! $project->users->contains('id', $user->id)) {
                continue;
            }
            if (! $project->tasks->contains('id', $source->task_id)) {
                continue;
            }

            $asanaGid = $source->asana_task_gid;
            if ($project->asanaLinked()) {
                $linkedBoardGids = $project->asanaProjects->pluck('gid')->all();

                if ($asanaGid !== null) {
                    $stillValid = AsanaTask::where('gid', $asanaGid)
                        ->whereIn('asana_project_gid', $linkedBoardGids)
                        ->exists();
                    if (! $stillValid) {
                        $asanaGid = null;
                    }
                }
                // If the project requires an Asana task but the source row has
                // none (or its gid is stale), still carry the row forward with
                // a null gid — the user will be forced to pick a task at edit
                // time by the existing save-side validation.
                if ($asanaGid === null && (bool) $project->asana_task_required) {
                    $needsAsanaTask++;
                }
            } else {
                $asanaGid = null;
            }

            $service->create($user, [
                'project_id' => $source->project_id,
                'task_id' => $source->task_id,
                'spent_on' => $this->selectedDate,
                'hours' => 0,
                'notes' => $source->notes,
                'asana_task_gid' => $asanaGid,
            ]);
            $copied++;
        }

        $sourceDateLabel = Carbon::parse($mostRecentPrior)->format('l, j F');
        if ($copied > 0) {
            $message = 'Copied '.$copied.' row'.($copied === 1 ? '' : 's').' from '.$sourceDateLabel.'.';
            if ($needsAsanaTask > 0) {
                $message .= ' '.$needsAsanaTask.' row'.($needsAsanaTask === 1 ? '' : 's')
                    .' '.($needsAsanaTask === 1 ? 'is' : 'are').' on a project that requires an Asana task — edit '
                    .($needsAsanaTask === 1 ? 'it' : 'them').' to pick one before logging hours.';
            }
            session()->flash('copy_rows_message', $message);
        } else {
            session()->flash('copy_rows_message', 'No rows could be copied from '.$sourceDateLabel.'.');
        }
    }

    public function deleteEntry(int $entryId): void
    {
        if ($this->isReadOnly) {
            return;
        }
        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->delete($entry);
    }

    public function startTimer(int $entryId): void
    {
        if ($this->isImpersonating || $this->isReadOnly) {
            return;
        }

        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->startTimer($entry);
    }

    public function stopTimer(int $entryId): void
    {
        if ($this->isImpersonating || $this->isReadOnly) {
            return;
        }

        $entry = $this->guardEntry($entryId);
        if (! $entry) {
            return;
        }

        app(TimeEntryService::class)->stopTimer($entry);
    }

    public function openCalendarPanel(): void
    {
        if ($this->isImpersonating || $this->isReadOnly) {
            return;
        }

        $this->showCalendarPanel = true;
        $this->calendarError = null;

        // Events themselves are prefetched in render(); this just toggles the
        // panel open. The error state for missing tokens is also computed in
        // render() so it stays correct after page refreshes.
    }

    public function closeCalendarPanel(): void
    {
        $this->showCalendarPanel = false;
    }

    public function pullFromCalendarEvent(string $title, float $hours): void
    {
        if ($this->isImpersonating || $this->isReadOnly) {
            return;
        }

        $this->notes = $title;
        $this->hoursInput = HoursFormatter::format($hours, $this->currentHoursFormat());
        $this->lastCalendarPullTitle = $title;

        // Auto-fill project/task from a previously remembered association for this event title.
        $assoc = app(CalendarEventAssociationService::class)->lookup($this->viewedUser(), $title);
        if ($assoc !== null) {
            $this->selectedProjectId = $assoc['project_id'];
            $this->selectedTaskId = $assoc['task_id'];
        }
    }

    #[On('timerPoll')]
    public function refreshForTimer(): void
    {
        // triggered by 60s poll — Livewire re-renders automatically
    }

    public function render(): View
    {
        $user = $this->viewedUser();
        $timeEntryService = app(TimeEntryService::class);
        $currentHours = fn (TimeEntry $entry): float => $timeEntryService->currentHours($entry);

        $selectedDay = CarbonImmutable::parse($this->selectedDate);
        $weekStart = $selectedDay->startOfWeek(); // Monday

        // Build week strip: Mon–Sun with daily totals
        $weekDays = collect(range(0, 6))->map(fn (int $offset) => $weekStart->addDays($offset));

        $weekEntries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->select(['spent_on', 'hours', 'is_running', 'timer_started_at'])
            ->get();

        $dayTotals = $weekEntries->groupBy(fn (TimeEntry $e) => $e->spent_on->toDateString())
            ->map(fn (Collection $group) => $group->sum($currentHours));

        $weekTotal = $weekEntries->sum($currentHours);

        // spent_on is a DATE column, so direct equality preserves the composite
        // user/date index on both MySQL and SQLite.
        $dayEntries = TimeEntry::with(['project.client', 'task', 'asanaTask'])
            ->where('user_id', $user->id)
            ->where('spent_on', $this->selectedDate)
            ->orderBy('created_at')
            ->get();

        $entryDisplayHours = $dayEntries
            ->mapWithKeys(fn (TimeEntry $entry) => [$entry->id => $currentHours($entry)])
            ->all();

        $dayTotal = array_sum($entryDisplayHours);

        // Locked client-months this week that the signed-in viewer cannot
        // change. A display hint only: the month lock observer still enforces.
        /** @var User|null $viewer */
        $viewer = auth()->user();
        $lockedClientMonths = array_filter(
            app(MonthLockGuard::class)->lockedClientMonths($weekStart, $weekStart->addDays(6)),
            fn (MonthLock $lock): bool => ! $lock->isEditableBy($viewer),
        );

        /** @var array<int, array{id: int, code: string, name: string, display_name: string, client_name: string, asana_project_gids: array<int, string>, asana_task_match_terms: array<int, string>, asana_task_required: bool, tasks: array<int, array{id: int, name: string, colour: string, is_billable: bool}>}> $projectsForPicker */
        $projectsForPicker = Cache::remember(
            "projects_picker_{$user->id}",
            now()->addMinutes(10),
            fn () => Project::with([
                'client',
                'tasks' => fn ($query) => $query->where('tasks.is_archived', false),
                'asanaProjects',
            ])
                ->where('is_archived', false)
                ->whereHas('users', fn ($q) => $q->where('users.id', $user->id))
                ->orderBy('name')
                ->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'display_name' => $p->timesheetDisplayName(),
                    'client_name' => $p->client->name,
                    'asana_project_gids' => $p->asanaProjects->pluck('gid')->values()->all(),
                    'asana_task_match_terms' => $p->asanaTaskMatchTerms(),
                    'asana_task_required' => (bool) $p->asana_task_required,
                    'tasks' => $p->tasks->map(function (Task $t) use ($p) {
                        /** @var Pivot $pivot */
                        $pivot = $t->getRelation('pivot');

                        return [
                            'id' => $t->id,
                            'name' => $t->name,
                            'colour' => $t->colour,
                            'is_billable' => (bool) $p->is_billable && (bool) $pivot->getAttribute('is_billable'),
                        ];
                    })->values()->all(),
                ])
                ->values()
                ->all()
        );

        // Track which calendar event titles are already logged today
        $usedEventTitles = $dayEntries->pluck('notes')->filter()->map(fn ($n) => strtolower($n))->all();

        // Prefetch the whole week's calendar events into a 5-min cache. Cache is
        // keyed on (user, weekStart) so sibling days within the same week reuse
        // it without re-hitting Google. Entire-week fetch is one API call per
        // accessible calendar (10x cheaper than per-day).
        $calendarEvents = [];
        $calendarHasToken = false;
        if (! $this->isImpersonating) {
            $calService = app(CalendarService::class);
            $calendarHasToken = $calService->hasToken($user);
            if ($calendarHasToken) {
                $weekEvents = Cache::remember(
                    "calendar_events_{$user->id}_{$weekStart->toDateString()}",
                    now()->addMinutes(5),
                    fn () => $calService->getEventsForDateRange(
                        $user,
                        Carbon::parse($weekStart),
                        Carbon::parse($weekStart->addDays(6)),
                    ),
                );
                $calendarEvents = $weekEvents[$this->selectedDate] ?? [];
            }
        }
        // Keep the Livewire-state error in sync with what render() decides, so
        // it stays correct across re-renders (and after the modal closes/reopens).
        $this->calendarError = ($calendarHasToken || $this->isImpersonating) ? null : 'no_token';

        // Direct reports of the *currently authenticated* user (not the viewed
        // user, which differs during impersonation/team-view). Powers the
        // 'Team Timesheets' dropdown in the header. We keep it empty when
        // viewing someone else's sheet to avoid stacking dropdowns on a sheet
        // that's already secondary.
        $teamMembers = collect();
        $authUser = auth()->user();
        if ($authUser !== null && $this->viewedUserId === null) {
            $teamMembers = $authUser->directReports()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $canCopyFromPrior = ! $this->isReadOnly
            && $dayEntries->isEmpty()
            && TimeEntry::where('user_id', $user->id)
                ->where('spent_on', '<', $this->selectedDate)
                ->exists();

        return view('livewire.timesheet.day-view', [
            'weekDays' => $weekDays,
            'dayTotals' => $dayTotals,
            'weekTotal' => $weekTotal,
            'dayEntries' => $dayEntries,
            'entryDisplayHours' => $entryDisplayHours,
            'lockedClientMonths' => $lockedClientMonths,
            'dayTotal' => $dayTotal,
            'canCopyFromPrior' => $canCopyFromPrior,
            'projectsForPicker' => $projectsForPicker,
            'asanaAvailable' => $this->asanaIntegrationAvailable(),
            'usedEventTitles' => $usedEventTitles,
            'calendarEvents' => $calendarEvents,
            'teamMembers' => $teamMembers,
            'emptySong' => null,
            'viewedUser' => $user,
            'hoursFormat' => $this->currentHoursFormat(),
        ]);
    }

    private function resetModal(): void
    {
        $this->editingEntryId = null;
        $this->selectedProjectId = null;
        $this->selectedTaskId = null;
        $this->selectedAsanaTaskGid = '';
        $this->hoursInput = '';
        $this->notes = '';
        $this->hoursError = '';
        $this->entryDate = $this->selectedDate;
        $this->lastCalendarPullTitle = null;
        $this->resetErrorBag();
    }

    private function resetEntryFormAfterSave(): void
    {
        $entryDate = $this->entryDate;
        $this->resetModal();
        $this->entryDate = $entryDate;
    }

    private function rememberLastSavedProjectAndTask(int $projectId, int $taskId): void
    {
        $this->lastSavedProjectId = $projectId;
        $this->lastSavedTaskId = $taskId;
    }

    private function restoreLastSavedProjectAndTask(): void
    {
        if ($this->lastSavedProjectId === null || $this->lastSavedTaskId === null) {
            return;
        }

        $this->selectedProjectId = $this->lastSavedProjectId;
        $this->selectedTaskId = $this->lastSavedTaskId;
    }

    private function currentHoursFormat(): string
    {
        /** @var User|null $authUser */
        $authUser = auth()->user();

        return $authUser?->hoursDisplayFormat() ?? HoursFormatter::FORMAT_HHMM;
    }

    private function guardEntry(int $entryId): ?TimeEntry
    {
        $user = $this->viewedUser();
        $entry = TimeEntry::find($entryId);

        if (! $entry || $entry->user_id !== $user->id) {
            return null;
        }

        return $entry;
    }
}
