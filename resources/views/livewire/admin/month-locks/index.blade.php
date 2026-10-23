<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Month locks</h1>
        <p class="text-sm text-gray-500 mt-1">
            Lock a client's month once its time report has been issued. Locked entries can't be added, changed, moved or deleted,
            and the hours issued are recorded at the time of locking. A lock can't be undone; to correct an entry, open an
            override for {{ \App\Domain\TimeTracking\MonthLockService::OVERRIDE_MINUTES }} minutes. Every change made under an override is logged.
        </p>
    </div>

    @if($confirmation)
        <div class="mb-4 px-4 py-2 rounded bg-blue-50 border border-blue-200 text-sm text-blue-800">
            {{ $confirmation }}
        </div>
    @endif

    {{-- Lock a month --}}
    <div class="bg-white rounded-lg border border-gray-200 p-4 mb-6">
        <h2 class="text-sm font-semibold text-gray-700 mb-3">Lock a month</h2>
        <div class="grid grid-cols-6 gap-3 mb-4">
            <div class="col-span-2">
                <label for="month-lock-month" class="block text-xs font-medium text-gray-600 mb-1">Month</label>
                <input id="month-lock-month" wire:model="month" type="month" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                @error('month')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="col-span-2">
                <label for="month-lock-issued-on" class="block text-xs font-medium text-gray-600 mb-1">Report issued on</label>
                <input id="month-lock-issued-on" wire:model="issuedOn" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                @error('issued_on')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <fieldset class="mb-4">
            <legend class="block text-xs font-medium text-gray-600 mb-1">Clients</legend>
            <div class="grid grid-cols-3 gap-x-4 gap-y-1">
                @foreach($clients as $client)
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model="clientIds" value="{{ $client->id }}" class="rounded">
                        <span>{{ $client->name }}@if($client->is_archived) <span class="text-xs text-gray-400">(archived)</span>@endif</span>
                    </label>
                @endforeach
            </div>
            @error('clientIds')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            @error('clientIds.*')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
        </fieldset>

        <button type="button"
                wire:click="lock"
                wire:confirm="Lock the ticked clients for this month? This can't be undone."
                class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
            Lock month
        </button>
    </div>

    {{-- Locked months --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Client</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Month</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Issued on</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Locked by</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600">Issued hours</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">Override</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($locks as $lock)
                    <tr wire:key="lock-{{ $lock->id }}" class="hover:bg-gray-50 align-top">
                        <td class="px-3 py-2">{{ $lock->client->name }}</td>
                        <td class="px-3 py-2">{{ $lock->label() }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ $lock->issued_on->format('j M Y') }}</td>
                        <td class="px-3 py-2 text-gray-500">{{ $lock->lockedBy->name }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ \App\Domain\TimeTracking\HoursFormatter::asReportDecimal((float) $lock->snapshots_sum_hours) }}</td>
                        <td class="px-3 py-2">
                            @forelse($lock->overrides as $override)
                                <div class="flex items-center gap-2 mb-1 last:mb-0">
                                    <span class="text-gray-700">
                                        {{ $override->user->name }} until <span class="tabular-nums">{{ $override->expires_at->format('j M, H:i') }}</span>
                                        <span class="block text-xs text-gray-400">{{ $override->reason }}</span>
                                    </span>
                                    <button type="button" wire:click="closeOverride({{ $override->id }})" class="text-xs text-red-600 hover:text-red-800 whitespace-nowrap">Close override</button>
                                </div>
                            @empty
                                <span class="text-gray-400">None</span>
                            @endforelse
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <button type="button" wire:click="startOverride({{ $lock->id }})" class="text-sm text-blue-600 hover:text-blue-800">Open override</button>
                        </td>
                    </tr>
                    @if($overrideLockId === $lock->id)
                        <tr wire:key="lock-{{ $lock->id }}-override-form" class="bg-gray-50">
                            <td colspan="7" class="px-3 py-3">
                                <label for="month-lock-override-reason" class="block text-xs font-medium text-gray-600 mb-1">
                                    Reason for overriding {{ $lock->client->name }}, {{ $lock->label() }}
                                    (at least {{ \App\Domain\TimeTracking\MonthLockService::MIN_REASON_LENGTH }} characters)
                                </label>
                                <div class="flex items-start gap-3">
                                    <div class="flex-1">
                                        <input id="month-lock-override-reason" wire:model="overrideReason" wire:keydown.enter="openOverride" type="text"
                                               class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                                        @error('reason')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                                    </div>
                                    <button type="button" wire:click="openOverride"
                                            class="px-4 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 whitespace-nowrap">
                                        Start {{ \App\Domain\TimeTracking\MonthLockService::OVERRIDE_MINUTES }}-minute override
                                    </button>
                                    <button type="button" wire:click="$set('overrideLockId', null)"
                                            class="px-4 py-1.5 text-sm text-gray-600 hover:text-gray-900">
                                        Cancel
                                    </button>
                                </div>
                                <p class="text-xs text-gray-500 mt-2">The override covers only you. Every change you make while it is open is logged.</p>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400 text-sm">No months have been locked yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
