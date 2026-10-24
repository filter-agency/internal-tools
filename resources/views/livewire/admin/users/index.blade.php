<div>
    <div class="flex items-center justify-between mb-6 gap-4">
        <h1 class="text-xl font-semibold text-gray-900">Users</h1>
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
                <input wire:model.live="showArchived" type="checkbox" class="rounded">
                Show archived
                @if($archivedCount > 0)
                    <span class="text-xs text-gray-400">({{ $archivedCount }})</span>
                @endif
            </label>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search by name, email or title…"
                   class="w-72 border border-gray-300 rounded text-sm px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                   style="min-width: 250px">
        </div>
    </div>

    @if(session('users.flash'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-sm text-green-800 rounded-md">
            {{ session('users.flash') }}
        </div>
    @endif
    @if(session('users.error'))
        <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-sm text-red-800 rounded-md">
            {{ session('users.error') }}
        </div>
    @endif

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Email</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-600">Role</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-600">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($users as $user)
                    @php $isArchived = $user->archived_at !== null; @endphp
                    <tr class="{{ $isArchived ? 'opacity-60 bg-gray-50' : '' }}">
                        <td class="px-4 py-3 font-medium text-gray-900">
                            {{ $user->name }}
                            @if($isArchived)
                                <span class="ml-2 text-xs font-normal text-gray-500">(archived {{ $user->archived_at->format('j M Y') }})</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $user->email }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium
                                {{ $user->role->value === 'admin' ? 'bg-red-100 text-red-700' : ($user->role->value === 'manager' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600') }}">
                                {{ ucfirst($user->role->value) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($isArchived)
                                <span class="inline-flex items-center gap-1.5 text-xs text-gray-500">
                                    <span class="inline-block w-2 h-2 rounded-full bg-gray-400"></span>
                                    Archived
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 text-xs text-green-700">
                                    <span class="inline-block w-2 h-2 rounded-full bg-green-500"></span>
                                    Active
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right space-x-3 whitespace-nowrap">
                            @if($isArchived)
                                <button wire:click="unarchive({{ $user->id }})" class="text-sm text-blue-600 hover:underline">Unarchive</button>
                            @else
                                <button wire:click="edit({{ $user->id }})" class="text-sm text-blue-600 hover:underline">Edit</button>
                                @if($user->id !== auth()->id())
                                    <button wire:click="confirmArchive({{ $user->id }})" class="text-sm text-red-600 hover:underline">Archive</button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($editingId !== null)
        @php $isSelfEdit = $editingId === auth()->id(); @endphp
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
            wire:click="cancel"
            x-data
            @keydown.escape.window="$wire.cancel()"
        >
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 px-6 pt-6 pb-12 max-h-[90vh] overflow-y-auto" @click.stop>
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-base font-semibold text-gray-900">Edit User</h2>
                    <button wire:click="cancel" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Name</label>
                    <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-700">{{ $editName }}</div>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Email</label>
                    <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-500">{{ $editEmail }}</div>
                </div>

                <div class="mb-1">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Role</label>
                    <select
                        wire:model.live="editRole"
                        class="w-full border border-gray-300 rounded-md text-sm px-3 py-2 {{ $isSelfEdit ? 'opacity-50 cursor-not-allowed bg-gray-50' : '' }}"
                        @disabled($isSelfEdit)
                    >
                        @foreach($roles as $role)
                            <option value="{{ $role->value }}">{{ ucfirst($role->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-gray-500 mb-1">
                    @if($editRole === 'admin')
                        Full access: timesheet, reports, and admin screens.
                    @elseif($editRole === 'manager')
                        Can view reports. Cannot access admin screens.
                    @else
                        Timesheet access only.
                    @endif
                </p>
                @error('editRole')
                    <p class="text-red-600 text-xs mb-1">{{ $message }}</p>
                @enderror
                @if($isSelfEdit)
                    <p class="text-xs text-amber-600 mt-1 mb-4">You cannot change your own role or archive yourself.</p>
                @endif
                <div class="mb-4"></div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Job Title</label>
                    <input wire:model="editRoleTitle" type="text" list="role-titles" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                    <datalist id="role-titles">
                        @foreach($roleTitles as $roleTitle)
                            <option value="{{ $roleTitle }}">
                        @endforeach
                    </datalist>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Teams</label>
                    @if($teams->isNotEmpty())
                        <div class="space-y-2">
                            @foreach($editTeamIds as $index => $teamId)
                                <div wire:key="edit-team-row-{{ $index }}" class="flex items-center gap-2">
                                    <select wire:model="editTeamIds.{{ $index }}" class="schedule-modal-select w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                                        <option value="">No team</option>
                                        @foreach($teams as $team)
                                            <option value="{{ $team->id }}">{{ $team->name }}</option>
                                        @endforeach
                                    </select>
                                    @if(count($editTeamIds) > 1)
                                        <button
                                            type="button"
                                            wire:click="removeEditTeamRow({{ $index }})"
                                            class="shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-500 hover:bg-gray-50 hover:text-gray-700"
                                            aria-label="Remove team"
                                        >
                                            &times;
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                            <button
                                type="button"
                                wire:click="addEditTeamRow"
                                class="inline-flex items-center rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            >
                                + Add another
                            </button>
                        </div>
                    @else
                        <p class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500">
                            Create teams from Admin → Teams.
                        </p>
                    @endif
                    @error('editTeamIds')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    @error('editTeamIds.*')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Employment Type</label>
                    <select wire:model="editIsContractor" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        <option value="0">Employee</option>
                        <option value="1">Contractor</option>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Role / Rate</label>
                    <select wire:model="editRateId" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        <option value="">— None —</option>
                        @foreach($rates as $rate)
                            <option value="{{ $rate->id }}">{{ $rate->label() }}</option>
                        @endforeach
                    </select>
                    @error('editRateId')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="mb-6">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Capacity (hrs/week)</label>
                    <input wire:model="editWeeklyCapacity" type="number" step="0.5" min="0" max="168" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                    @error('editWeeklyCapacity')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="mb-6">
                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Schedule work days</label>
                    <div class="flex overflow-hidden rounded-md border border-gray-300">
                        @foreach([[1,'M'], [2,'T'], [3,'W'], [4,'T'], [5,'F'], [6,'S'], [7,'S']] as [$value, $label])
                            <label class="flex flex-1 cursor-pointer items-center justify-center border-r border-gray-300 px-3 py-2 text-sm last:border-r-0">
                                <input wire:model="editScheduleWorkDays" value="{{ $value }}" type="checkbox" class="sr-only peer">
                                <span class="text-gray-400 peer-checked:font-semibold peer-checked:text-blue-700">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('editScheduleWorkDays')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                </div>

                <div class="border-t border-gray-100 pt-6 mt-6 mb-6">
                    <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-4">Notifications & reporting line</h3>

                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Line manager</label>
                        <select wire:model="editReportsToUserId" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                            <option value="">— None —</option>
                            @foreach($managerCandidates as $candidate)
                                <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ ucfirst($candidate->role->value) }})</option>
                            @endforeach
                        </select>
                        <p class="text-xs text-gray-500 mt-1">If this user finishes below target, their line manager receives the Monday completed-week digest (only when at least one direct report is below target).</p>
                        @error('editReportsToUserId')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="mb-4">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Pause notifications until</label>
                        <input wire:model="editNotificationsPausedUntil" type="date" class="w-full border border-gray-300 rounded-md text-sm px-3 py-2">
                        <p class="text-xs text-gray-500 mt-1">Use during holidays/long absences. Reminders resume the day <strong>after</strong> this date.</p>
                        @error('editNotificationsPausedUntil')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>

                    <div class="flex gap-6 mb-4">
                        <label class="flex items-center gap-3 text-sm cursor-pointer">
                            <input wire:model="editEmailNotificationsEnabled" type="checkbox" class="rounded"> Email reminders
                        </label>
                        <label class="flex items-center gap-3 text-sm cursor-pointer">
                            <input wire:model="editSlackNotificationsEnabled" type="checkbox" class="rounded"> Slack DMs
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">Slack user ID</label>
                        <div class="px-3 py-2 bg-gray-50 border border-gray-200 rounded-md text-sm text-gray-500 font-mono">{{ $editSlackUserId ?: '— not resolved yet —' }}</div>
                        <p class="text-xs text-gray-500 mt-1">Resolved automatically by the nightly Slack sync.</p>
                    </div>
                </div>

                <div class="flex gap-2 mt-6">
                    <button wire:click="save" class="flex-1 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700">Save changes</button>
                    <button wire:click="cancel" class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Cancel</button>
                </div>
            </div>
        </div>
    @endif

    @if($confirmingArchiveId !== null)
        @php $archiveTarget = $users->firstWhere('id', $confirmingArchiveId); @endphp
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
            wire:click="cancelArchive"
            x-data
            @keydown.escape.window="$wire.cancelArchive()"
        >
            <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6" @click.stop>
                <h2 class="text-base font-semibold text-gray-900 mb-3">Archive {{ $archiveTarget?->name ?? 'user' }}?</h2>
                <p class="text-sm text-gray-600 mb-2">
                    They will be signed out and removed from all assignee, manager and reporting dropdowns.
                </p>
                <p class="text-sm text-gray-600 mb-6">
                    <strong>Their recorded time entries are preserved</strong> and will continue to appear in historic reports. You can unarchive them later if they return.
                </p>
                <div class="flex gap-2 justify-end">
                    <button wire:click="cancelArchive" class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Cancel</button>
                    <button wire:click="archive" class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700">Archive user</button>
                </div>
            </div>
        </div>
    @endif
</div>
