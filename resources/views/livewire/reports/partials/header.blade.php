@use('App\Domain\TimeTracking\HoursFormatter')
{{-- Report header: title, period selector, totals cards, filters. Optional: $subtitle (small line under the title), $subtitleHint (its tooltip). --}}
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-3">
        @isset($backLink)
        <a href="{{ $backLink }}" class="text-sm text-gray-500 hover:text-gray-700">← Reports</a>
        @endisset
        <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ $title }}</h1>
            @if(filled($subtitle ?? null))
            <p data-report-subtitle class="mt-0.5 text-sm text-gray-500"@isset($subtitleHint) title="{{ $subtitleHint }}"@endisset>{{ $subtitle }}</p>
            @endif
        </div>
    </div>

    <div class="flex items-center gap-3">
        <button wire:click="export"
                class="text-sm text-gray-600 border border-gray-300 rounded-md px-3 py-2 hover:bg-gray-50 flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Export CSV
        </button>

        <div class="relative">
        <select wire:model.live="preset"
                class="text-sm border border-gray-300 rounded-md bg-white cursor-pointer focus:outline-none focus:ring-2 focus:ring-blue-500" style="padding: 0.5rem 2rem 0.5rem 0.75rem; -webkit-appearance: none; -moz-appearance: none; appearance: none;">
            <option value="this_week">This week</option>
            <option value="this_month">This month</option>
            <option value="last_month">Last month</option>
            <option value="last_3">Last 3 months</option>
            <option value="this_year">This year</option>
            <option value="last_year">Last year</option>
            <option value="custom">Custom</option>
        </select>
        <div style="pointer-events:none; position:absolute; top:0; bottom:0; right:0.5rem; display:flex; align-items:center;">
            <svg style="width:1rem;height:1rem;color:#6b7280;" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
            </svg>
        </div>
        </div>

        @if($preset === 'custom')
        <input type="date" wire:model.live="from"
               class="text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
        <span class="text-gray-400 text-sm">–</span>
        <input type="date" wire:model.live="to"
               class="text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" />
        @else
        <span class="text-sm text-gray-500">
            {{ \Carbon\CarbonImmutable::parse($from)->format('d M Y') }} – {{ \Carbon\CarbonImmutable::parse($to)->format('d M Y') }}
        </span>
        @endif
    </div>
</div>

{{-- Totals --}}
<div class="mb-6" style="display:grid; grid-template-columns: repeat(4, 1fr); gap: 1rem;">
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Total hours</p>
        <p class="text-2xl font-semibold text-gray-900">{{ HoursFormatter::asReportDecimal((float) $totals->totalHours) }}</p>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Billable hours</p>
        <p class="text-2xl font-semibold text-gray-900">{{ HoursFormatter::asReportDecimal((float) $totals->billableHours) }}</p>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Billable %</p>
        <p class="text-2xl font-semibold text-gray-900">{{ $totals->billablePercent }}%</p>
    </div>
    <div class="bg-white rounded-lg border border-gray-200 p-4">
        <p class="text-xs text-gray-500 mb-1">Billable amount</p>
        <p class="text-2xl font-semibold text-gray-900">£{{ number_format($totals->billableAmount, 2) }}</p>
    </div>
</div>

{{-- Filters --}}
<div class="flex items-center justify-end mb-4 text-sm text-gray-600">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="checkbox" wire:model.live="showArchived"
               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
        Show archived projects
    </label>
</div>
