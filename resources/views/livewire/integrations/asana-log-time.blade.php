<div x-data="{ embedded: window.self !== window.top }">
    @if($status === 'missing')
        <div class="text-center px-6 py-10">
            <p class="text-sm font-medium text-gray-900">This Asana task isn't synced to Internal Tools yet.</p>
            <p class="mt-2 text-sm text-gray-500">Refresh Asana tasks from the timesheet, then try again.</p>
            <a href="{{ route('timesheet') }}" target="_blank" rel="noopener"
               class="mt-4 inline-block text-sm text-green-700 hover:underline">Open Internal Tools ↗</a>
        </div>
    @elseif($status === 'unmapped')
        <div class="text-center px-6 py-10">
            <p class="text-sm font-medium text-gray-900">This Asana board isn't linked to any of your projects.</p>
            <p class="mt-2 text-sm text-gray-500">Ask an admin to link the board to an Internal Tools project, or log the time from your timesheet.</p>
            <a href="{{ route('timesheet') }}" target="_blank" rel="noopener"
               class="mt-4 inline-block text-sm text-green-700 hover:underline">Open Internal Tools ↗</a>
        </div>
    @else
        {{-- Branded header bar, like Harvest's logo bar --}}
        <div class="flex items-center justify-between gap-3 px-6 py-3" style="background-color: #002f5f;">
            <img src="/assets/filter-logo-white-rgb.png" alt="Filter" class="w-auto" style="height: 1.5rem;">
            <a href="{{ route('timesheet') }}" target="_blank" rel="noopener"
               class="flex-none text-xs text-white opacity-70 hover:opacity-100 hover:underline">My timesheet ↗</a>
        </div>

        @if($running !== null)
            {{-- A timer is running on this Asana task: show it instead of
                 the entry form. Ticks client-side only — no Livewire polls
                 (a morph mid-interaction is what breaks embedded forms). --}}
            <div class="px-6 py-5 space-y-3"
                 x-data="{
                     seconds: {{ $running['base_seconds'] }} + Math.max(0, Math.floor((Date.now() - {{ $running['started_at_ms'] }}) / 1000)),
                     display() {
                         const h = Math.floor(this.seconds / 3600);
                         const m = String(Math.floor((this.seconds % 3600) / 60)).padStart(2, '0');
                         const s = String(this.seconds % 60).padStart(2, '0');
                         return h + ':' + m + ':' + s;
                     },
                 }"
                 x-init="setInterval(() => seconds++, 1000)">
                <div class="text-sm font-semibold text-gray-700">Timer running</div>

                <div class="flex items-center justify-between gap-3 border border-green-200 bg-green-50 rounded-lg px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ $running['label'] }}</p>
                        @if($running['notes'])
                            <p class="text-xs text-gray-500 truncate">{{ $running['notes'] }}</p>
                        @endif
                    </div>
                    <span class="flex-none text-lg font-semibold tabular-nums text-green-700" x-text="display()"></span>
                </div>

                <div class="flex items-center pt-4 -mx-6 px-6 border-t border-gray-100 !mt-5">
                    <button type="button" wire:click="stopRunningTimer"
                            class="px-5 py-2 text-sm font-semibold bg-green-600 hover:bg-green-700 text-white rounded-full transition">
                        Stop timer
                    </button>
                    <button type="button" x-show="embedded" x-cloak
                            x-on:click="window.parent.postMessage({ type: 'filter-log-time:close' }, 'https://app.asana.com')"
                            class="ml-auto px-4 py-2 text-sm text-gray-500 hover:text-gray-700 transition">
                        Close
                    </button>
                </div>
            </div>
        @else
        <form wire:submit="save" class="px-6 py-5 space-y-3">
            @if($savedSummary !== '')
                <div class="px-4 py-2.5 rounded-lg text-sm {{ $timerStarted ? 'bg-blue-50 text-blue-900' : 'bg-green-50 text-green-900' }}">
                    ✓ {{ $savedSummary }}
                </div>
            @endif

            <div class="text-sm font-semibold text-gray-700">Project / Task</div>

            <select wire:model.live="selectedProjectId" aria-label="Project" data-searchable-select="false"
                    class="w-full border border-gray-300 rounded-lg bg-white px-4 py-3 text-sm text-gray-900 hover:border-gray-400 transition focus:outline-none focus:ring-2 focus:ring-green-500 appearance-none pr-10 bg-no-repeat" style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e&quot;); background-position: right 0.875rem center; background-size: 1.25em 1.25em;">
                @foreach($projects as $project)
                    <option value="{{ $project->id }}">{{ $project->name }}{{ $project->client ? ' — '.$project->client->name : '' }}</option>
                @endforeach
            </select>

            <div>
                <select wire:model="selectedTaskId" aria-label="Task" data-searchable-select="false"
                        class="w-full border border-gray-300 rounded-lg bg-white px-4 py-3 text-sm text-gray-900 hover:border-gray-400 transition focus:outline-none focus:ring-2 focus:ring-green-500 appearance-none pr-10 bg-no-repeat" style="background-image: url(&quot;data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='m6 8 4 4 4-4'/%3e%3c/svg%3e&quot;); background-position: right 0.875rem center; background-size: 1.25em 1.25em;">
                    <option value="">Select a task…</option>
                    @foreach(($projects->firstWhere('id', $selectedProjectId)?->tasks ?? []) as $task)
                        <option value="{{ $task->id }}">{{ $task->name }}</option>
                    @endforeach
                </select>
                @error('selectedTaskId')
                    <p class="text-red-500 text-xs mt-1">Pick a task.</p>
                @enderror
            </div>

            {{-- The Asana task this entry is fixed to — read-only context --}}
            <input type="text" value="{{ $taskName }}" disabled aria-label="Asana task"
                   class="w-full border border-gray-200 rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500 cursor-not-allowed">

            {{-- Notes + Hours row, as in the entry modal --}}
            <div class="flex gap-3 items-stretch pt-1">
                <textarea wire:model="notes" rows="1" placeholder="Notes (optional)"
                          class="flex-1 border border-gray-300 rounded-lg px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-green-500 resize-none placeholder-gray-400"></textarea>
                <div class="flex-shrink-0 w-24 flex flex-col">
                    <input type="text" wire:model="hoursInput" placeholder="0.25" aria-label="Hours"
                           class="w-full h-full border {{ ($hoursError !== '' || $errors->has('hoursInput')) ? 'border-red-400' : 'border-gray-300' }} rounded-lg px-3 py-2.5 text-sm text-center tabular-nums focus:outline-none focus:ring-2 focus:ring-green-500 placeholder-gray-400">
                    @error('hoursInput')
                        <p class="text-red-500 text-xs mt-1 text-center">Hours required</p>
                    @enderror
                    @if($hoursError !== '')
                        <p class="text-red-500 text-xs mt-1 text-center">{{ $hoursError }}</p>
                    @endif
                </div>
            </div>
            @error('month_lock')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror

            {{-- Footer — mirrors the entry modal footer --}}
            <div class="flex items-center pt-4 -mx-6 px-6 border-t border-gray-100 !mt-5">
                <button type="submit"
                        class="px-5 py-2 text-sm font-semibold bg-green-600 hover:bg-green-700 text-white rounded-full transition">
                    Log time
                </button>
                <button type="button" wire:click="startTimer"
                        class="ml-3 px-4 py-2 text-sm text-gray-600 hover:text-gray-800 border border-gray-300 rounded-full transition">
                    Start timer
                </button>
                {{-- Only meaningful inside the extension dialog. --}}
                <button type="button" x-show="embedded" x-cloak
                        x-on:click="window.parent.postMessage({ type: 'filter-log-time:close' }, 'https://app.asana.com')"
                        class="ml-auto px-4 py-2 text-sm text-gray-500 hover:text-gray-700 transition">
                    Cancel
                </button>
            </div>
        </form>
        @endif
    @endif
</div>

@script
<script>
    // Inside the extension's <dialog> iframe on app.asana.com. The
    // extension listens for these (see asana-extension/content.js):
    // saved → dismiss after a beat; height → size the dialog to the form.
    const embedded = window.self !== window.top;
    const post = (message) => window.parent.postMessage(message, 'https://app.asana.com');

    $wire.on('asana-entry-saved', () => {
        if (embedded) post({ type: 'filter-log-time:saved' });
    });

    if (embedded) {
        // Measure the component root, not document scrollHeight — the
        // latter is floored at the iframe's viewport height, so the
        // dialog could grow but never shrink back to fit the form.
        const root = document.body.firstElementChild;
        const reportHeight = () => post({
            type: 'filter-log-time:height',
            height: Math.ceil(root.getBoundingClientRect().height),
        });
        new ResizeObserver(reportHeight).observe(root);
        reportHeight();
    }
</script>
@endscript
