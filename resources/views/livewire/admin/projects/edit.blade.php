<div>
    <div class="mb-6 flex items-end justify-between gap-4">
        <div>
            <a href="{{ route('admin.projects') }}" class="text-sm text-gray-500 hover:text-gray-700">← Projects</a>
            <h1 class="text-xl font-semibold text-gray-900 mt-1">{{ $project->name }}</h1>
        </div>
        <button wire:click="save" class="px-5 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700 shrink-0">
            Save project
        </button>
    </div>

    @if(session('status'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 text-green-700 text-sm rounded">{{ session('status') }}</div>
    @endif
    @if(session('asana_warning'))
        <div class="mb-4 px-4 py-2 bg-yellow-50 border border-yellow-200 text-yellow-700 text-sm rounded">{{ session('asana_warning') }}</div>
    @endif

    <div class="space-y-6">
        <div class="space-y-6">
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Project details</h2>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                            <select wire:model="clientId" class="admin-select">
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                                @endforeach
                            </select>
                            @error('client_id')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Project manager / Account lead</label>
                            <select wire:model="managerUserId" class="admin-select">
                                <option value="">— None —</option>
                                @foreach($allUsers as $u)
                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Receives budget alerts alongside admins.</p>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                        <input wire:model="code" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        @error('code')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                        <input wire:model="name" type="text" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        @error('name')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="grid grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Billable</label>
                            <select wire:model="isBillable" class="admin-select">
                                <option value="1">Billable</option>
                                <option value="0">Non-billable</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Starts on</label>
                            <input wire:model="startsOn" type="date" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ends on</label>
                            <input wire:model="endsOn" type="date" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Budget --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <div class="flex items-start justify-between mb-4">
                    <h2 class="text-sm font-semibold text-gray-700">Budget</h2>
                    @if($budgetStatus)
                        <x-budget-usage :status="$budgetStatus" />
                    @endif
                </div>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Budget type</label>
                            <select wire:model.live="budgetType" class="admin-select">
                                <option value="">No budget</option>
                                @foreach($budgetTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                            @error('budgetType')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                {{ $budgetType === 'monthly_ci' ? 'Monthly budget (£/month)' : 'Total fee (£)' }}
                            </label>
                            <input wire:model="budgetAmount" type="number" step="0.01" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                            @error('budgetAmount')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Budget hours (optional)</label>
                            <input wire:model="budgetHours" type="number" step="0.25" min="0" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                        </div>
                        @if($budgetType === 'monthly_ci')
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Budget starts on</label>
                                <input wire:model="budgetStartsOn" type="date" class="w-full border border-gray-300 rounded text-sm px-3 py-2">
                                @error('budgetStartsOn')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Asana --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <div class="flex items-start justify-between mb-4">
                    <h2 class="text-sm font-semibold text-gray-700">Asana boards</h2>
                    @if(count($asanaProjectGids) > 0)
                        <button type="button" wire:click="refreshAsanaTasks" class="px-3 py-1 text-xs font-medium text-blue-700 border border-blue-200 rounded hover:bg-blue-50">
                            Refresh tasks
                        </button>
                    @endif
                </div>

                @if(! $asanaConnected)
                    <p class="text-sm text-gray-500">
                        Connect your Asana account on
                        <a href="{{ route('profile.asana') }}" class="text-blue-700 underline">your profile</a>
                        to link boards.
                    </p>
                @elseif($asanaProjects->isEmpty() && count($asanaProjectGids) === 0)
                    <p class="text-sm text-gray-500">
                        No cached Asana projects yet. Visit
                        <a href="{{ route('admin.integrations.asana') }}" class="text-blue-700 underline">Asana integration</a>
                        and click "Pull projects from my workspace".
                    </p>
                @else
                    @php
                        $linkedBoards = $asanaProjects->whereIn('gid', $asanaProjectGids)->values();
                        $availableBoards = $asanaProjects->whereNotIn('gid', $asanaProjectGids)->values();
                    @endphp

                    <p class="text-xs text-gray-500 mb-3">
                        Add every Asana board whose tasks this project should pull. Time entries can be logged against tasks from any linked board, and cumulative hours sync back to whichever board the task lives on.
                    </p>

                    @if($linkedBoards->isEmpty())
                        <p class="text-sm text-gray-400 py-4 text-center mb-3">No Asana boards linked yet.</p>
                    @else
                        <ul class="space-y-2 mb-4">
                            @foreach($linkedBoards as $board)
                                <li class="flex items-center justify-between bg-blue-50 border border-blue-100 rounded px-3 py-2 text-sm">
                                    <span class="font-medium text-gray-800">{{ $board->name }}</span>
                                    <button type="button"
                                            wire:click="removeAsanaBoard('{{ $board->gid }}')"
                                            class="text-xs text-gray-400 hover:text-red-600 hover:underline">
                                        Remove
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1">
                                {{ $linkedBoards->isEmpty() ? 'Asana board' : 'Add another board' }}
                            </label>
                            <select wire:model.live="pendingAsanaProjectGid"
                                    class="admin-select">
                                <option value="">— Select a board —</option>
                                @foreach($availableBoards as $ap)
                                    <option value="{{ $ap->gid }}">{{ $ap->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button"
                                wire:click="addAsanaBoard"
                                @disabled($pendingAsanaProjectGid === '')
                                class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed">
                            + Add to Project
                        </button>
                    </div>
                    @if($availableBoards->isEmpty() && $linkedBoards->isNotEmpty())
                        <p class="text-xs text-gray-400 mt-2">All cached Asana boards in your workspace are already linked to this project.</p>
                    @endif

                    @error('asanaProjectGids.*')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror
                    @error('asanaProjectGids')<p class="text-red-600 text-xs mt-2">{{ $message }}</p>@enderror

                    @if($linkedBoards->isNotEmpty())
                        <div class="mt-5 pt-4 border-t border-gray-100">
                            <label class="flex items-start gap-3 text-sm cursor-pointer">
                                <input wire:model="asanaTaskRequired" type="checkbox" class="rounded mt-0.5">
                                <span>
                                    <span class="font-medium text-gray-800">Require an Asana task on every time entry</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">
                                        Recommended. Untick for projects where these boards are linked for reference only and not every entry needs to push hours back to a specific task.
                                    </span>
                                </span>
                            </label>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Tasks --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <h2 class="text-sm font-semibold text-gray-700 mb-4">Tasks</h2>
                {{-- Multi-column (not grid) so the alphabetical list fills the
                     left column top-to-bottom before the right (FLTR-2388),
                     rather than snaking left-to-right across both. --}}
                <div class="columns-2 gap-x-6">
                    @foreach($allTasks as $task)
                        @php $assigned = isset($taskAssignments[$task->id]); @endphp
                        <div class="flex items-center gap-3 py-2 border-b border-gray-50 last:border-0 break-inside-avoid">
                            <input
                                type="checkbox"
                                id="task-{{ $task->id }}"
                                {{ $assigned ? 'checked' : '' }}
                                wire:click="toggleTask({{ $task->id }})"
                                class="rounded"
                            >
                            <label for="task-{{ $task->id }}" class="flex-1 text-sm cursor-pointer">
                                <span class="inline-block w-2.5 h-2.5 rounded-full mr-1.5" style="background-color: {{ $task->colour }}"></span>
                                {{ $task->name }}@unless($task->defaultBillableForProject($project))
                                    <span class="text-xs text-gray-400 ml-1">(not billable)</span>
                                @endunless
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Team & Rates --}}
            <div class="bg-white rounded-lg border border-gray-200 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-gray-700">Team &amp; Rates</h2>
                    <button type="button" wire:click="openAddUserModal"
                            class="px-4 py-2 bg-white border border-gray-300 text-gray-700 text-sm font-medium rounded hover:bg-gray-50">
                        + Add user
                    </button>
                </div>

                @php
                    $assignedUsers = $allUsers->filter(fn ($u) => isset($userAssignments[$u->id]))->values();
                    $unassignedUsers = $allUsers->filter(fn ($u) => ! isset($userAssignments[$u->id]) && ! in_array($u->id, $pendingNewUserIds, true))->values();
                    $fallbackLabel = '£'.number_format(\App\Domain\Billing\RateResolver::FALLBACK_HOURLY_RATE, 2).'/hr';
                @endphp

                @if($assignedUsers->isEmpty())
                    <p class="text-sm text-gray-400 py-8 text-center">No team members yet. Click <strong>+ Add user</strong> to assign one.</p>
                @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                                <th class="text-left px-2 py-2 font-medium">Member</th>
                                <th class="text-left px-2 py-2 font-medium">Default role &amp; rate</th>
                                <th class="text-left px-2 py-2 font-medium">Project rate (£/hr)</th>
                                <th class="text-right px-2 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                        @foreach($assignedUsers as $user)
                            @php
                                if ($user->rate_id) {
                                    $libRate = $rates->firstWhere('id', $user->rate_id);
                                    $userDefault = $libRate
                                        ? $libRate->name.' — £'.number_format((float) $libRate->hourly_rate, 2).'/hr'
                                        : $fallbackLabel;
                                } else {
                                    $userDefault = $fallbackLabel;
                                }
                            @endphp
                            <tr>
                                <td class="px-2 py-2 font-medium">{{ $user->name }}</td>
                                <td class="px-2 py-2 text-gray-500">{{ $userDefault }}</td>
                                <td class="px-2 py-2">
                                    <input type="number" step="0.01" min="0"
                                           wire:model="userAssignments.{{ $user->id }}.hourly_rate_override"
                                           placeholder="—"
                                           class="w-28 border border-gray-300 rounded text-sm px-2 py-1.5">
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <button type="button"
                                            wire:click="openRemoveUserModal({{ $user->id }})"
                                            class="text-xs text-gray-400 hover:text-red-600 hover:underline">
                                        Remove from project
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>

            @if($showAddUserModal)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
                     wire:click="closeAddUserModal"
                     x-data
                     @keydown.escape.window="$wire.closeAddUserModal()">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 px-8 py-8" @click.stop>
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-base font-semibold text-gray-900">Add team member</h2>
                            <button wire:click="closeAddUserModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                        </div>

                        @if(! empty($pendingNewUserIds))
                            <ul class="mb-5 space-y-2">
                                @foreach($pendingNewUserIds as $queuedId)
                                    @php $queuedUser = $allUsers->firstWhere('id', $queuedId); @endphp
                                    @if($queuedUser)
                                        <li class="flex items-center justify-between bg-blue-50 border border-blue-100 rounded px-3 py-2 text-sm">
                                            <span>{{ $queuedUser->name }}</span>
                                            <button type="button" wire:click="unqueuePendingUser({{ $queuedId }})"
                                                    class="text-xs text-gray-400 hover:text-red-600">Remove</button>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif

                        <div class="mt-2 mb-6">
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">User</label>
                            <select wire:model.live="pendingNewUserDropdown" class="admin-select">
                                <option value="">— Select a user —</option>
                                @foreach($unassignedUsers as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                                @endforeach
                            </select>
                            @if($unassignedUsers->isEmpty() && empty($pendingNewUserIds))
                                <p class="text-xs text-gray-400 mt-2">All active users are already on this project.</p>
                            @endif
                        </div>

                        <div class="flex items-center justify-between">
                            <button type="button" wire:click="queuePendingUser"
                                    @if(! $pendingNewUserDropdown) disabled @endif
                                    class="text-sm text-blue-600 hover:underline disabled:text-gray-300 disabled:no-underline disabled:cursor-not-allowed">
                                + Add another
                            </button>
                            <div class="flex gap-2">
                                <button wire:click="closeAddUserModal" class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Cancel</button>
                                <button wire:click="confirmAddUsers"
                                        @if(empty($pendingNewUserIds) && ! $pendingNewUserDropdown) disabled @endif
                                        class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                                    Save
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if($confirmRemoveUserId !== null)
                @php $removeUser = $allUsers->firstWhere('id', $confirmRemoveUserId); @endphp
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
                     wire:click="closeRemoveUserModal"
                     x-data
                     @keydown.escape.window="$wire.closeRemoveUserModal()">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 px-8 py-8" @click.stop>
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-base font-semibold text-gray-900">Remove team member</h2>
                            <button wire:click="closeRemoveUserModal" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                        </div>

                        <p class="text-sm text-gray-700 mb-2">
                            Remove <strong>{{ $removeUser?->name }}</strong> from this project?
                        </p>
                        <p class="text-xs text-gray-500 mb-6">
                            Their existing time entries are kept; they just won't be assigned to it any more.
                        </p>

                        <div class="flex justify-end gap-2">
                            <button wire:click="closeRemoveUserModal" class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Cancel</button>
                            <button wire:click="confirmRemoveUser"
                                    class="px-4 py-2 text-white text-sm font-medium rounded-md"
                                    style="background-color: #DC2626;">
                                Remove from project
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            @if($showClearBudgetModal)
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/30"
                     wire:click="keepBudgetFields"
                     x-data
                     @keydown.escape.window="$wire.keepBudgetFields()">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 px-8 py-8" @click.stop>
                        <div class="flex items-center justify-between mb-6">
                            <h2 class="text-base font-semibold text-gray-900">Clear budget values?</h2>
                            <button wire:click="keepBudgetFields" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
                        </div>

                        <p class="text-sm text-gray-700 mb-2">
                            You've changed the budget type. Do you want to clear the existing
                            <strong>Total fee</strong> and <strong>Budget hours</strong> values?
                        </p>
                        <p class="text-xs text-gray-500 mb-6">
                            Choose <strong>Keep values</strong> to leave the fields as they are and edit them yourself.
                        </p>

                        <div class="flex justify-end gap-2">
                            <button wire:click="keepBudgetFields" class="px-4 py-2 bg-white border border-gray-300 text-sm rounded-md hover:bg-gray-50">Keep values</button>
                            <button wire:click="clearBudgetFields"
                                    class="px-4 py-2 text-white text-sm font-medium rounded-md"
                                    style="background-color: #DC2626;">
                                Clear fields
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
