<?php

namespace App\Domain\Mcp;

use App\Domain\Budgeting\ProjectBudgetCalculator;
use App\Domain\Reporting\TimeReportQuery;
use App\Domain\TimeTracking\HoursParser;
use App\Domain\TimeTracking\ProjectPickerCache;
use App\Domain\TimeTracking\ProjectTaskUsability;
use App\Domain\TimeTracking\TimeEntryService;
use App\Enums\BudgetType;
use App\Enums\ClientTaskBillabilityProfile;
use App\Enums\GroupBy;
use App\Models\AsanaTask;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InternalMcpActions
{
    public function __construct(
        private readonly TimeEntryService $timeEntries,
        private readonly ProjectBudgetCalculator $budgetCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTimeEntry(User $actor, array $data): TimeEntry
    {
        $this->assertActive($actor);

        $validated = Validator::validate($data, [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'spent_on' => ['required', 'date_format:Y-m-d'],
            'hours' => ['required'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'asana_task_gid' => ['nullable', 'string'],
        ]);

        $hours = $this->parseHours($validated['hours']);
        $this->ensureProjectTaskIsUsable($actor, (int) $validated['project_id'], (int) $validated['task_id'], $validated['asana_task_gid'] ?? null);

        return $this->timeEntries->create($actor, [
            'project_id' => (int) $validated['project_id'],
            'task_id' => (int) $validated['task_id'],
            'spent_on' => (string) $validated['spent_on'],
            'hours' => $hours,
            'notes' => $validated['notes'] ?? null,
            'asana_task_gid' => $validated['asana_task_gid'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function startTimer(User $actor, array $data): TimeEntry
    {
        $this->assertActive($actor);

        $validated = Validator::validate($data, [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'spent_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'asana_task_gid' => ['nullable', 'string'],
        ]);

        $this->ensureProjectTaskIsUsable($actor, (int) $validated['project_id'], (int) $validated['task_id'], $validated['asana_task_gid'] ?? null);

        $entry = $this->timeEntries->create($actor, [
            'project_id' => (int) $validated['project_id'],
            'task_id' => (int) $validated['task_id'],
            'spent_on' => (string) $validated['spent_on'],
            'hours' => 0.01,
            'notes' => $validated['notes'] ?? null,
            'asana_task_gid' => $validated['asana_task_gid'] ?? null,
        ]);

        $this->timeEntries->startTimer($entry);

        return $entry->refresh();
    }

    public function stopTimer(User $actor): TimeEntry
    {
        $this->assertActive($actor);

        $entry = TimeEntry::where('user_id', $actor->id)
            ->where('is_running', true)
            ->first();

        if ($entry === null) {
            throw ValidationException::withMessages(['timer' => 'No running timer exists for the authenticated user.']);
        }

        $this->timeEntries->stopTimer($entry);

        return $entry->refresh();
    }

    public function runningTimer(User $actor): ?TimeEntry
    {
        $this->assertActive($actor);

        return TimeEntry::with(['project.client', 'task', 'user'])
            ->where('user_id', $actor->id)
            ->where('is_running', true)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTimeEntry(User $actor, TimeEntry $entry, array $data): TimeEntry
    {
        $this->assertCanWriteTimeEntry($actor, $entry);

        $validated = Validator::validate($data, [
            'project_id' => ['sometimes', 'integer', 'exists:projects,id'],
            'task_id' => ['sometimes', 'integer', 'exists:tasks,id'],
            'spent_on' => ['sometimes', 'date_format:Y-m-d'],
            'hours' => ['sometimes'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'asana_task_gid' => ['nullable', 'string'],
        ]);

        $projectId = (int) ($validated['project_id'] ?? $entry->project_id);
        $taskId = (int) ($validated['task_id'] ?? $entry->task_id);
        $targetUser = $entry->user()->firstOrFail();
        $this->ensureProjectTaskIsUsable($targetUser, $projectId, $taskId, $validated['asana_task_gid'] ?? $entry->asana_task_gid);

        $update = Arr::only($validated, ['project_id', 'task_id', 'spent_on', 'notes', 'asana_task_gid']);

        if (array_key_exists('hours', $validated)) {
            $update['hours'] = $this->parseHours($validated['hours']);
        }

        return $this->timeEntries->update($entry, $update);
    }

    public function deleteTimeEntry(User $actor, TimeEntry $entry): void
    {
        $this->assertCanWriteTimeEntry($actor, $entry);

        $this->timeEntries->delete($entry);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createClient(User $actor, array $data): Client
    {
        $this->assertAdmin($actor);

        $validated = Validator::validate($data, [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', 'unique:clients,code'],
            'task_billability_profile' => ['sometimes', Rule::in(['agency', 'jdw'])],
            'default_task_ids' => ['sometimes', 'array'],
            'default_task_ids.*' => ['integer', 'exists:tasks,id'],
        ]);

        $client = Client::create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'task_billability_profile' => isset($validated['task_billability_profile'])
                ? ClientTaskBillabilityProfile::from($validated['task_billability_profile'])
                : ClientTaskBillabilityProfile::Agency,
        ]);

        if (array_key_exists('default_task_ids', $validated)) {
            $this->syncClientDefaultTasks($client, $validated['default_task_ids']);
        }

        return $client->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateClient(User $actor, Client $client, array $data): Client
    {
        $this->assertAdmin($actor);

        $validated = Validator::validate($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('clients', 'code')->ignore($client->id)],
            'task_billability_profile' => ['sometimes', Rule::in(['agency', 'jdw'])],
            'default_task_ids' => ['sometimes', 'array'],
            'default_task_ids.*' => ['integer', 'exists:tasks,id'],
        ]);

        $taskBillabilityProfileChanged = array_key_exists('task_billability_profile', $validated)
            && $client->task_billability_profile !== ClientTaskBillabilityProfile::from($validated['task_billability_profile']);

        $update = Arr::only($validated, ['name', 'code']);
        if (array_key_exists('task_billability_profile', $validated)) {
            $update['task_billability_profile'] = ClientTaskBillabilityProfile::from($validated['task_billability_profile']);
        }

        $client->update($update);

        if (array_key_exists('default_task_ids', $validated)) {
            $this->syncClientDefaultTasks($client, $validated['default_task_ids']);
        }

        if ($taskBillabilityProfileChanged) {
            $client->reapplyTaskBillabilityToProjects();
            $this->forgetProjectPickerCachesForClient($client);
        }

        return $client->refresh();
    }

    public function archiveClient(User $actor, Client $client, bool $archive = true): Client
    {
        $this->assertAdmin($actor);

        $client->update(['is_archived' => $archive]);

        return $client->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createProject(User $actor, array $data): Project
    {
        $this->assertAdmin($actor);

        $validated = Validator::validate($data, [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'code' => ['required', 'string', 'max:50', 'unique:projects,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_billable' => ['sometimes', 'boolean'],
            'default_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'budget_type' => ['nullable', Rule::in(['fixed_fee', 'monthly_ci'])],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'budget_hours' => ['nullable', 'numeric', 'min:0'],
            'budget_starts_on' => ['nullable', 'date_format:Y-m-d'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'asana_task_required' => ['sometimes', 'boolean'],
            'task_ids' => ['sometimes', 'array'],
            'task_ids.*' => ['integer', 'exists:tasks,id'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $project = Project::create([
            'client_id' => (int) $validated['client_id'],
            'manager_user_id' => $validated['manager_user_id'] ?? null,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'is_billable' => $validated['is_billable'] ?? true,
            'default_hourly_rate' => $validated['default_hourly_rate'] ?? null,
            'budget_type' => isset($validated['budget_type']) ? BudgetType::from($validated['budget_type']) : null,
            'budget_amount' => $validated['budget_amount'] ?? null,
            'budget_hours' => $validated['budget_hours'] ?? null,
            'budget_starts_on' => $validated['budget_starts_on'] ?? null,
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
            'asana_task_required' => $validated['asana_task_required'] ?? true,
        ]);

        if (array_key_exists('task_ids', $validated)) {
            $this->syncProjectTasks($project, $validated['task_ids']);
        } else {
            $project->attachClientDefaultTasks();
        }

        if (array_key_exists('user_ids', $validated)) {
            $this->syncProjectUsers($project, $validated['user_ids']);
        }

        ProjectPickerCache::forgetForUsers($validated['user_ids'] ?? []);

        return $project->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProject(User $actor, Project $project, array $data): Project
    {
        $this->assertAdmin($actor);

        $previousUserIds = $project->users()->pluck('users.id')->all();

        $validated = Validator::validate($data, [
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('projects', 'code')->ignore($project->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'is_billable' => ['sometimes', 'boolean'],
            'default_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'budget_type' => ['nullable', Rule::in(['fixed_fee', 'monthly_ci'])],
            'budget_amount' => ['nullable', 'numeric', 'min:0'],
            'budget_hours' => ['nullable', 'numeric', 'min:0'],
            'budget_starts_on' => ['nullable', 'date_format:Y-m-d'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'asana_task_required' => ['sometimes', 'boolean'],
            'task_ids' => ['sometimes', 'array'],
            'task_ids.*' => ['integer', 'exists:tasks,id'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $clientChanged = array_key_exists('client_id', $validated)
            && (int) $validated['client_id'] !== $project->client_id;

        $update = Arr::only($validated, [
            'client_id',
            'manager_user_id',
            'code',
            'name',
            'is_billable',
            'default_hourly_rate',
            'budget_amount',
            'budget_hours',
            'budget_starts_on',
            'starts_on',
            'ends_on',
            'asana_task_required',
        ]);

        if (array_key_exists('budget_type', $validated)) {
            $update['budget_type'] = $validated['budget_type'] !== null ? BudgetType::from($validated['budget_type']) : null;
        }

        $project->update($update);

        if (array_key_exists('task_ids', $validated)) {
            $this->syncProjectTasks($project, $validated['task_ids']);
        } elseif ($clientChanged) {
            $project->refresh();
            $project->reapplyTaskBillabilityToTasks();
        }

        if (array_key_exists('user_ids', $validated)) {
            $this->syncProjectUsers($project, $validated['user_ids']);
        }

        // Both current and removed members see this project's picker change.
        ProjectPickerCache::forgetForUsers([
            ...$previousUserIds,
            ...($validated['user_ids'] ?? []),
        ]);

        return $project->refresh();
    }

    public function archiveProject(User $actor, Project $project, bool $archive = true): Project
    {
        $this->assertAdmin($actor);

        $project->update(['is_archived' => $archive]);

        ProjectPickerCache::forgetForUsers($project->users()->pluck('users.id'));

        return $project->refresh();
    }

    public function assignProjectMember(User $actor, Project $project, User $member): Project
    {
        $this->assertAdmin($actor);

        if (! $member->is_active) {
            throw ValidationException::withMessages(['user_id' => 'The selected user is inactive.']);
        }

        $project->users()->syncWithoutDetaching([
            $member->id => ['hourly_rate_override' => null, 'rate_id' => null],
        ]);

        ProjectPickerCache::forgetForUsers([$member->id]);

        return $project->refresh();
    }

    public function unassignProjectMember(User $actor, Project $project, User $member): Project
    {
        $this->assertAdmin($actor);

        $project->users()->detach($member->id);

        ProjectPickerCache::forgetForUsers([$member->id]);

        return $project->refresh();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, TimeEntry>
     */
    public function listTimeEntries(User $actor, array $filters): array
    {
        $this->assertActive($actor);

        $validated = Validator::validate($filters, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requestedUserId = isset($validated['user_id']) ? (int) $validated['user_id'] : $actor->id;
        $this->assertCanReadUserTime($actor, $requestedUserId);

        $query = TimeEntry::with(['project.client', 'task', 'user'])
            ->where('user_id', $requestedUserId);

        if (isset($validated['from'])) {
            $query->where('spent_on', '>=', $validated['from']);
        }

        if (isset($validated['to'])) {
            $query->where('spent_on', '<=', $validated['to']);
        }

        if (isset($validated['project_id'])) {
            $query->where('project_id', (int) $validated['project_id']);
        }

        if (isset($validated['task_id'])) {
            $query->where('task_id', (int) $validated['task_id']);
        }

        return $query->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get()
            ->all();
    }

    /**
     * @return array<int, Client>
     */
    public function listClients(User $actor, bool $includeArchived = false): array
    {
        $this->assertActive($actor);

        $query = Client::query()->orderBy('name');

        if (! $includeArchived) {
            $query->where('is_archived', false);
        }

        if (! $actor->isManager()) {
            $query->whereHas('projects.users', fn (Builder $query) => $query->whereKey($actor->id));
        }

        return $query->get()->all();
    }

    /**
     * @return array<int, Project>
     */
    public function listProjects(User $actor, bool $includeArchived = false, bool $all = false): array
    {
        $this->assertActive($actor);

        $query = Project::with(['client', 'tasks', 'users', 'asanaProjects'])->orderBy('name');

        if (! $includeArchived) {
            $query->where('is_archived', false);
        }

        if (! $all || ! $actor->isManager()) {
            $query->whereHas('users', fn (Builder $query) => $query->whereKey($actor->id));
        }

        return $query->get()->all();
    }

    /**
     * @return array<int, Task>
     */
    public function listTasks(User $actor, bool $includeArchived = false): array
    {
        $this->assertActive($actor);

        $query = Task::orderBy('sort_order')->orderBy('name');

        if (! $includeArchived) {
            $query->where('is_archived', false);
        }

        return $query->get()->all();
    }

    /**
     * @return array{asana_project_gids: array<int, string>, tasks: array<int, AsanaTask>}
     */
    public function listAsanaTasks(User $actor, int $projectId, ?string $asanaProjectGid = null, bool $includeCompleted = false): array
    {
        $this->assertActive($actor);

        $project = Project::with(['users', 'asanaProjects'])->findOrFail($projectId);

        if (! $actor->isManager() && ! $project->users->contains('id', $actor->id)) {
            throw new AuthorizationException('You are not allowed to view Asana tasks for this project.');
        }

        $linkedBoardGids = $project->asanaProjects
            ->sortBy('name')
            ->pluck('gid')
            ->values()
            ->all();

        if ($asanaProjectGid !== null && $asanaProjectGid !== '') {
            if (! in_array($asanaProjectGid, $linkedBoardGids, true)) {
                throw ValidationException::withMessages(['asana_project_gid' => 'The selected Asana board is not linked to this project.']);
            }

            $linkedBoardGids = [$asanaProjectGid];
        }

        $query = AsanaTask::whereIn('asana_project_gid', $linkedBoardGids)
            ->orderBy('name');

        if (! $includeCompleted) {
            $query->where('is_completed', false);
        }

        return [
            'asana_project_gids' => $linkedBoardGids,
            'tasks' => $query->get()->all(),
        ];
    }

    /**
     * @return array<int, User>
     */
    public function listUsers(User $actor, bool $includeInactive = false): array
    {
        $this->assertAdmin($actor);

        $query = User::orderBy('name');

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return $query->get()->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function timeReport(User $actor, array $filters): array
    {
        $this->assertActive($actor);

        $validated = Validator::validate($filters, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'billable_only' => ['sometimes', 'boolean'],
            'group_by' => ['nullable', Rule::in(['client', 'project', 'task', 'user'])],
        ]);

        $userId = isset($validated['user_id']) ? (int) $validated['user_id'] : null;
        if ($userId !== null) {
            $this->assertCanReadUserTime($actor, $userId);
        } elseif (! $actor->isManager()) {
            $userId = $actor->id;
        }

        $query = new TimeReportQuery(
            from: CarbonImmutable::parse($validated['from']),
            to: CarbonImmutable::parse($validated['to']),
            userId: $userId,
            clientId: isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            projectId: isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            taskId: isset($validated['task_id']) ? (int) $validated['task_id'] : null,
            billableOnly: (bool) ($validated['billable_only'] ?? false),
        );

        $totals = $query->totals();
        $result = [
            'totals' => [
                'total_hours' => $totals->totalHours,
                'billable_hours' => $totals->billableHours,
                'billable_amount' => $totals->billableAmount,
                'uninvoiced_amount' => $totals->uninvoicedAmount,
                'billable_percent' => $totals->billablePercent,
            ],
        ];

        if (isset($validated['group_by'])) {
            $result['groups'] = $query->groupBy(GroupBy::from($validated['group_by']))
                ->map(fn (object $row): array => (array) $row)
                ->values()
                ->all();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function projectBudget(User $actor, Project $project): ?array
    {
        $this->assertActive($actor);

        if (! $actor->isManager() && ! $project->users()->whereKey($actor->id)->exists()) {
            throw new AuthorizationException('You are not allowed to view this project budget.');
        }

        $status = $this->budgetCalculator->forProject($project);

        if ($status === null) {
            return null;
        }

        return [
            'budget_type' => $status->budgetType->value,
            'budget_amount' => $status->budgetAmount,
            'budget_hours' => $status->budgetHours,
            'actual_amount' => $status->actualAmount,
            'actual_hours' => $status->actualHours,
            'variance' => $status->variance(),
            'percent_used' => $status->percentUsed(),
            'is_over_budget' => $status->isOver(),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeTimeEntry(TimeEntry $entry): array
    {
        $entry->loadMissing(['project.client', 'task', 'user']);

        return [
            'id' => $entry->id,
            'user_id' => $entry->user_id,
            'user_name' => $entry->user->name,
            'client_id' => $entry->project->client_id,
            'client_name' => $entry->project->client->name,
            'project_id' => $entry->project_id,
            'project_name' => $entry->project->name,
            'task_id' => $entry->task_id,
            'task_name' => $entry->task->name,
            'spent_on' => $entry->spent_on->toDateString(),
            'hours' => (float) $entry->hours,
            'notes' => $entry->notes,
            'is_running' => (bool) $entry->is_running,
            'timer_started_at' => $entry->timer_started_at?->toIso8601String(),
            'asana_task_gid' => $entry->asana_task_gid,
            'is_billable' => (bool) $entry->is_billable,
            'billable_amount' => (float) $entry->billable_amount,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeClient(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'code' => $client->code,
            'task_billability_profile' => $client->task_billability_profile->value,
            'is_archived' => (bool) $client->is_archived,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeProject(Project $project): array
    {
        $project->loadMissing(['client', 'tasks', 'users', 'asanaProjects']);

        $asanaProjects = $project->asanaProjects
            ->sortBy('name')
            ->values();

        return [
            'id' => $project->id,
            'client_id' => $project->client_id,
            'client_name' => $project->client->name,
            'manager_user_id' => $project->manager_user_id,
            'code' => $project->code,
            'name' => $project->name,
            'is_billable' => (bool) $project->is_billable,
            'starts_on' => $project->starts_on?->toDateString(),
            'ends_on' => $project->ends_on?->toDateString(),
            'is_archived' => (bool) $project->is_archived,
            'asana_task_required' => (bool) $project->asana_task_required,
            'asana_project_gids' => $asanaProjects->pluck('gid')->values()->all(),
            'asana_projects' => $asanaProjects
                ->map(fn ($asanaProject): array => [
                    'gid' => $asanaProject->gid,
                    'workspace_gid' => $asanaProject->workspace_gid,
                    'name' => $asanaProject->name,
                    'is_archived' => (bool) $asanaProject->is_archived,
                ])
                ->all(),
            'task_ids' => $project->tasks->pluck('id')->values()->all(),
            'user_ids' => $project->users->pluck('id')->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function serializeTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'name' => $task->name,
            'is_default_billable' => (bool) $task->is_default_billable,
            'is_jdw_default_billable' => (bool) $task->is_jdw_default_billable,
            'colour' => $task->colour,
            'sort_order' => $task->sort_order,
            'is_archived' => (bool) $task->is_archived,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeAsanaTask(AsanaTask $task): array
    {
        return [
            'gid' => $task->gid,
            'asana_project_gid' => $task->asana_project_gid,
            'name' => $task->name,
            'is_completed' => (bool) $task->is_completed,
            'parent_gid' => $task->parent_gid,
        ];
    }

    /** @return array<string, mixed> */
    public function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'is_active' => (bool) $user->is_active,
        ];
    }

    public function assertActive(User $user): void
    {
        if (! $user->is_active) {
            throw new AuthorizationException('The authenticated user is inactive.');
        }
    }

    public function assertAdmin(User $user): void
    {
        $this->assertActive($user);

        if (! $user->isAdmin()) {
            throw new AuthorizationException('Administrator access is required for this MCP tool.');
        }
    }

    private function assertCanWriteTimeEntry(User $actor, TimeEntry $entry): void
    {
        $this->assertActive($actor);

        if ($entry->user_id !== $actor->id && ! $actor->isAdmin()) {
            throw new AuthorizationException('Only admins can change another user\'s time entry.');
        }
    }

    private function assertCanReadUserTime(User $actor, int $userId): void
    {
        if ($userId === $actor->id || $actor->isAdmin()) {
            return;
        }

        if (User::whereKey($userId)->where('reports_to_user_id', $actor->id)->exists()) {
            return;
        }

        throw new AuthorizationException('You are not allowed to read that user\'s time entries.');
    }

    private function parseHours(mixed $hours): float
    {
        try {
            return HoursParser::parse((string) $hours);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['hours' => $exception->getMessage()]);
        }
    }

    private function ensureProjectTaskIsUsable(User $user, int $projectId, int $taskId, ?string $asanaTaskGid): void
    {
        ProjectTaskUsability::ensure($user, $projectId, $taskId, $asanaTaskGid);
    }

    /**
     * @param  array<int, int>  $taskIds
     */
    private function syncClientDefaultTasks(Client $client, array $taskIds): void
    {
        $sync = [];
        foreach (array_values(array_unique(array_map('intval', $taskIds))) as $idx => $taskId) {
            $sync[$taskId] = ['sort_order' => $idx];
        }

        $client->defaultTasks()->sync($sync);
    }

    private function forgetProjectPickerCachesForClient(Client $client): void
    {
        $client->loadMissing('projects.users:id');

        ProjectPickerCache::forgetForUsers(
            $client->projects->flatMap(fn ($project) => $project->users->pluck('id'))
        );
    }

    /**
     * @param  array<int, int>  $taskIds
     */
    private function syncProjectTasks(Project $project, array $taskIds): void
    {
        $project->load('client');
        $tasksById = Task::whereIn('id', $taskIds)->get()->keyBy('id');
        $sync = [];

        foreach (array_values(array_unique(array_map('intval', $taskIds))) as $taskId) {
            $task = $tasksById->get($taskId);
            $sync[$taskId] = ['is_billable' => $task?->defaultBillableForProject($project) ?? false];
        }

        $project->tasks()->sync($sync);
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function syncProjectUsers(Project $project, array $userIds): void
    {
        $sync = [];

        foreach (array_values(array_unique(array_map('intval', $userIds))) as $userId) {
            $sync[$userId] = ['hourly_rate_override' => null, 'rate_id' => null];
        }

        $project->users()->sync($sync);
    }
}
