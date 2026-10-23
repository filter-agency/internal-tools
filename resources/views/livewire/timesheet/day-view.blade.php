<div
    x-data="{
        showEntryModal: false,
        showCalendarPanel: false,
        calendarAvailable: {{ $calendarError === null && ! $isImpersonating ? 'true' : 'false' }},
        init() {
            this.$watch('$wire.showModal', (open) => {
                if (open) this.showEntryModal = true;
            });
        },
        openNewEntry() {
            this.showEntryModal = true;
            this.showCalendarPanel = this.calendarAvailable;
            $wire.set('editingEntryId', null, false);
            $wire.set('selectedProjectId', $wire.lastSavedProjectId ?? null, false);
            $wire.set('selectedTaskId', $wire.lastSavedTaskId ?? null, false);
            $wire.set('selectedAsanaTaskGid', '', false);
            $wire.set('hoursInput', '', false);
            $wire.set('notes', '', false);
            $wire.set('entryDate', $wire.selectedDate, false);
            $wire.set('lastCalendarPullTitle', null, false);
        },
        closeNewEntry() {
            this.showEntryModal = false;
            this.showCalendarPanel = false;
            $wire.set('showModal', false, false);
            $wire.set('editingEntryId', null, false);
            $wire.set('selectedProjectId', null, false);
            $wire.set('selectedTaskId', null, false);
            $wire.set('selectedAsanaTaskGid', '', false);
            $wire.set('hoursInput', '', false);
            $wire.set('notes', '', false);
            $wire.set('entryDate', $wire.selectedDate, false);
            $wire.set('lastCalendarPullTitle', null, false);
        },
        closeEntry() {
            if ($wire.editingEntryId) {
                $wire.closeModal();
                return;
            }

            this.closeNewEntry();
        },
    }"
    {{-- Global 'n' shortcut opens a new time entry. Guard against firing while
         the user is typing into a form field — otherwise typing the letter 'n'
         in the notes textarea (etc.) re-opens the modal and resets the form. --}}
    @day-entry-timer-started.window="closeNewEntry()"
    @keydown.n.window="if (!['INPUT','TEXTAREA','SELECT'].includes($event.target.tagName) && !$event.target.isContentEditable) openNewEntry()"
