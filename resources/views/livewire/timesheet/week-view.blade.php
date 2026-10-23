<div
    x-data="{
        hasUnsavedWeekChanges: false,
        markWeekDirty() {
            this.hasUnsavedWeekChanges = true;
        },
        clearWeekDirty() {
            this.hasUnsavedWeekChanges = false;
        },
        confirmWeekDayNavigation(event) {
            if (!this.hasUnsavedWeekChanges) return;

            if (!window.confirm('You have unsaved changes on this week. Leave without saving?')) {
                event.preventDefault();
            }
        },
    }"
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

    @if(session('week_saved'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 rounded text-sm text-green-700">Saved.</div>
    @endif
    @if(session('copy_rows_message'))
        <div class="mb-4 px-4 py-2 bg-green-50 border border-green-200 rounded text-sm text-green-700">{{ session('copy_rows_message') }}</div>
    @endif

    {{-- Day header --}}
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-3">
            <div class="inline-flex items-center">
                <button wire:click="previousWeek"
                        class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800 shadow-sm"
                        title="Previous week">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button wire:click="nextWeek"
                        class="flex items-center justify-center w-8 h-8 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 transition text-gray-500 hover:text-gray-800 shadow-sm ml-1"
                        title="Next week">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
            <h2 class="text-lg font-semibold text-gray-800">
                {{ $weekStart->format('j M') }} – {{ $weekStart->addDays(6)->format('j M Y') }}
            </h2>
        </div>
        <div class="flex items-center gap-2">
            {{-- Date picker --}}
            <div class="relative" x-data>
                <button type="button"
                        @click="$refs.datePicker.showPicker?.() ?? $refs.datePicker.click()"
                        title="Pick a date"
                        style="width:2.25rem; height:2.25rem;"
                        class="inline-flex items-center justify-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-600 rounded-lg transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </button>
                <input x-ref="datePicker" type="date" value="{{ $selectedDate }}"
                       @change="$wire.set('selectedDate', $event.target.value)"
                       class="absolute inset-0 opacity-0" style="pointer-events:none;" aria-label="Pick a date"/>
            </div>

            @unless(\Carbon\Carbon::parse($selectedDate)->isSameWeek(\Carbon\Carbon::today()))
                <button wire:click="goToToday"
                        class="inline-flex items-center bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition">
                    This week
                </button>
            @endunless

            {{-- Day / Week toggle --}}
            @php
                $dayUrl = $isImpersonating || $isReadOnly
                    ? route(request()->routeIs('admin.*') ? 'admin.timesheets.user' : 'team.timesheet', ['user' => $viewedUser, 'date' => $selectedDate])
                    : route('timesheet', ['date' => $selectedDate]);
            @endphp
            <div class="inline-flex bg-gray-100 rounded-lg p-1">
                <a href="{{ $dayUrl }}"
                   class="px-4 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 rounded-md">Day</a>
                <span class="px-4 py-1.5 text-sm font-medium bg-white text-gray-900 rounded-md shadow-sm">Week</span>
            </div>

            @if($teamMembers->isNotEmpty())
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" @click="open = !open"
                            class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition">
                        Team Timesheets
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div x-show="open" x-cloak
                         class="absolute right-0 top-full mt-1 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-50 py-1 overflow-y-auto"
                         style="max-height: 320px;">
                        <div class="px-3 py-1.5 text-xs font-semibold text-gray-400 uppercase tracking-wide">Direct reports</div>
                        @foreach($teamMembers as $member)
                            <a href="{{ route('team.timesheet.week', ['user' => $member->id, 'date' => $selectedDate]) }}"
                               class="block px-3 py-2 text-sm text-gray-800 hover:bg-gray-50 hover:text-gray-900">{{ $member->name }}</a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Week table --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-600" style="min-width: 280px;">Project &amp; task</th>
                    @foreach($weekDays as $day)
                        @php
                            $dayDate = $day->toDateString();
                            $headerDayUrl = $isImpersonating || $isReadOnly
                                ? route(request()->routeIs('admin.*') ? 'admin.timesheets.user' : 'team.timesheet', ['user' => $viewedUser, 'date' => $dayDate])
                                : route('timesheet', ['date' => $dayDate]);
                        @endphp
                        <th class="px-2 py-3 text-center font-medium text-gray-600 {{ $day->isToday() ? 'bg-green-50' : '' }}" style="min-width: 80px;">
                            <a
                                href="{{ $headerDayUrl }}"
                                data-week-day-link
                                data-unsaved-week-guard
                                @click="confirmWeekDayNavigation($event)"
                                class="block rounded-md px-2 py-1 transition hover:bg-white hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500"
                            >
                                <div class="text-xs uppercase tracking-wide">{{ $day->format('D') }}</div>
                                <div class="text-sm">{{ $day->format('j M') }}</div>
                            </a>
                        </th>
                    @endforeach
                    <th class="px-2 py-3 text-right font-medium text-gray-600" style="min-width: 70px;">Total</th>
                    @unless($isReadOnly)
                        <th class="px-2 py-3" style="width: 36px;"></th>
                    @endunless
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($rows as $row)
                    @php
                        $rowTotal = 0.0;
                        $cells = $cellValues[$row['key']] ?? $row['cells'];
                        $runningCells = $runningCellHours[$row['key']] ?? [];
                        $cellLocks = [];
                        $rowHasLockedTime = false;
                        for ($i = 0; $i < 7; $i++) {
                            $cellLocks[$i] = $lockedClientMonths[$row['client_id'].'|'.$weekDays[$i]->format('Y-m')] ?? null;
                            // Saved time in a locked month, which removing the row would delete.
                            $rowHasLockedTime = $rowHasLockedTime || ($cellLocks[$i] !== null && trim((string) ($row['cells'][$i] ?? '')) !== '');
                            $raw = trim((string) ($cells[$i] ?? ''));
                            $hours = 0.0;
                            if ($raw !== '') {
                                try { $hours = \App\Domain\TimeTracking\HoursParser::parse($raw); } catch (\InvalidArgumentException) {}
                            }
                            $rowTotal += max($hours, (float) ($runningCells[$i] ?? 0.0));
                        }
                    @endphp
                    <tr wire:key="week-row-{{ $row['key'] }}" class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $row['project_name'] }}
                                @if($row['client_name'])
                                    <span class="text-xs text-gray-400 ml-1">({{ $row['client_name'] }})</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5">{{ $row['task_name'] }}</div>
                            @if(! empty($row['asana_task_name']))
                                <div class="text-xs text-gray-400 mt-0.5 italic truncate" title="{{ $row['asana_task_name'] }}">↳ {{ $row['asana_task_name'] }}</div>
                            @endif
                        </td>
                        @for($i = 0; $i < 7; $i++)
                            <td class="px-2 py-2 {{ $weekDays[$i]->isToday() ? 'bg-green-50' : '' }}">
                                @if($isReadOnly)
                                    <div class="text-center text-sm text-gray-700 tabular-nums">
                                        {{ trim((string) ($cells[$i] ?? '')) ?: '—' }}
                                    </div>
                                @else
                                    <input type="text"
                                           wire:key="week-cell-{{ $row['key'] }}-{{ $i }}"
                                           wire:model.live.blur="cellValues.{{ $row['key'] }}.{{ $i }}"
                                           value="{{ $cells[$i] ?? '' }}"
                                           placeholder="—"
                                           @input="markWeekDirty()"
                                           @change="markWeekDirty()"
                                           @disabled($cellLocks[$i])
                                           class="w-full text-center text-sm tabular-nums border border-gray-200 rounded px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent {{ $cellLocks[$i] ? 'bg-gray-50 text-gray-400 cursor-not-allowed' : '' }}">
                                @endif
                                @if($cellLocks[$i])
                                    <div class="mt-1 text-center"><x-month-lock-badge :lock="$cellLocks[$i]" /></div>
                                @endif
                            </td>
                        @endfor
                        <td class="px-2 py-3 text-right text-sm font-medium tabular-nums text-gray-900">
                            {{ $rowTotal > 0 ? \App\Domain\TimeTracking\HoursFormatter::format((float) $rowTotal, $hoursFormat) : '—' }}
                        </td>
                        @unless($isReadOnly)
                            <td class="px-2 py-3 text-center">
                                <button wire:click="removeRow('{{ $row['key'] }}')"
                                        wire:confirm="Remove this row? Any time logged against it this week will be deleted."
                                        @disabled($rowHasLockedTime)
                                        class="transition {{ $rowHasLockedTime ? 'text-gray-200 cursor-not-allowed' : 'text-gray-300 hover:text-red-600' }}" title="Remove row">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </td>
                        @endunless
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $isReadOnly ? 9 : 10 }}" class="px-4 py-12 text-center text-gray-400 text-sm">
                            No time logged this week.
                            @unless($isReadOnly)
                                Click <strong>+ Add row</strong> to start.
                            @endunless
                        </td>
                    </tr>
                @endforelse
            </tbody>
            <tfoot class="bg-gray-50 border-t border-gray-200">
                <tr>
                    <td class="px-4 py-3 text-sm font-medium text-gray-700">Daily totals</td>
                    @for($i = 0; $i < 7; $i++)
                        <td class="px-2 py-3 text-center text-sm font-medium tabular-nums text-gray-900 {{ $weekDays[$i]->isToday() ? 'bg-green-100' : '' }}">
                            {{ $dayTotals[$i] > 0 ? \App\Domain\TimeTracking\HoursFormatter::format((float) $dayTotals[$i], $hoursFormat) : '—' }}
                        </td>
                    @endfor
                    <td class="px-2 py-3 text-right text-sm font-semibold tabular-nums text-gray-900">
                        {{ $weekTotal > 0 ? \App\Domain\TimeTracking\HoursFormatter::format((float) $weekTotal, $hoursFormat) : '—' }}
                    </td>
                    @unless($isReadOnly)
                        <td></td>
                    @endunless
                </tr>
            </tfoot>
        </table>
    </div>

    @unless($isReadOnly)
        <div class="mt-4 flex items-center gap-3">
            @if($canCopyRowsFromPriorWeek)
                <button wire:click="copyRowsFromMostRecentWeek"
                        class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/>
                    </svg>
                    Copy rows from most recent week
                </button>
            @endif
            <button wire:click="openAddRowModal"
                    class="inline-flex items-center gap-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-medium px-4 py-2 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Add row
            </button>
            <button type="button"
                    @click="$wire.save().then((saved) => { if (saved) clearWeekDirty(); })"
                    class="inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                Save
            </button>
            @error('month_lock')<p class="text-red-600 text-xs">{{ $message }}</p>@enderror
        </div>
    @endunless

    {{-- Add row modal — mirrors the Day view's Track Time modal --}}
    <div x-show="$wire.showAddRowModal" style="display:none">
        <div
            class="fixed inset-0 z-50 flex items-start justify-center bg-black/40"
            style="padding-top: 22vh"
            @click.self="$wire.closeAddRowModal()"
            @keydown.escape.window="$wire.closeAddRowModal()"
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
                    selectedProjectId: $wire.newRowProjectId,
                    selectedTaskId: $wire.newRowTaskId,
                    selectedAsanaTaskGid: $wire.newRowAsanaTaskGid ?? '',
                    projects: {{ Js::from($projectsForPicker) }},
                    asanaTasksByProject: {},
                    asanaAvailable: {{ $asanaAvailable ? 'true' : 'false' }},
                    init() {
                        this.$watch('$wire.newRowProjectId', v => this.selectedProjectId = v);
                        this.$watch('$wire.newRowTaskId', v => this.selectedTaskId = v);
                        this.$watch('$wire.newRowAsanaTaskGid', v => this.selectedAsanaTaskGid = v ?? '');

                        this.$watch('$wire.showAddRowModal', (open) => {
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
                                (p.client_name ?? '').toLowerCase().includes(q)
                            )
                            : this.projects;
                        const groups = {};
                        filtered.forEach(p => { (groups[p.client_name || '—'] ??= []).push(p); });
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
                    // Picker syncs are deferred ($wire.set third arg false):
                    // a live set fires an immediate round trip whose morph can
                    // land while a dropdown is open, orphaning Alpine template
                    // clones (ghost/empty dropdowns). The server only needs
                    // these values on the next action (Save row / Refresh),
                    // and deferred updates flush with that request.
                    pickProject(id) {
                        this.selectedProjectId = id;
                        this.selectedTaskId = null;
                        this.selectedAsanaTaskGid = '';
                        this.projectSearch = '';
                        this.taskSearch = '';
                        this.closePickers();
                        $wire.set('newRowProjectId', id, false);
                        $wire.set('newRowTaskId', null, false);
                        $wire.set('newRowAsanaTaskGid', '', false);
                    },
                    pickTask(id) {
                        this.selectedTaskId = id;
                        this.taskSearch = '';
                        this.closePickers();
                        $wire.set('newRowTaskId', id, false);
                    },
                    pickAsanaTask(gid) {
                        this.selectedAsanaTaskGid = gid;
                        this.asanaTaskSearch = '';
                        this.closePickers();
                        $wire.set('newRowAsanaTaskGid', gid, false);
                    },
                }"
                @click.stop
            >
                {{-- Modal header --}}
                <div class="px-6 py-4 border-b border-gray-100 text-center relative">
                    <h3 class="font-semibold text-gray-900 text-base">
                        Add row to this timesheet
                    </h3>
                    <button
                        type="button"
                        wire:click="closeAddRowModal"
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

                    {{-- Project dropdown --}}
                    <div class="relative z-30" @click.outside="closeProjectPicker()">
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
                         Required vs optional is gated by selectedProject.asana_task_required. --}}
                    <template x-if="asanaBoardGids.length > 0">
                        <div class="relative z-20" @click.outside="closeAsanaTaskPicker()">
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
                                            wire:click="refreshNewRowAsanaTasks"
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
                            @error('newRowAsanaTaskGid')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                    </template>

                    {{-- Task dropdown --}}
                    <div class="relative z-10" @click.outside="closeTaskPicker()"
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

                </div>

                {{-- Modal footer --}}
                <div class="flex items-center px-6 py-4 border-t border-gray-100">
                    <button
                        wire:click="addRow"
                        class="px-5 py-2 text-sm font-semibold bg-green-600 hover:bg-green-700 text-white rounded-full transition"
                    >Save row</button>
                    <button
                        wire:click="closeAddRowModal"
                        class="ml-3 px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded-full transition"
                    >Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>
