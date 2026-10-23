<?php

namespace App\Livewire\Timesheet;

use App\Domain\TimeTracking\HoursFormatter;
use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\MonthLockGuard;
use App\Domain\TimeTracking\TimeEntryService;
use App\Models\AsanaTask;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\TimesheetExtraRow;
use App\Models\User;
use App\Services\Asana\AsanaTaskRefreshDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class WeekView extends Component
{
    #[Url(as: 'date')]
    public string $selectedDate = '';

    #[Locked]
    public ?int $viewedUserId = null;

    #[Locked]
    public bool $isImpersonating = false;

    #[Locked]
    public bool $isReadOnly = false;

    #[Locked]
    public string $backUrl = '';

    #[Locked]
    public string $backLabel = '';

    /**
     * Cell values keyed by "{projectId}:{taskId}" → array of 7 strings (Mon..Sun).
     * Strings so we can hold the user's typed input ("0:30", "1.5", "30m") and only
     * parse on save. Empty string == no entry.
     *
     * @var array<string, array<int, string>>
     */
    public array $cellValues = [];

    /**
     * Rows the admin has added via the "+ Add row" modal that don't have any
     * entries yet. Stored as ["{projectId}:{taskId}", ...]. Persists across
     * Livewire requests within the same week.
     *
     * @var array<int, string>
     */
    public array $extraRows = [];

    // Add-row modal state
    public bool $showAddRowModal = false;

    public ?int $newRowProjectId = null;

    public ?int $newRowTaskId = null;

    public string $newRowAsanaTaskGid = '';

    private ?User $viewedUserCache = null;

    public function mount(?User $user = null): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->selectedDate)) {
            $this->selectedDate = Carbon::today()->toDateString();
        }

        if ($user !== null && $user->exists && $user->id !== auth()->id()) {
            /** @var User $authUser */
            $authUser = auth()->user();
            $cameFromAdmin = (bool) (request()->route()?->getName() === 'admin.timesheets.user.week');

            if ($authUser->isAdmin() && $cameFromAdmin) {
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('admin.timesheets');
                $this->backLabel = 'Back to admin index';
            } elseif ($authUser->isAdmin() && $user->reports_to_user_id === $authUser->id) {
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } elseif ($user->reports_to_user_id === $authUser->id) {
                $this->viewedUserId = $user->id;
                $this->isReadOnly = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } elseif ($authUser->isAdmin()) {
                $this->viewedUserId = $user->id;
                $this->isImpersonating = true;
                $this->backUrl = route('timesheet');
                $this->backLabel = 'Back to my timesheet';
            } else {
                abort(403);
            }
        }
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

    public function previousWeek(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->subWeek()->toDateString();
        $this->extraRows = [];
        $this->cellValues = [];
    }

    public function nextWeek(): void
    {
        $this->selectedDate = Carbon::parse($this->selectedDate)->addWeek()->toDateString();
        $this->extraRows = [];
        $this->cellValues = [];
    }

    public function goToToday(): void
    {
        $this->selectedDate = Carbon::today()->toDateString();
        $this->extraRows = [];
        $this->cellValues = [];
    }

    // --- Add-row modal ---

    public function openAddRowModal(): void
    {
        if ($this->isReadOnly) {
            return;
        }
        $this->resetErrorBag();
        $this->newRowProjectId = null;
        $this->newRowTaskId = null;
        $this->newRowAsanaTaskGid = '';
        $this->showAddRowModal = true;
    }

    public function closeAddRowModal(): void
    {
        $this->showAddRowModal = false;
        $this->newRowProjectId = null;
        $this->newRowTaskId = null;
        $this->newRowAsanaTaskGid = '';
        $this->resetErrorBag();
    }

    public function addRow(): void
    {
        if ($this->isReadOnly) {
            return;
        }
        if ($this->newRowProjectId === null || $this->newRowTaskId === null) {
            return;
        }

        // Asana validation — mirrors DayView::validateAsanaTaskRequirement().
        $project = Project::find($this->newRowProjectId);
        if ($project && $project->asanaLinked()) {
            $taskGidProvided = $this->newRowAsanaTaskGid !== '';
            $required = (bool) $project->asana_task_required;

            if ($required || $taskGidProvided) {
                if (! $this->asanaIntegrationAvailable()) {
                    $this->addError('newRowAsanaTaskGid',
                        'Asana integration is not active. An admin needs to connect Asana before time can be logged on linked projects.'
                    );

                    return;
                }
                if ($required && ! $taskGidProvided) {
                    $this->addError('newRowAsanaTaskGid', 'Pick the Asana task this row relates to.');

                    return;
                }
                if ($taskGidProvided) {
                    $linkedBoardGids = $project->asanaProjects()->pluck('gid')->all();
                    $exists = AsanaTask::where('gid', $this->newRowAsanaTaskGid)
                        ->whereIn('asana_project_gid', $linkedBoardGids)
                        ->exists();
                    if (! $exists) {
                        $this->addError('newRowAsanaTaskGid', 'That Asana task is no longer in this project. Refresh tasks and try again.');

                        return;
                    }
                }
            }
        }

        $key = $this->buildRowKey($this->newRowProjectId, $this->newRowTaskId, $this->newRowAsanaTaskGid !== '' ? $this->newRowAsanaTaskGid : null);

        // Don't duplicate a row that already exists (either from saved entries
        // or already added in this session).
        if (isset($this->cellValues[$key])) {
            $this->closeAddRowModal();

            return;
        }

        $this->extraRows[] = $key;
        $this->cellValues[$key] = array_fill(0, 7, '');
        $this->persistExtraRowsForWeek(CarbonImmutable::parse($this->selectedDate)->startOfWeek());
        $this->closeAddRowModal();
    }

    public function copyRowsFromMostRecentWeek(): void
    {
        if ($this->isReadOnly || $this->cellValues !== []) {
            return;
        }

        $user = $this->viewedUser();
        $weekStart = CarbonImmutable::parse($this->selectedDate)->startOfWeek();
        $weekEnd = $weekStart->addDays(6);

        $alreadyHasEntries = TimeEntry::where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->exists();
        if ($alreadyHasEntries) {
            return;
        }

        $mostRecentPrior = TimeEntry::where('user_id', $user->id)
            ->where('spent_on', '<', $weekStart->toDateString())
            ->orderByDesc('spent_on')
            ->value('spent_on');

        if (! $mostRecentPrior) {
            return;
        }

        $sourceWeekStart = CarbonImmutable::parse($mostRecentPrior)->startOfWeek();
        $sourceWeekEnd = $sourceWeekStart->addDays(6);
        $sourceEntries = TimeEntry::with(['project.tasks', 'project.users', 'project.asanaProjects'])
            ->where('user_id', $user->id)
            ->whereBetween('spent_on', [$sourceWeekStart->toDateString(), $sourceWeekEnd->toDateString()])
            ->orderBy('created_at')
            ->get();

        $copied = 0;
        $skippedMissingAsanaTask = 0;

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

                if ($asanaGid === null && (bool) $project->asana_task_required) {
                    $skippedMissingAsanaTask++;

                    continue;
                }
            } else {
                $asanaGid = null;
            }

            $key = $this->buildRowKey($source->project_id, $source->task_id, $asanaGid);
            if (isset($this->cellValues[$key])) {
                continue;
            }

            $this->extraRows[] = $key;
            $this->cellValues[$key] = array_fill(0, 7, '');
            $copied++;
        }

        if ($copied > 0) {
            $this->persistExtraRowsForWeek($weekStart);
        }

        $sourceWeekLabel = $sourceWeekStart->format('j M Y');
        if ($copied > 0) {
            $message = 'Copied '.$copied.' row'.($copied === 1 ? '' : 's').' from week of '.$sourceWeekLabel.'.';
            if ($skippedMissingAsanaTask > 0) {
                $message .= ' '.$skippedMissingAsanaTask.' row'.($skippedMissingAsanaTask === 1 ? '' : 's')
                    .' '.($skippedMissingAsanaTask === 1 ? 'needs' : 'need').' an Asana task and can be added manually.';
            }
            session()->flash('copy_rows_message', $message);
        } else {
            session()->flash('copy_rows_message', 'No rows could be copied from week of '.$sourceWeekLabel.'.');
        }
    }

    private function asanaIntegrationAvailable(): bool
    {
        return User::query()
            ->whereNotNull('asana_access_token')
            ->whereNotNull('asana_user_gid')
            ->where('is_active', true)
            ->exists();
    }

    public function refreshNewRowAsanaTasks(AsanaTaskRefreshDispatcher $dispatcher): void
    {
        if ($this->isReadOnly || $this->newRowProjectId === null) {
            return;
        }

        $project = Project::query()
            ->whereKey($this->newRowProjectId)
            ->whereHas('users', fn ($query) => $query->where('users.id', $this->viewedUser()->id))
            ->first();

        if ($project === null || ! $project->asanaLinked()) {
            return;
        }

        if ($dispatcher->dispatchForProject($project) === 0) {
            $this->addError('newRowAsanaTaskGid', 'No connected Asana user is available to refresh tasks for this project.');

            return;
        }

        session()->flash('asana_task_refresh_message', 'Refreshing Asana tasks in the background.');
    }

    public function removeRow(string $rowKey): void
    {
        if ($this->isReadOnly) {
            return;
        }

        // If the row had any saved entries this week, delete them.
        $weekStart = CarbonImmutable::parse($this->selectedDate)->startOfWeek();
        [$projectId, $taskId, $asanaGid] = $this->parseRowKey($rowKey);
        if ($projectId !== null && $taskId !== null) {
            $query = TimeEntry::where('user_id', $this->viewedUser()->id)
                ->where('project_id', $projectId)
                ->where('task_id', $taskId)
                ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()]);
            if ($asanaGid === null) {
                $query->whereNull('asana_task_gid');
            } else {
                $query->where('asana_task_gid', $asanaGid);
            }
            // All or nothing: a month lock refusal keeps the whole row.
            DB::transaction(fn () => $query->get()->each(fn (TimeEntry $entry) => app(TimeEntryService::class)->delete($entry)));
        }

        unset($this->cellValues[$rowKey]);
        $this->extraRows = array_values(array_filter($this->extraRows, fn ($k) => $k !== $rowKey));
        $this->persistExtraRowsForWeek($weekStart);
    }

    // --- Save ---

    /**
     * Returns true once the week is saved. A month lock refusal rolls back
     * every cell and surfaces as a "month_lock" error instead.
     */
    public function save(): bool
    {
        if ($this->isReadOnly) {
            return false;
        }

        $user = $this->viewedUser();
        $service = app(TimeEntryService::class);
        $weekStart = CarbonImmutable::parse($this->selectedDate)->startOfWeek();

        DB::transaction(fn () => $this->saveWeek($user, $service, $weekStart));

        session()->flash('week_saved', true);

        return true;
    }

    private function saveWeek(User $user, TimeEntryService $service, CarbonImmutable $weekStart): void
    {
        $hoursFormat = $this->currentHoursFormat();

        // Pull every entry in the week so we can update/delete in-place.
        $existing = TimeEntry::where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->get()
            ->groupBy(fn (TimeEntry $e) => $this->buildRowKey($e->project_id, $e->task_id, $e->asana_task_gid).'|'.$e->spent_on->toDateString());

        foreach ($this->cellValues as $rowKey => $perDay) {
            [$projectId, $taskId, $asanaGid] = $this->parseRowKey($rowKey);
            if ($projectId === null || $taskId === null) {
                continue;
            }

            for ($i = 0; $i < 7; $i++) {
                $date = $weekStart->addDays($i)->toDateString();
                $raw = trim((string) ($perDay[$i] ?? ''));
                $cellKey = $rowKey.'|'.$date;
                $existingForCell = $existing->get($cellKey, collect());

                if ($raw === '' || $raw === '0' || $raw === '0:00') {
                    // Empty cell: delete any existing entries for this slot.
                    $existingForCell->each(fn (TimeEntry $entry) => $service->delete($entry));

                    continue;
                }

                try {
                    $hours = HoursParser::parse($raw);
                } catch (\InvalidArgumentException) {
                    continue; // skip invalid input silently
                }

                if ($existingForCell->isNotEmpty()) {
                    $storedTotal = round((float) $existingForCell->sum(fn (TimeEntry $entry) => (float) $entry->hours), 2);
                    // A cell still showing what render() filled it with is
                    // untouched, even when h:mm does not parse back to the
                    // stored value (1.09h shows as 1:05, which parses as 1.08h).
                    if ($raw === HoursFormatter::format($storedTotal, $hoursFormat) || abs($storedTotal - $hours) < 0.005) {
                        continue;
                    }

                    /** @var TimeEntry $firstEntry */
                    $firstEntry = $existingForCell->first();
                    $service->update($firstEntry, ['hours' => $hours]);
                    $existingForCell->skip(1)->each(fn (TimeEntry $entry) => $service->delete($entry));
                } else {
                    $service->create($user, [
                        'project_id' => $projectId,
                        'task_id' => $taskId,
                        'spent_on' => $date,
                        'hours' => $hours,
                        'notes' => null,
                        'asana_task_gid' => $asanaGid,
                    ]);
                }
            }
        }

        $persistedRowKeys = TimeEntry::where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->get(['project_id', 'task_id', 'asana_task_gid'])
            ->map(fn (TimeEntry $entry) => $this->buildRowKey($entry->project_id, $entry->task_id, $entry->asana_task_gid))
            ->unique();

        $this->extraRows = collect($this->extraRows)
            ->reject(fn (string $rowKey) => $persistedRowKeys->contains($rowKey))
            ->values()
            ->all();
        $this->persistExtraRowsForWeek($weekStart);
    }

    /**
     * Row keys: "{projectId}:{taskId}:{asanaGid|''}". Asana segment may be empty.
     *
     * @return array{0: ?int, 1: ?int, 2: ?string}
     */
    private function parseRowKey(string $rowKey): array
    {
        if (preg_match('/^p(?P<project>\d+)_t(?P<task>\d+)_a(?P<asana>[A-Za-z0-9]+|none)$/', $rowKey, $matches) === 1) {
            return [
                (int) $matches['project'],
                (int) $matches['task'],
                $matches['asana'] === 'none' ? null : $matches['asana'],
            ];
        }

        $parts = explode(':', $rowKey, 3);
        if (count($parts) < 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return [null, null, null];
        }
        $asana = $parts[2] ?? '';

        return [(int) $parts[0], (int) $parts[1], $asana === '' ? null : $asana];
    }

    private function buildRowKey(int $projectId, int $taskId, ?string $asanaGid): string
    {
        return 'p'.$projectId.'_t'.$taskId.'_a'.($asanaGid ?? 'none');
    }

    private function loadPersistedExtraRowsForWeek(CarbonImmutable $weekStart): void
    {
        if ($this->extraRows !== []) {
            $this->extraRows = $this->normalizeExtraRowKeys($this->extraRows);

            return;
        }

        $storedRows = TimesheetExtraRow::where('user_id', $this->viewedUser()->id)
            ->whereDate('week_start', $weekStart->toDateString())
            ->pluck('row_key')
            ->all();

        $this->extraRows = $this->normalizeExtraRowKeys($storedRows);
    }

    private function persistExtraRowsForWeek(CarbonImmutable $weekStart): void
    {
        $this->extraRows = $this->normalizeExtraRowKeys($this->extraRows);

        $userId = $this->viewedUser()->id;
        $weekStartDate = $weekStart->toDateString();

        $scope = TimesheetExtraRow::where('user_id', $userId)
            ->whereDate('week_start', $weekStartDate);

        // Drop any stored rows the user no longer wants (removed, or now backed
        // by real time entries), then persist the current set.
        if ($this->extraRows === []) {
            (clone $scope)->delete();

            return;
        }

        (clone $scope)->whereNotIn('row_key', $this->extraRows)->delete();

        foreach ($this->extraRows as $rowKey) {
            TimesheetExtraRow::firstOrCreate([
                'user_id' => $userId,
                'week_start' => $weekStartDate,
                'row_key' => $rowKey,
            ]);
        }
    }

    /**
     * @param  array<mixed>  $rowKeys
     * @return array<int, string>
     */
    private function normalizeExtraRowKeys(array $rowKeys): array
    {
        return collect($rowKeys)
            ->map(function ($rowKey): ?string {
                if (! is_string($rowKey)) {
                    return null;
                }

                [$projectId, $taskId, $asanaGid] = $this->parseRowKey($rowKey);
                if ($projectId === null || $taskId === null) {
                    return null;
                }

                return $this->buildRowKey($projectId, $taskId, $asanaGid);
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function currentHoursFormat(): string
    {
        /** @var User|null $authUser */
        $authUser = auth()->user();

        return $authUser?->hoursDisplayFormat() ?? HoursFormatter::FORMAT_HHMM;
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

    public function render(): View
    {
        $user = $this->viewedUser();
        $hoursFormat = $this->currentHoursFormat();
        $weekStart = CarbonImmutable::parse($this->selectedDate)->startOfWeek();
        $weekDays = collect(range(0, 6))->map(fn (int $offset) => $weekStart->addDays($offset));
        $this->loadPersistedExtraRowsForWeek($weekStart);

        $weekEntries = TimeEntry::with(['project.client', 'task'])
            ->where('user_id', $user->id)
            ->whereBetween('spent_on', [$weekStart->toDateString(), $weekStart->addDays(6)->toDateString()])
            ->get();
        $timeEntryService = app(TimeEntryService::class);

        // Pull projects (with client + tasks) the user has access to.
        // Note: separate cache key from DayView's "projects_picker_{id}" which
        // caches a different (array) shape for its own picker.
        $projects = Cache::remember(
            "projects_picker_eloquent_{$user->id}",
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
        );

        // Asana task name lookup, keyed by gid, scoped to projects on this week
        // (so we can label rows that have an Asana task attached).
        $linkedAsanaProjectGids = $projects->flatMap(fn ($p) => $p->asanaProjects->pluck('gid'))->unique()->values()->all();
        $asanaTasksByGid = AsanaTask::query()
            ->whereIn('asana_project_gid', $linkedAsanaProjectGids)
            ->orderBy('name')
            ->get(['gid', 'asana_project_gid', 'name'])
            ->keyBy('gid');
        // Group into rows by (project, task, asana_task_gid). Each row gets
        // project/task names for display + a 7-cell array of saved hours strings.
        $rowsFromEntries = [];
        $storedCellHours = [];
        $currentCellHours = [];
        $runningCellHours = [];
        foreach ($weekEntries as $entry) {
            $key = $this->buildRowKey($entry->project_id, $entry->task_id, $entry->asana_task_gid);
            if (! isset($rowsFromEntries[$key])) {
                $rowsFromEntries[$key] = [
                    'key' => $key,
                    'client_id' => $entry->project->client_id,
                    'project_name' => $entry->project->timesheetDisplayName(),
                    'client_name' => $entry->project->client->name,
                    'task_name' => $entry->task->name,
                    'asana_task_name' => $entry->asana_task_gid ? ($asanaTasksByGid[$entry->asana_task_gid]->name ?? null) : null,
                    'cells' => array_fill(0, 7, ''),
                ];
            }
            $dayIndex = (int) $weekStart->diffInDays(CarbonImmutable::parse($entry->spent_on));
            if ($dayIndex >= 0 && $dayIndex < 7) {
                $storedCellHours[$key][$dayIndex] = ($storedCellHours[$key][$dayIndex] ?? 0.0) + (float) $entry->hours;
                $rowsFromEntries[$key]['cells'][$dayIndex] = HoursFormatter::format($storedCellHours[$key][$dayIndex], $hoursFormat);

                $currentCellHours[$key][$dayIndex] = ($currentCellHours[$key][$dayIndex] ?? 0.0) + $timeEntryService->currentHours($entry);
                if ($currentCellHours[$key][$dayIndex] > $storedCellHours[$key][$dayIndex]) {
                    $runningCellHours[$key][$dayIndex] = $currentCellHours[$key][$dayIndex];
                }
            }
        }

        foreach ($this->extraRows as $extraKey) {
            if (isset($rowsFromEntries[$extraKey])) {
                continue;
            }
            [$projectId, $taskId, $asanaGid] = $this->parseRowKey($extraKey);
            if ($projectId === null || $taskId === null) {
                continue;
            }
            $project = $projects->firstWhere('id', $projectId);
            $task = $project?->tasks->firstWhere('id', $taskId);
            if (! $project || ! $task) {
                continue;
            }
            $rowsFromEntries[$extraKey] = [
                'key' => $extraKey,
                'client_id' => $project->client_id,
                'project_name' => $project->timesheetDisplayName(),
                'client_name' => $project->client?->name,
                'task_name' => $task->name,
                'asana_task_name' => $asanaGid ? ($asanaTasksByGid[$asanaGid]->name ?? null) : null,
                'cells' => array_fill(0, 7, ''),
            ];
        }

        // Seed the Livewire $cellValues from the database for any row we
        // haven't already touched in this session. This lets the user edit a
        // pre-existing cell, navigate weeks, or hit Save without losing data.
        foreach ($rowsFromEntries as $rowKey => $row) {
            if (! isset($this->cellValues[$rowKey])) {
                $this->cellValues[$rowKey] = $row['cells'];
            } else {
                // Ensure the array always has 7 slots (defensive after wire reset).
                $this->cellValues[$rowKey] = $this->cellValues[$rowKey] + array_fill(0, 7, '');
                ksort($this->cellValues[$rowKey]);
            }
        }

        // Drop cellValues for rows that no longer exist (e.g. removed).
        foreach (array_keys($this->cellValues) as $rowKey) {
            if (! isset($rowsFromEntries[$rowKey])) {
                unset($this->cellValues[$rowKey]);
            }
        }

        // Sort rows alphabetically by client / project / task for consistency.
        $sortedRows = collect($rowsFromEntries)
            ->sortBy(fn ($row) => ($row['client_name'] ?? '').'|'.$row['project_name'].'|'.$row['task_name'])
            ->values()
            ->all();

        $canCopyRowsFromPriorWeek = ! $this->isReadOnly
            && $sortedRows === []
            && TimeEntry::where('user_id', $user->id)
                ->where('spent_on', '<', $weekStart->toDateString())
                ->exists();

        // Per-day totals across all rows, with running timers using live elapsed hours.
        $dayTotals = array_fill(0, 7, 0.0);
        foreach ($this->cellValues as $rowKey => $perDay) {
            for ($i = 0; $i < 7; $i++) {
                $raw = trim((string) ($perDay[$i] ?? ''));
                $hours = 0.0;
                try {
                    $hours = $raw !== '' ? HoursParser::parse($raw) : 0.0;
                } catch (\InvalidArgumentException) {
                    // ignore invalid input in totals
                }

                $dayTotals[$i] += max($hours, (float) ($runningCellHours[$rowKey][$i] ?? 0.0));
            }
        }
        $weekTotal = array_sum($dayTotals);

        // Locked client-months this week that the signed-in viewer cannot
        // change, looked up once for every cell. A display hint only: the
        // month lock observer still enforces.
        /** @var User|null $viewer */
        $viewer = auth()->user();
        $lockedClientMonths = app(MonthLockGuard::class)->lockedClientMonthsFor($viewer, $weekStart, $weekStart->addDays(6));

        // Direct reports for the Team Timesheets dropdown
        $teamMembers = collect();
        $authUser = auth()->user();
        if ($authUser !== null && $this->viewedUserId === null) {
            $teamMembers = $authUser->directReports()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return view('livewire.timesheet.week-view', [
            'weekStart' => $weekStart,
            'weekDays' => $weekDays,
            'rows' => $sortedRows,
            'runningCellHours' => $runningCellHours,
            'lockedClientMonths' => $lockedClientMonths,
            'dayTotals' => $dayTotals,
            'weekTotal' => $weekTotal,
            'projectsForPicker' => $projects->map(fn ($p) => [
                'id' => $p->id,
                'code' => $p->code,
                'name' => $p->name,
                'display_name' => $p->timesheetDisplayName(),
                'client_name' => $p->client?->name ?? '',
                'asana_project_gids' => $p->asanaProjects->pluck('gid')->values()->all(),
                'asana_task_match_terms' => $p->asanaTaskMatchTerms(),
                'asana_task_required' => (bool) $p->asana_task_required,
                'tasks' => $p->tasks
                    ->map(fn ($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'colour' => $t->colour,
                        'is_billable' => (bool) $p->is_billable && (bool) $t->pivot->getAttribute('is_billable'),
                    ])
                    ->values()
                    ->all(),
            ])->values()->all(),
            'asanaAvailable' => $this->asanaIntegrationAvailable(),
            'teamMembers' => $teamMembers,
            'canCopyRowsFromPriorWeek' => $canCopyRowsFromPriorWeek,
            'viewedUser' => $user,
            'hoursFormat' => $hoursFormat,
        ]);
    }
}
