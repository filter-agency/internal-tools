<?php

namespace App\Livewire\Admin\Users;

use App\Enums\ContractPosition;
use App\Enums\Role;
use App\Models\Rate;
use App\Models\Team;
use App\Models\User;
use App\Models\UserContractPosition;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    /** Shown when Save is pressed with a position change filled in but not added. */
    public const UNADDED_POSITION_ERROR = 'Add this position change or clear its date before saving.';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $showArchived = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $confirmingArchiveId = null;

    public string $editName = ''; // display only — name is sourced from Google OAuth, not editable

    #[Locked]
    public string $editEmail = '';

    public string $editRole = '';

    public string $editRoleTitle = '';

    public ?int $editRateId = null;

    public string $editWeeklyCapacity = '';

    /** @var array<int, string> */
    public array $editScheduleWorkDays = ['1', '2', '3', '4', '5'];

    /** @var array<int, int|string> */
    public array $editTeamIds = [''];

    public string $editIsContractor = '0';

    public string $editStartedOn = '';

    public string $editLeftOn = '';

    /** Start date, leave date and employment type come from Breathe HR for this person. */
    #[Locked]
    public bool $editSyncedFromBreathe = false;

    #[Locked]
    public string $editBreatheSyncedAt = '';

    public ?int $editReportsToUserId = null;

    public string $editNotificationsPausedUntil = '';

    public bool $editEmailNotificationsEnabled = true;

    public bool $editSlackNotificationsEnabled = true;

    public ?string $editSlackUserId = null;

    /** Add-position form in the edit panel; '' means outside the contracted team. */
    public string $newPosition = '';

    public string $newPositionFrom = '';

    public function edit(int $userId): void
    {
        Gate::authorize('access-admin');

        $user = User::findOrFail($userId);
        $this->editingId = $userId;
        $this->editName = $user->name;
        $this->editEmail = $user->email;
        $this->editRole = $user->role->value;
        $this->editRoleTitle = $user->role_title ?? '';
        $this->editRateId = $user->rate_id;
        $this->editWeeklyCapacity = (string) $user->weekly_capacity_hours;
        $this->editScheduleWorkDays = collect($user->effectiveScheduleWorkDays())
            ->map(fn (int $day): string => (string) $day)
            ->all();
        $this->editTeamIds = $this->teamRowsFromIds($user->teams()->pluck('teams.id')->all());
        $this->editIsContractor = $user->is_contractor ? '1' : '0';
        $this->editStartedOn = $user->started_on?->toDateString() ?? '';
        $this->editLeftOn = $user->left_on?->toDateString() ?? '';
        $this->editSyncedFromBreathe = $user->isSyncedFromBreathe();
        $this->editBreatheSyncedAt = $user->breathe_synced_at?->format('j M Y, H:i') ?? '';
        $this->editReportsToUserId = $user->reports_to_user_id;
        $this->editNotificationsPausedUntil = $user->notifications_paused_until?->toDateString() ?? '';
        $this->editEmailNotificationsEnabled = $user->email_notifications_enabled;
        $this->editSlackNotificationsEnabled = $user->slack_notifications_enabled;
        $this->editSlackUserId = $user->slack_user_id;
        $this->reset('newPosition', 'newPositionFrom');
        $this->resetErrorBag();
    }

    public function save(): void
    {
        Gate::authorize('access-admin');

        // The position form saves on its own button, so a filled-in date would be lost silently.
        if ($this->newPositionFrom !== '') {
            $this->addError('newPositionFrom', self::UNADDED_POSITION_ERROR);

            return;
        }

        $scheduleWorkDays = collect($this->editScheduleWorkDays)
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day >= 1 && $day <= 7)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $this->editTeamIds = $this->normalisedTeamIds($this->editTeamIds);

        $this->validate([
            'editRole' => 'required|in:user,manager,admin',
            'editRoleTitle' => 'nullable|string|max:255',
            'editRateId' => 'nullable|exists:rates,id',
            'editWeeklyCapacity' => 'required|numeric|min:0|max:168',
            'editScheduleWorkDays' => 'required|array|min:1',
            'editScheduleWorkDays.*' => 'integer|between:1,7',
            'editIsContractor' => 'boolean',
            'editStartedOn' => 'nullable|date',
            'editLeftOn' => array_filter([
                'nullable',
                'date',
                $this->editStartedOn !== '' ? 'after_or_equal:editStartedOn' : null,
            ]),
            'editTeamIds' => 'array',
            'editTeamIds.*' => [
                'integer',
                Rule::exists('teams', 'id')->where(fn ($q) => $q->where('is_archived', false)),
            ],
            'editReportsToUserId' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('role', [Role::Manager->value, Role::Admin->value])->where('is_active', true)),
            ],
            'editNotificationsPausedUntil' => 'nullable|date',
        ], [
            'editLeftOn.after_or_equal' => "The leave date can't be before the start date.",
        ]);

        if ((int) $this->editingId === auth()->id()) {
            if ($this->editRole !== Role::Admin->value) {
                $this->addError('editRole', 'You cannot change your own role.');

                return;
            }
        }

        if ($this->editReportsToUserId !== null) {
            if ($this->editReportsToUserId === (int) $this->editingId) {
                $this->addError('editReportsToUserId', 'A user cannot report to themselves.');

                return;
            }

            if (in_array($this->editReportsToUserId, $this->descendantIds((int) $this->editingId), true)) {
                $this->addError('editReportsToUserId', 'That choice would create a circular reporting line.');

                return;
            }
        }

        $user = User::findOrFail((int) $this->editingId);

        // Breathe HR owns these for synced people; its nightly sync would undo an edit anyway.
        $employment = $user->isSyncedFromBreathe() ? [] : [
            'is_contractor' => $this->editIsContractor === '1',
            'started_on' => $this->editStartedOn !== '' ? $this->editStartedOn : null,
            'left_on' => $this->editLeftOn !== '' ? $this->editLeftOn : null,
        ];

        $user->update($employment + [
            'role' => Role::from($this->editRole),
            'role_title' => $this->editRoleTitle ?: null,
            'rate_id' => $this->editRateId,
            'weekly_capacity_hours' => (float) $this->editWeeklyCapacity,
            'schedule_work_days' => $scheduleWorkDays,
            'reports_to_user_id' => $this->editReportsToUserId,
            'notifications_paused_until' => $this->editNotificationsPausedUntil !== '' ? $this->editNotificationsPausedUntil : null,
            'email_notifications_enabled' => $this->editEmailNotificationsEnabled,
            'slack_notifications_enabled' => $this->editSlackNotificationsEnabled,
        ]);
        $user->teams()->sync($this->editTeamIds);

        $this->editingId = null;
        $this->editTeamIds = [''];
    }

    /** Saves straight away, independent of the main form's Save button. */
    public function addContractPosition(): void
    {
        Gate::authorize('access-admin');

        $this->newPosition = trim($this->newPosition);

        $this->validate([
            'newPosition' => ['nullable', Rule::enum(ContractPosition::class)],
            'newPositionFrom' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
        ], [
            'newPositionFrom.after_or_equal' => 'Enter a date from 2000 onwards.',
        ], [
            'newPosition' => 'position',
            'newPositionFrom' => 'effective date',
        ]);

        $user = User::findOrFail((int) $this->editingId);

        // whereDate, not Rule::unique: SQLite stores date casts with a time part.
        if ($user->contractPositions()->whereDate('effective_from', $this->newPositionFrom)->exists()) {
            $this->addError('newPositionFrom', 'This person already has a position change on that date.');

            return;
        }

        $user->contractPositions()->create([
            'position' => ContractPosition::tryFrom($this->newPosition),
            'effective_from' => $this->newPositionFrom,
        ]);

        $this->reset('newPosition', 'newPositionFrom');
    }

    public function removeContractPosition(int $id): void
    {
        Gate::authorize('access-admin');

        // Scoped to the person being edited so another person's row id does nothing.
        UserContractPosition::query()
            ->where('user_id', (int) $this->editingId)
            ->whereKey($id)
            ->delete();
    }

    public function addEditTeamRow(): void
    {
        Gate::authorize('access-admin');

        $this->editTeamIds[] = '';
    }

    public function removeEditTeamRow(int $index): void
    {
        Gate::authorize('access-admin');

        unset($this->editTeamIds[$index]);
        $this->editTeamIds = array_values($this->editTeamIds);

        if ($this->editTeamIds === []) {
            $this->editTeamIds = [''];
        }
    }

    public function confirmArchive(int $userId): void
    {
        Gate::authorize('access-admin');

        if ($userId === auth()->id()) {
            session()->flash('users.error', 'You cannot archive yourself.');

            return;
        }

        $this->confirmingArchiveId = $userId;
    }

    public function cancelArchive(): void
    {
        $this->confirmingArchiveId = null;
    }

    public function archive(): void
    {
        Gate::authorize('access-admin');

        if ($this->confirmingArchiveId === null) {
            return;
        }

        if ($this->confirmingArchiveId === auth()->id()) {
            $this->confirmingArchiveId = null;
            session()->flash('users.error', 'You cannot archive yourself.');

            return;
        }

        $user = User::findOrFail($this->confirmingArchiveId);
        $user->archive();
        $this->confirmingArchiveId = null;
        session()->flash('users.flash', "{$user->name} has been archived. Their time entries are preserved.");
    }

    public function unarchive(int $userId): void
    {
        Gate::authorize('access-admin');

        $user = User::findOrFail($userId);
        $user->unarchive();
        session()->flash('users.flash', "{$user->name} has been reactivated.");
    }

    /**
     * IDs of every user that ultimately reports up to $userId (transitive direct reports).
     *
     * @return array<int>
     */
    private function descendantIds(int $userId): array
    {
        $descendants = [];
        $queue = [$userId];

        while ($queue !== []) {
            $batch = User::query()->whereIn('reports_to_user_id', $queue)->pluck('id')->all();
            $queue = array_values(array_diff($batch, $descendants));
            $descendants = array_merge($descendants, $queue);
        }

        return $descendants;
    }

    public function cancel(): void
    {
        $this->editingId = null;
        $this->editTeamIds = [''];
        $this->resetErrorBag();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function normalisedTeamIds(array $ids): array
    {
        return collect($ids)
            ->map(fn ($teamId) => (int) $teamId)
            ->filter(fn (int $teamId) => $teamId > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int|string>
     */
    private function teamRowsFromIds(array $ids): array
    {
        $normalised = $this->normalisedTeamIds($ids);

        return $normalised === [] ? [''] : $normalised;
    }

    public function render(): View
    {
        $managerCandidates = collect();
        if ($this->editingId !== null) {
            $excluded = array_merge([(int) $this->editingId], $this->descendantIds((int) $this->editingId));

            $managerCandidates = User::query()
                ->where('is_active', true)
                ->whereIn('role', [Role::Manager->value, Role::Admin->value])
                ->whereNotIn('id', $excluded)
                ->orderBy('name')
                ->get();
        }

        $roleTitles = collect();
        if ($this->editingId !== null) {
            $roleTitles = User::query()
                ->whereNotNull('role_title')
                ->distinct()
                ->orderBy('role_title')
                ->pluck('role_title');
        }

        $contractPositions = collect();
        if ($this->editingId !== null) {
            $contractPositions = UserContractPosition::query()
                ->where('user_id', $this->editingId)
                ->orderByDesc('effective_from')
                ->get();
        }

        $usersQuery = User::orderBy('archived_at')->orderBy('name');

        if (! $this->showArchived) {
            $usersQuery->whereNull('archived_at');
        }

        $term = trim($this->search);
        if ($term !== '') {
            $usersQuery->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('role_title', 'like', "%{$term}%");
            });
        }

        $archivedCount = User::query()->whereNotNull('archived_at')->count();

        return view('livewire.admin.users.index', [
            'users' => $usersQuery->with('rate')->get(),
            'roles' => Role::cases(),
            'managerCandidates' => $managerCandidates,
            'roleTitles' => $roleTitles,
            'contractPositions' => $contractPositions,
            'contractPositionOptions' => ContractPosition::cases(),
            'rates' => Rate::where('is_archived', false)->orderBy('name')->get(),
            'teams' => Team::active()->orderBy('name')->get(),
            'archivedCount' => $archivedCount,
        ]);
    }
}