>
    @if($isImpersonating)
        <div class="mb-4 px-4 py-3 bg-amber-50 border border-amber-200 rounded-lg flex items-center justify-between">
            <div class="text-sm text-amber-900">
                <span class="font-semibold">Editing timesheet for {{ $viewedUser->name }}</span>
                <span class="text-amber-700 ml-2">({{ $viewedUser->email }})</span>
            </div>
            <a href="{{ $backUrl }}" class="text-sm text-amber-900 hover:underline">← {{ $backLabel }}</a>
        </div>
    @elseif($isReadOnly)
        <div class="mb-4 px-4 py-3 bg-blue-50 border border-blue-200 rounded-lg flex items-center justify-between">
            <div class="text-sm text-blue-900">
                <span class="font-semibold">Viewing timesheet for {{ $viewedUser->name }}</span>
                <span class="text-blue-700 ml-2">({{ $viewedUser->email }})</span>
                <span class="text-blue-700 ml-2">— read-only</span>
            </div>
            <a href="{{ $backUrl }}" class="text-sm text-blue-900 hover:underline">← {{ $backLabel }}</a>
        </div>
    @endif

    {{-- Day header --}}
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
            @unless($isReadOnly)
                <button
                    @click="openNewEntry()"
                    class="inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition"
                    title="New entry (N)"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Track time
                </button>
            @endunless
            <div class="inline-flex items-center">
                <button
                    wire:click="previousWeek"
                    class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800 shadow-sm"
                    title="Previous week"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <button
                    wire:click="nextWeek"
                    class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800 shadow-sm ml-1"
                    title="Next week"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>
            <h2 class="text-lg font-semibold text-gray-800">
                {{ \Carbon\Carbon::parse($selectedDate)->format('l, j F Y') }}
            </h2>
        </div>
        <div class="flex items-center gap-2">
            {{-- Date picker — calendar icon opens the browser's native date input --}}
            <div class="relative" x-data>
                <button
                    type="button"
                    @click="$refs.datePicker.showPicker?.() ?? $refs.datePicker.click()"
                    title="Pick a date"
                    style="width:2.25rem; height:2.25rem;"
                    class="inline-flex items-center justify-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-600 rounded-lg transition"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                </button>
                <input
                    x-ref="datePicker"
                    type="date"
                    value="{{ $selectedDate }}"
                    @change="$wire.selectDate($event.target.value)"
                    class="absolute inset-0 opacity-0"
                    style="pointer-events:none;"
                    aria-label="Pick a date"
                />
            </div>

            @if($selectedDate !== \Carbon\Carbon::today()->toDateString())
                <button
                    wire:click="selectDate('{{ \Carbon\Carbon::today()->toDateString() }}')"
                    class="inline-flex items-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition"
                >
                    Today
                </button>
            @endif

            {{-- Day / Week toggle --}}
            @php
                $weekUrl = $isImpersonating
                    ? route('admin.timesheets.user.week', ['user' => $viewedUser, 'date' => $selectedDate])
                    : ($isReadOnly
                        ? route('team.timesheet.week', ['user' => $viewedUser, 'date' => $selectedDate])
                        : route('timesheet.week', ['date' => $selectedDate]));
            @endphp
            <div class="inline-flex bg-gray-100 rounded-lg p-1">
                <span class="px-4 py-1.5 text-sm font-medium bg-white text-gray-900 rounded-md shadow-sm">Day</span>
                <a href="{{ $weekUrl }}"
                   class="px-4 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 rounded-md">Week</a>
            </div>

            @if($teamMembers->isNotEmpty())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button
                        type="button"
                        @click="open = !open"
                        class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition"
                    >
                        Team Timesheets
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div
                        x-show="open"
                        x-cloak
                        x-transition:enter="transition ease-out duration-100"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        class="absolute right-0 top-full mt-1 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1 overflow-y-auto"
                        style="max-height: 320px;"
                    >
                        <div class="px-3 py-1.5 text-xs font-semibold text-gray-400 uppercase tracking-wide">Direct reports</div>
                        @foreach($teamMembers as $member)
                            <a
                                href="{{ route('team.timesheet', $member->id) }}"
                                class="block px-3 py-2 text-sm text-gray-800 hover:bg-gray-50 hover:text-gray-900"
                            >{{ $member->name }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Week strip --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6">
        <div class="grid grid-cols-7 divide-x divide-gray-100">
            @foreach ($weekDays as $day)
                @php
                    $dateStr = $day->toDateString();
                    $isToday = $day->isToday();
                    $isSelected = $dateStr === $selectedDate;
                    $total = $dayTotals[$dateStr] ?? 0;
                @endphp
                <button
                    wire:click="selectDate('{{ $dateStr }}')"
                    class="flex flex-col items-center py-3 px-2 hover:bg-gray-50 transition {{ $isSelected ? 'bg-green-50' : '' }}"
                >
                    <span class="text-xs text-gray-500 uppercase tracking-wide">{{ $day->format('D') }}</span>
                    <span class="mt-1 w-8 h-8 flex items-center justify-center rounded-full text-sm font-medium
                        {{ $isToday ? 'bg-green-600 text-white' : ($isSelected ? 'text-green-700 font-semibold' : 'text-gray-700') }}">
                        {{ $day->format('j') }}
                    </span>
                    <span class="mt-1 text-xs {{ $total > 0 ? 'text-gray-600' : 'text-gray-300' }}">
                        {{ $total > 0 ? \App\Domain\TimeTracking\HoursFormatter::format($total, $hoursFormat) : '–' }}
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    {{-- Entries list --}}
    @if (session('copy_rows_message'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 rounded text-sm text-green-700">
            {{ session('copy_rows_message') }}
        </div>
    @endif
    {{-- Refusals from row actions; the entry modal shows its own copy. --}}
    @error('month_lock')<p x-show="!showEntryModal" class="text-red-600 text-xs mb-2">{{ $message }}</p>@enderror
    @if ($dayEntries->isEmpty())
        <div
            class="rounded-xl bg-gray-100 flex items-center justify-center px-8 text-center"
            style="min-height: 350px"
            x-data="{ song: null }"
            x-init="fetch('/timesheet/song/{{ $selectedDate }}').then(r => r.json()).then(d => song = d)"
        >
            <div x-show="song">
                <p class="text-lg italic text-gray-500" x-text="'&quot;' + (song?.song_name ?? '') + '&quot;'"></p>
                <p class="mt-2 text-sm text-gray-400" x-text="(song?.album ?? '') + ' · ' + (song?.year ?? '') + ' · Depeche Mode'"></p>
            </div>
        </div>
    @else
        <div class="space-y-2">
            @foreach ($dayEntries as $entry)
                @php $entryLock = $lockedClientMonths[\App\Domain\TimeTracking\MonthLockGuard::key($entry->project->client_id, $entry->spent_on)] ?? null; @endphp
                <div class="bg-white rounded-lg border border-gray-200 px-4 py-3 flex items-start gap-4">
                    {{-- Colour band --}}
                    <div class="mt-1 w-1 self-stretch rounded-full flex-shrink-0" style="background-color: {{ $entry->task->colour }}"></div>

                    {{-- Main content --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-baseline gap-2 flex-wrap">
                            <span class="font-semibold text-gray-900">{{ $entry->project->timesheetDisplayName() }}</span>
                            <span class="text-gray-400 text-sm">{{ $entry->project->client->name }}</span>
                            @if ($entryLock)
                                <x-month-lock-badge :lock="$entryLock" />
                            @endif
                        </div>
                        <div class="text-sm text-gray-600 mt-0.5">{{ $entry->task->name }}</div>
                        @if ($entry->asana_task_gid)
                            <a
                                href="https://app.asana.com/0/0/{{ $entry->asana_task_gid }}/f"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-xs text-gray-500 hover:text-[#E1236C] mt-1 group"
                                title="Open in Asana"
                            >
                                <svg class="w-3 h-3 flex-shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                    <path d="M18.78 12.653a4.105 4.105 0 1 0-.001 8.21 4.105 4.105 0 0 0 .001-8.21Zm-13.56 0a4.105 4.105 0 1 0 0 8.211 4.105 4.105 0 0 0 0-8.211Zm10.885-7.582a4.105 4.105 0 1 1-8.21 0 4.105 4.105 0 0 1 8.21 0Z"/>
                                </svg>
                                <span class="truncate">{{ $entry->asanaTask?->name ?? 'Linked Asana task' }}</span>
                                <svg class="w-3 h-3 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                            </a>
                        @endif
                        @if ($entry->notes)
                            <div class="text-xs text-gray-400 mt-1">{{ $entry->notes }}</div>
                        @endif
                    </div>

                    {{-- Hours + running indicator --}}
                    <div class="flex items-center gap-3 flex-shrink-0">
                        @if ($entry->is_running)
                            <span class="inline-flex items-center gap-1 text-green-600 text-sm font-medium">
                                <span class="animate-pulse w-2 h-2 bg-green-500 rounded-full"></span>
                                Running
                            </span>
                        @endif
                        <span class="text-gray-700 font-medium tabular-nums">{{ \App\Domain\TimeTracking\HoursFormatter::format((float) ($entryDisplayHours[$entry->id] ?? $entry->hours), $hoursFormat) }}</span>
                    </div>

                    {{-- Actions --}}
                    @unless($isReadOnly)
                    <div class="flex items-center gap-1 flex-shrink-0">
                        @unless($isImpersonating)
                            @if ($entry->is_running)
                                <button
                                    wire:click="stopTimer({{ $entry->id }})"
                                    @disabled($entryLock)
                                    class="p-1.5 rounded transition {{ $entryLock ? 'text-gray-300 cursor-not-allowed' : 'text-gray-400 hover:text-red-600 hover:bg-red-50' }}"
                                    title="Stop timer"
                                >
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M6 6h12v12H6z"/>
                                    </svg>
                                </button>
                            @else
                                <button
                                    wire:click="startTimer({{ $entry->id }})"
                                    @disabled($entryLock)
                                    class="p-1.5 rounded transition {{ $entryLock ? 'text-gray-300 cursor-not-allowed' : 'text-gray-400 hover:text-green-600 hover:bg-green-50' }}"
                                    title="Start timer"
                                >
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M8 5v14l11-7z"/>
                                    </svg>
                                </button>
                            @endif
                        @endunless
                        <button
                            wire:click="openEditModal({{ $entry->id }})"
                            @disabled($entryLock)
                            class="p-1.5 rounded transition {{ $entryLock ? 'text-gray-300 cursor-not-allowed' : 'text-gray-400 hover:text-blue-600 hover:bg-blue-50' }}"
                            title="Edit"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </button>
                        <button
                            wire:click="deleteEntry({{ $entry->id }})"
                            wire:confirm="Delete this time entry?"
                            @disabled($entryLock)
                            class="p-1.5 rounded transition {{ $entryLock ? 'text-gray-300 cursor-not-allowed' : 'text-gray-400 hover:text-red-600 hover:bg-red-50' }}"
                            title="Delete"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                    @endunless
                </div>
            @endforeach
        </div>
    @endif

    {{-- Day / week totals --}}
    <div class="mt-4 flex items-center justify-between gap-4 text-sm text-gray-500">
        <div>
            @if ($canCopyFromPrior)
                <button
                    wire:click="copyRowsFromMostRecent"
                    class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
                    </svg>
                    Copy rows from most recent timesheet
                </button>
            @endif
        </div>
        <div class="flex gap-6">
            <span>Day: <strong class="text-gray-800">{{ \App\Domain\TimeTracking\HoursFormatter::format((float) $dayTotal, $hoursFormat) }}</strong></span>
            <span>Week: <strong class="text-gray-800">{{ \App\Domain\TimeTracking\HoursFormatter::format((float) $weekTotal, $hoursFormat) }}</strong></span>
        </div>
    </div>


    {{-- ============================================================
         Entry modal
    ============================================================ --}}
    {{-- Keep date-sensitive content mounted so Livewire can refresh the calendar
         panel and title after switching days. Alpine x-if templates retain their
         original server-rendered content across a Livewire morph. --}}
    <div x-cloak x-show="showEntryModal">
    <div>

        {{-- ============================================================
             Calendar sidebar — slides in from the left over the page
        ============================================================ --}}
        <div
            x-show="showCalendarPanel"
            x-transition:enter="transition-transform ease-out duration-300"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition-transform ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full"
            class="fixed inset-y-0 left-0 z-[60] w-80 bg-white shadow-2xl flex flex-col"
            style="display:none"
        >
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <div>
                    <div class="text-xs text-gray-400 uppercase tracking-wide">Calendar events for</div>
                    <div class="font-semibold text-gray-900 text-sm mt-0.5">{{ \Carbon\Carbon::parse($selectedDate)->format('l, j M') }}</div>
                </div>
                <button @click="showCalendarPanel = false" class="text-gray-400 hover:text-gray-600 p-1 rounded transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="overflow-y-auto flex-1 p-4 space-y-2">
                @if ($calendarError === 'no_token')
                    <div class="text-center py-8 px-3">
                        <svg class="w-8 h-8 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        <p class="text-sm text-gray-500 mb-3">Calendar access requires signing in with Google.</p>
                        <a href="{{ route('auth.google') }}" class="text-sm text-blue-600 hover:underline">Re-sign in to grant access →</a>
                    </div>
                @elseif ($calendarError === 'empty' || empty($calendarEvents))
                    <div class="text-center py-8">
                        <p class="text-sm text-gray-400">No events on this day.</p>
                    </div>
                @else
                    @foreach ($calendarEvents as $event)
                        @php
                            $used = in_array(strtolower($event['title']), $usedEventTitles, true);
                            $hoursLabel = $event['hours'] == 1.0 ? '1 hour'
                                : \App\Domain\TimeTracking\HoursFormatter::format((float) $event['hours'], $hoursFormat);
                        @endphp
                        <button
                            @if (! $used) wire:click="pullFromCalendarEvent('{{ addslashes($event['title']) }}', {{ $event['hours'] }})" @endif
                            {{ $used ? 'disabled' : '' }}
                            class="w-full text-left p-3 rounded-lg border transition
                                {{ $used
                                    ? 'border-gray-100 bg-gray-50 cursor-default'
                                    : 'border-gray-200 bg-white hover:border-green-300 hover:bg-green-50 cursor-pointer' }}"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <div class="font-semibold text-sm {{ $used ? 'text-gray-400' : 'text-gray-900' }} leading-snug">
                                    {{ $event['title'] }}
                                </div>
                                @if ($used)
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                    </svg>
                                @endif
                            </div>
                            <div class="text-xs {{ $used ? 'text-gray-400' : 'text-gray-500' }} mt-0.5">
                                {{ $event['start_formatted'] }} – {{ $event['end_formatted'] }}
                            </div>
                            <div class="text-xs {{ $used ? 'text-gray-400' : 'text-gray-500' }}">
                                {{ $hoursLabel }}
                            </div>
                        </button>
                    @endforeach
                @endif
            </div>
        </div>

        {{-- Modal backdrop + centred dialog --}}
        <div
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/40"
            style="padding-top: 22vh"
            @mousedown.self="closeEntry()"
            @keydown.escape.window="closeEntry()"
        >
            <div
                class="bg-white rounded-xl shadow-2xl w-full max-w-lg mx-4"
                x-data="{
                    projectOpen: false,
                    taskOpen: false,
                    asanaTaskOpen: false,
                    asanaTaskSearch: '',
                    projectSearch: '',
                    taskSearch: '',
                    selectedProjectId: $wire.selectedProjectId,
                    selectedTaskId: $wire.selectedTaskId,
                    selectedAsanaTaskGid: $wire.selectedAsanaTaskGid ?? '',
                    liveHoursInput: $wire.hoursInput ?? '',
                    projects: {{ Js::from($projectsForPicker) }},
                    asanaTasksByProject: {},
                    asanaAvailable: {{ $asanaAvailable ? 'true' : 'false' }},
                    init() {
                        // $wire is the source of truth; mirror it into Alpine state on every Livewire-driven change
                        // (calendar pull, edit modal, calendar association auto-fill, etc.) so Alpine never clobbers.
                        this.$watch('$wire.selectedProjectId', v => this.selectedProjectId = v);
                        this.$watch('$wire.selectedTaskId', v => this.selectedTaskId = v);
                        this.$watch('$wire.selectedAsanaTaskGid', v => this.selectedAsanaTaskGid = v ?? '');
                        this.$watch('$wire.hoursInput', v => this.liveHoursInput = v ?? '');

                        this.$watch('$wire.showModal', (open) => {
                            if (open) {
                                this.projectOpen = false;
                                this.taskOpen = false;
                                this.asanaTaskOpen = false;
                                this.projectSearch = '';
                                this.taskSearch = '';
                                this.asanaTaskSearch = '';
                            }
                        });
                    },
                    get selectedProject() {
                        return this.projects.find(p => p.id === this.selectedProjectId) ?? null;
                    },
                    get selectedProjectLabel() {
                        return this.selectedProject ? (this.selectedProject.display_name ?? this.selectedProject.name) : '';
                    },
                    get selectedTask() {
                        return this.selectedProject?.tasks.find(t => t.id === this.selectedTaskId) ?? null;
                    },
                    get selectedTaskLabel() {
                        return this.selectedTask?.name ?? '';
                    },
                    get filteredTasks() {
                        const tasks = this.selectedProject?.tasks ?? [];
                        const q = this.taskSearch.toLowerCase();
                        const filtered = q
                            ? tasks.filter(t => t.name.toLowerCase().includes(q))
                            : tasks;

                        return [...filtered].sort((a, b) => a.name.localeCompare(b.name));
                    },
                    get filteredBillableTasks() {
                        return this.filteredTasks.filter(t => t.is_billable);
                    },
                    get filteredNonBillableTasks() {
                        return this.filteredTasks.filter(t => !t.is_billable);
                    },
                    get asanaBoardGids() {
                        return this.selectedProject?.asana_project_gids ?? [];
                    },
                    get asanaTaskMatchTerms() {
                        return this.selectedProject?.asana_task_match_terms ?? [];
                    },
                    get asanaRequired() {
                        if (this.asanaBoardGids.length === 0) return false;
                        if ($wire.lastCalendarPullTitle) return false;
                        return this.selectedProject?.asana_task_required ?? true;
                    },
                    get linkedAsanaTasks() {
                        return this.asanaTasksByProject[this.selectedProjectId] ?? [];
                    },
                    get asanaTasks() {
                        return this.filterAsanaTasks('');
                    },
                    get filteredAsanaTasks() {
                        return this.filterAsanaTasks(this.asanaTaskSearch);
                    },
                    filterAsanaTasks(query) {
                        const filter = window.asanaTaskFilter?.filterAsanaTasksForProject;

                        if (filter) {
                            return filter(this.linkedAsanaTasks, this.asanaTaskMatchTerms, query);
                        }

                        const q = (query ?? '').toLowerCase();
                        if (!q) return this.linkedAsanaTasks;

                        return this.linkedAsanaTasks.filter(t =>
                            t.name.toLowerCase().includes(q) ||
                            (t.board_name ?? '').toLowerCase().includes(q)
                        );
                    },
                    get showAsanaBoardLabel() {
                        return this.asanaBoardGids.length > 1;
                    },
                    get selectedAsanaTask() {
                        return this.linkedAsanaTasks.find(t => t.gid === this.selectedAsanaTaskGid) ?? null;
                    },
                    get selectedAsanaTaskLabel() {
                        return this.selectedAsanaTask?.name ?? '';
                    },
                    get groupedProjects() {
                        const q = this.projectSearch.toLowerCase();
                        const filtered = q
                            ? this.projects.filter(p =>
                                (p.display_name ?? p.name).toLowerCase().includes(q) ||
                                (p.code ?? '').toLowerCase().includes(q) ||
                                p.client_name.toLowerCase().includes(q)
                            )
                            : this.projects;
                        const groups = {};
                        filtered.forEach(p => { (groups[p.client_name] ??= []).push(p); });
                        return Object.entries(groups).sort(([a],[b]) => a.localeCompare(b));
                    },
                    openProjectPicker() {
                        const wasOpen = this.projectOpen;
                        this.projectOpen = true;
                        this.taskOpen = false;
                        this.asanaTaskOpen = false;
                        if (!wasOpen) this.projectSearch = '';
                    },
                    closeProjectPicker() {
                        this.projectOpen = false;
                        this.projectSearch = '';
                    },
                    toggleProjectPicker() {
                        this.projectOpen ? this.closeProjectPicker() : this.openProjectPicker();
                    },
                    searchProjects(value) {
                        this.projectSearch = value;
                        this.projectOpen = true;
                        this.taskOpen = false;
                        this.asanaTaskOpen = false;
                    },
                    openAsanaTaskPicker() {
                        const wasOpen = this.asanaTaskOpen;
                        this.asanaTaskOpen = true;
                        this.projectOpen = false;
                        this.taskOpen = false;
                        if (!wasOpen) this.asanaTaskSearch = '';
                        this.loadAsanaTasks();
                    },
                    closeAsanaTaskPicker() {
                        this.asanaTaskOpen = false;
                        this.asanaTaskSearch = '';
                    },
                    toggleAsanaTaskPicker() {
                        this.asanaTaskOpen ? this.closeAsanaTaskPicker() : this.openAsanaTaskPicker();
                    },
                    searchAsanaTasks(value) {
                        this.asanaTaskSearch = value;
                        this.asanaTaskOpen = true;
                        this.projectOpen = false;
                        this.taskOpen = false;
                    },
                    async loadAsanaTasks() {
                        const projectId = this.selectedProjectId;
                        if (!projectId || this.asanaBoardGids.length === 0 || projectId in this.asanaTasksByProject) return;

                        this.asanaTasksByProject[projectId] = await $wire.loadAsanaTasksForProject(projectId);
                    },
                    openTaskPicker() {
                        if (!this.selectedProjectId) return;
                        const wasOpen = this.taskOpen;
                        this.taskOpen = true;
                        this.projectOpen = false;
                        this.asanaTaskOpen = false;
                        if (!wasOpen) this.taskSearch = '';
                    },
                    closeTaskPicker() {
                        this.taskOpen = false;
                        this.taskSearch = '';
                    },
                    toggleTaskPicker() {
                        if (!this.selectedProjectId) return;
                        this.taskOpen ? this.closeTaskPicker() : this.openTaskPicker();
                    },
                    searchTasks(value) {
                        if (!this.selectedProjectId) return;
                        this.taskSearch = value;
                        this.taskOpen = true;
                        this.projectOpen = false;
                        this.asanaTaskOpen = false;
                    },
                    closePickers() {
                        this.projectOpen = false;
                        this.taskOpen = false;
                        this.asanaTaskOpen = false;
                    },
                    pickProject(id) {
                        this.selectedProjectId = id;
                        this.selectedTaskId = null;
                        this.selectedAsanaTaskGid = '';
                        this.projectSearch = '';
                        this.taskSearch = '';
                        this.closePickers();
                        $wire.set('selectedProjectId', id, false);
                        $wire.set('selectedTaskId', null, false);
                        $wire.set('selectedAsanaTaskGid', '', false);
                    },
                    pickTask(id) {
                        this.selectedTaskId = id;
                        this.taskSearch = '';
                        this.closePickers();
                        $wire.set('selectedTaskId', id, false);
                    },
                    pickAsanaTask(gid) {
                        this.selectedAsanaTaskGid = gid;
                        this.asanaTaskSearch = '';
                        this.closePickers();
                        $wire.set('selectedAsanaTaskGid', gid, false);
                    },
                    doSave(isTimer) {
                        isTimer ? $wire.startTimerFromModal() : $wire.save();
                    },
                    get isTimerMode() {
                        return (this.liveHoursInput ?? '').trim() === '';
                    }
                }"
                @click.stop
            >
                {{-- Modal header --}}
                <div class="px-6 py-4 border-b border-gray-100 text-center relative">
                    <h3 class="font-semibold text-gray-900 text-base">
                        @if ($editingEntryId)
                            Edit entry
                        @else
                            New time entry for {{ \Carbon\Carbon::parse($selectedDate)->format('l, j M') }}
                        @endif
                    </h3>
                    <button
                        type="button"
                        @click="closeEntry()"
                        aria-label="Close"
                        class="absolute top-3 right-3 p-1.5 rounded-full text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition focus:outline-none focus:ring-2 focus:ring-green-500"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-5 space-y-3">

                    {{-- Project / Task label --}}
                    <div class="text-sm font-semibold text-gray-700">Project / Task</div>

                    {{-- Project dropdown. wire:ignore: this picker's options are
                         Alpine x-for clones over client-side data; keeping Livewire's
                         morph out of it stops any round-trip from orphaning those
                         clones (dead option clicks until a hard refresh). --}}
                    <div wire:ignore class="relative z-30" @click.outside="closeProjectPicker()">
                        <div class="relative">
                            <input
                                type="text"
                                autocomplete="off"
                                data-bwignore="true"
                                :value="projectOpen ? projectSearch : selectedProjectLabel"
                                @focus="openProjectPicker()"
                                @click="openProjectPicker()"
                                @input="searchProjects($event.target.value)"
                                @keydown.escape.stop="closeProjectPicker(); $event.target.blur()"
                                @keydown.enter.prevent
                                :placeholder="projectOpen ? 'Search projects…' : 'Select a project…'"
                                role="combobox"
                                aria-autocomplete="list"
                                :aria-expanded="projectOpen.toString()"
                                :class="projectOpen ? 'cursor-text' : 'cursor-pointer'"
                                class="w-full border border-gray-300 rounded-lg bg-white px-4 py-3 pr-11 text-sm text-gray-900 hover:border-gray-400 transition focus:outline-none focus:ring-2 focus:ring-green-500 placeholder-gray-400"
                            />
                            <button
                                type="button"
                                @click.stop="toggleProjectPicker()"
                                aria-label="Toggle project picker"
                                class="absolute right-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-50 hover:text-gray-600 focus:outline-none"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                        </div>

                        <div
                            x-show="projectOpen"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            class="absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg"
                            style="display: none"
                        >
                            <div class="max-h-60 overflow-y-auto py-1">
                                <template x-if="groupedProjects.length === 0">
                                    <p class="text-sm text-gray-400 px-3 py-4 text-center">No projects found.</p>
                                </template>
                                <template x-for="[clientName, projects] in groupedProjects" :key="clientName">
                                    <div>
                                        <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-3 py-1.5 mt-1" x-text="clientName"></div>
                                        <template x-for="project in projects" :key="project.id">
                                            <button
                                                type="button"
                                                @click="pickProject(project.id)"
                                                class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition"
                                                x-text="project.display_name ?? project.name"
                                            ></button>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Asana task picker — shown whenever the project has linked boards.
                         Required vs optional is gated by selectedProject.asana_task_required (see asanaRequired). --}}
                    <template x-if="asanaBoardGids.length > 0">
                        <div wire:ignore class="relative z-20" @click.outside="closeAsanaTaskPicker()">
                            <template x-if="!asanaRequired">
                                <p class="text-xs text-gray-500 mb-1">Asana task (optional)</p>
                            </template>

                            <template x-if="!asanaAvailable">
                                <div class="border border-yellow-200 bg-yellow-50 rounded-lg px-3 py-2 text-xs text-yellow-800">
                                    This project is linked to Asana, but no admin has connected the integration yet. Time can't be logged on it until they do.
                                </div>
                            </template>

                            <template x-if="asanaAvailable">
                                <div>
                                    <div class="flex items-stretch gap-2">
                                        <div class="relative min-w-0 flex-1">
                                            <input
                                                type="text"
                                                autocomplete="off"
                                                data-bwignore="true"
                                                :value="asanaTaskOpen ? asanaTaskSearch : selectedAsanaTaskLabel"
                                                @focus="openAsanaTaskPicker()"
                                                @click="openAsanaTaskPicker()"
                                                @input="searchAsanaTasks($event.target.value)"
                                                @keydown.escape.stop="closeAsanaTaskPicker(); $event.target.blur()"
                                                @keydown.enter.prevent
                                                :placeholder="asanaTaskOpen ? 'Search Asana tasks…' : (asanaRequired ? 'Select an Asana task…' : 'No Asana task')"
                                                role="combobox"
                                                aria-autocomplete="list"
                                                :aria-expanded="asanaTaskOpen.toString()"
                                                :class="asanaTaskOpen ? 'cursor-text' : 'cursor-pointer'"
                                                class="w-full border border-gray-300 rounded-lg bg-white px-4 py-2.5 pr-11 text-sm text-gray-900 hover:border-gray-400 transition focus:outline-none focus:ring-2 focus:ring-green-500 placeholder-gray-400"
                                            />
                                            <button
                                                type="button"
                                                @click.stop="toggleAsanaTaskPicker()"
                                                aria-label="Toggle Asana task picker"
                                                class="absolute right-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-md text-gray-400 transition hover:bg-gray-50 hover:text-gray-600 focus:outline-none"
                                            >
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="refreshSelectedProjectAsanaTasks"
                                            @click.stop="openAsanaTaskPicker()"
                                            class="inline-flex flex-shrink-0 items-center justify-center rounded-lg border border-blue-200 bg-white px-4 text-sm font-medium text-blue-700 transition hover:bg-blue-50 focus:outline-none focus:ring-2 focus:ring-green-500"
                                        >
                                            Refresh
                                        </button>
                                    </div>
                                    <div
                                        x-show="asanaTaskOpen"
                                        class="absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg"
                                        style="display: none"
                                    >
                                        @if(session('asana_task_refresh_message'))
                                            <p class="px-3 py-2 text-xs text-green-700 bg-green-50 border-b border-green-100">
                                                {{ session('asana_task_refresh_message') }}
                                            </p>
                                        @endif
                                        <div class="max-h-60 overflow-y-auto py-1">
                                            <template x-if="!asanaRequired">
                                                <button
                                                    type="button"
                                                    @click="pickAsanaTask('')"
                                                    class="w-full text-left px-4 py-2 text-sm text-gray-500 italic hover:bg-gray-50 transition"
                                                >— No Asana task —</button>
                                            </template>
                                            <template x-if="filteredAsanaTasks.length === 0">
                                                <p class="text-sm text-gray-400 px-3 py-4 text-center">
                                                    <template x-if="linkedAsanaTasks.length === 0">
                                                        <span>No Asana tasks cached for this project. Try Refresh, then reopen this picker in a minute.</span>
                                                    </template>
                                                    <template x-if="linkedAsanaTasks.length > 0 && asanaTasks.length === 0 && asanaTaskSearch.trim() === ''">
                                                        <span>No Asana tasks match this project. Search to see all linked board tasks.</span>
                                                    </template>
                                                    <template x-if="linkedAsanaTasks.length > 0 && (asanaTasks.length > 0 || asanaTaskSearch.trim() !== '')">
                                                        <span>No tasks match.</span>
                                                    </template>
                                                </p>
                                            </template>
                                            <template x-for="task in filteredAsanaTasks" :key="task.gid">
                                                <button
                                                    type="button"
                                                    @click="pickAsanaTask(task.gid)"
                                                    class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition"
                                                >
                                                    <div class="truncate" x-text="task.name"></div>
                                                    <template x-if="showAsanaBoardLabel && task.board_name">
                                                        <div class="text-xs text-gray-500 truncate" x-text="task.board_name"></div>
                                                    </template>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                    @error('selectedAsanaTaskGid')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror

                    {{-- Task dropdown. wire:ignore for the same reason as the
                         project picker above: shield the Alpine option clones from
                         Livewire morph so option clicks never go dead. --}}
                    <div wire:ignore class="relative z-10" @click.outside="closeTaskPicker()"
                         data-picker="task" :data-selected="selectedTaskId ?? ''"
                         :data-open="taskOpen ? 'true' : 'false'">
                        <div class="relative">
                            <template x-if="selectedTask && !taskOpen">
                                <span class="absolute left-4 top-1/2 h-2.5 w-2.5 -translate-y-1/2 rounded-full" :style="'background:' + (selectedTask?.colour ?? '')"></span>
                            </template>
                            <input
                                type="text"
                                autocomplete="off"
                                data-bwignore="true"
                                :disabled="!selectedProjectId"
                                :value="taskOpen ? taskSearch : selectedTaskLabel"
                                @focus="openTaskPicker()"
                                @click="openTaskPicker()"
                                @input="searchTasks($event.target.value)"
                                @keydown.escape.stop="closeTaskPicker(); $event.target.blur()"
                                @keydown.enter.prevent
                                :placeholder="taskOpen ? 'Search tasks…' : 'Select a task…'"
                                role="combobox"
                                aria-autocomplete="list"
                                :aria-expanded="taskOpen.toString()"
                                :class="[selectedProjectId ? 'border-gray-300 bg-white text-gray-900 hover:border-gray-400 placeholder-gray-400' : 'border-gray-200 bg-gray-50 text-gray-300 cursor-not-allowed placeholder-gray-300', selectedProjectId ? (taskOpen ? 'cursor-text' : 'cursor-pointer') : '', selectedTask && !taskOpen ? 'pl-9' : 'pl-4']"
                                class="w-full border rounded-lg py-3 pr-11 text-sm transition focus:outline-none focus:ring-2 focus:ring-green-500"
                            />
                            <button
                                type="button"
                                :disabled="!selectedProjectId"
                                @click.stop="toggleTaskPicker()"
                                aria-label="Toggle task picker"
                                :class="selectedProjectId ? 'text-gray-400 hover:bg-gray-50 hover:text-gray-600 cursor-pointer' : 'text-gray-300 cursor-not-allowed'"
                                class="absolute right-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md transition focus:outline-none"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                        </div>

                        <div
                            x-show="taskOpen"
                            x-transition:enter="transition ease-out duration-100"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            class="absolute z-20 w-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg"
                            style="display: none"
                        >
                            <div class="max-h-60 overflow-y-auto py-1">
                                <template x-if="selectedProject">
                                    <div>
                                        <template x-if="(selectedProject?.tasks.length ?? 0) > 0 && filteredTasks.length === 0">
                                            <p class="text-sm text-gray-400 px-3 py-4 text-center">No tasks match.</p>
                                        </template>
                                        <template x-if="filteredBillableTasks.length > 0">
                                            <div>
                                                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-3 py-1.5">Billable</div>
                                                <template x-for="task in filteredBillableTasks" :key="task.id">
                                                    <button type="button" @mousedown.prevent @click="pickTask(task.id)"
                                                        data-task-option :data-task-option="task.id"
                                                        class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-green-50 hover:text-green-700 transition flex items-center gap-2">
                                                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="'background:' + task.colour"></span>
                                                        <span x-text="task.name"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="filteredNonBillableTasks.length > 0">
                                            <div>
                                                <div class="text-xs font-semibold text-gray-400 uppercase tracking-wide px-3 py-1.5">Non-billable</div>
                                                <template x-for="task in filteredNonBillableTasks" :key="task.id">
                                                    <button type="button" @mousedown.prevent @click="pickTask(task.id)"
                                                        data-task-option :data-task-option="task.id"
                                                        class="w-full text-left px-4 py-2 text-sm text-gray-800 hover:bg-gray-50 hover:text-gray-700 transition flex items-center gap-2">
                                                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="'background:' + task.colour"></span>
                                                        <span x-text="task.name"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </template>
                                        <template x-if="(selectedProject?.tasks.length ?? 0) === 0 && selectedProject">
                                            <p class="text-sm text-gray-400 px-3 py-4 text-center">No tasks assigned.</p>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Notes + Time row --}}
                    <div class="flex gap-3 items-stretch">
                        {{-- Deferred wire:model (not .blur): a blur-triggered
                             round trip can morph the modal while a picker
                             dropdown is open, orphaning Alpine template
                             clones. The server only needs these at save. --}}
                        <textarea
                            wire:model="notes"
                            rows="1"
                            placeholder="Notes (optional)"
                            @keydown.enter.prevent="$wire.notes = $event.target.value; doSave({{ $editingEntryId ? 'false' : ($isImpersonating ? 'false' : 'isTimerMode') }})"
                            class="flex-1 border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none placeholder-gray-400"
                        ></textarea>
                        <div class="flex-shrink-0 w-24 flex flex-col">
                            <input
                                type="text"
                                wire:model="hoursInput"
                                @input="liveHoursInput = $event.target.value"
                                @keydown.enter.prevent="$wire.hoursInput = $event.target.value; doSave({{ $editingEntryId ? 'false' : ($isImpersonating ? 'false' : 'isTimerMode') }})"
                                placeholder="0.25"
                                class="w-full h-full border {{ $hoursError ? 'border-red-400' : 'border-gray-300' }} rounded-lg px-3 py-2.5 text-sm text-center tabular-nums focus:outline-none focus:ring-2 focus:ring-green-500 placeholder-gray-400"
                            />
                            @if ($hoursError)
                                <p class="text-red-500 text-xs mt-1 text-center">{{ $hoursError }}</p>
                            @endif
                        </div>
                    </div>
                    @error('month_lock')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror

                </div>

                {{-- Modal footer --}}
                <div class="flex items-center px-6 py-4 border-t border-gray-100">
                    <button
                        @click="doSave({{ $editingEntryId ? 'false' : ($isImpersonating ? 'false' : 'isTimerMode') }})"
                        class="px-5 py-2 text-sm font-semibold bg-green-600 hover:bg-green-700 text-white rounded-full transition"
                        x-text="{{ $editingEntryId || $isImpersonating ? "'Save entry'" : "isTimerMode ? 'Start timer' : 'Save entry'" }}"
                    ></button>
                    <button
                        @click="closeEntry()"
                        class="ml-3 px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded-full transition"
                    >Cancel</button>
                </div>

            </div>

        </div>{{-- end modal backdrop --}}
    </div>{{-- end modal wrapper --}}
    </div>

    {{-- 60-second poll for running timers. Suspended while the entry modal is
         open: a poll morph landing mid-interaction leaves orphaned Alpine
         template clones in the pickers (ghost dropdowns, cleared-looking
         fields), and can reset liveHoursInput so Enter starts a timer. --}}
    <template x-if="!showEntryModal">
        <div wire:poll.60000ms="refreshForTimer" class="hidden"></div>
    </template>
</div>

@script
<script>
    // When this page runs inside the browser extension's iframe overlay on
    // app.asana.com, tell the extension the entry saved so it can close.
    $wire.on('asana-entry-saved', () => {
        if (window.self !== window.top) {
            window.parent.postMessage('filter-log-time:saved', 'https://app.asana.com');
        }
    });
</script>
@endscript
