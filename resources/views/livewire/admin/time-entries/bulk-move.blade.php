<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Bulk move time entries</h1>
        <p class="text-sm text-gray-500 mt-1">
            Filter, select and re-assign time entries from one project/task to another.
            Billing fields are recalculated against the destination at move time; an audit row is written for every change.
        </p>
    </div>

    @if($confirmation)
        <div class="mb-4 px-4 py-2 rounded bg-blue-50 border border-blue-200 text-sm text-blue-800">
            {{ $confirmation }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <div class="grid grid-cols-6 gap-3">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">From</label>
                <input wire:model.live="filterFrom" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">To</label>
                <input wire:model.live="filterTo" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Client</label>
                <select wire:model.live="filterClientId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                    <option value="">Any</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Project</label>
                <select wire:model.live="filterProjectId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                    <option value="">Any</option>
                    @foreach($projects as $project)
                        @if(! $filterClientId || $project->client_id == $filterClientId)
                            <option value="{{ $project->id }}">{{ $project->client->name }} — {{ $project->name }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Task</label>
                <select wire:model.live="filterTaskId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                    <option value="">Any</option>
                    @foreach($tasks as $task)
                        <option value="{{ $task->id }}">{{ $task->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">User</label>
                <select wire:model.live="filterUserId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                    <option value="">Any</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- Selection + Move panel --}}
    <div class="bg-white rounded-lg border border-gray-200 p-4 mb-4">
        <div class="grid grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Move selected to project</label>
                <select wire:model.live="destinationProjectId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                    <option value="">— Select —</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->client->name }} — {{ $project->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-600 mb-1">Move selected to task</label>
                <select wire:model="destinationTaskId" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                    <option value="">— Select —</option>
                    @foreach($destinationTasks as $task)
                        <option value="{{ $task->id }}">{{ $task->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="text-right">
                <button wire:click="move"
                        wire:confirm="Move {{ count($selected) }} entries to the selected destination?"
                        @if(empty($selected) || ! $destinationProjectId || ! $destinationTaskId) disabled @endif
                        class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
                    Move {{ count($selected) }} entries
                </button>
            </div>
        </div>
        @error('month_lock')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
    </div>

    {{-- Entries table --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto mb-6">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 w-8"></th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Date</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">User</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Client</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Project</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Task</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600">Hours</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Notes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($entries as $entry)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2"><input type="checkbox" wire:model.live="selected" value="{{ $entry->id }}" class="rounded"></td>
                        <td class="px-3 py-2 tabular-nums">{{ $entry->spent_on->format('Y-m-d') }}</td>
                        <td class="px-3 py-2">{{ $entry->user?->name }}</td>
                        <td class="px-3 py-2 text-gray-500">{{ $entry->project?->client?->name }}</td>
                        <td class="px-3 py-2">{{ $entry->project?->name }}</td>
                        <td class="px-3 py-2 text-gray-500">{{ $entry->task?->name }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ \App\Domain\TimeTracking\HoursFormatter::asTime((float) $entry->hours) }}</td>
                        <td class="px-3 py-2 text-gray-500 truncate max-w-xs">{{ $entry->notes }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400 text-sm">No entries match the current filters.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="px-4 py-2">
            {{ $entries->links() }}
        </div>
    </div>

    {{-- Recent moves --}}
    @if($recentMoves->isNotEmpty())
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h2 class="text-sm font-semibold text-gray-700 mb-2">Recent moves (last 20 by you)</h2>
            <ul class="text-xs text-gray-500 space-y-1">
                @foreach($recentMoves as $audit)
                    <li>
                        <span class="tabular-nums">{{ $audit->created_at->format('Y-m-d H:i') }}</span>
                        — moved time entry #{{ $audit->time_entry_id }} (project {{ $audit->old_value }} → {{ $audit->new_value }})
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
