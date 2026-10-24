@use('App\Domain\TimeTracking\HoursFormatter')
<div>
    @include('livewire.reports.partials.header', [
        'title' => $member->name,
        'totals' => $totals,
        'backLink' => route('reports.team'),
    ])

    @php
        $memberDetails = array_filter([
            $position,
            $member->started_on ? 'Started '.$member->started_on->format('j M Y') : null,
            $member->left_on ? 'Left '.$member->left_on->format('j M Y') : null,
        ]);
    @endphp
    <p class="mb-4 text-sm text-gray-500" title="Position as at {{ $positionAsAt->format('j M Y') }}">{{ implode(' · ', $memberDetails) }}</p>

    {{-- Group-by tabs --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        <div class="flex border-b border-gray-200 px-4">
            @foreach(['project' => 'Project', 'client' => 'Client', 'task' => 'Task'] as $value => $label)
            <button wire:click="$set('groupBy', '{{ $value }}')"
                    class="px-4 py-3 text-sm font-medium border-b-2 -mb-px {{ $groupBy === $value ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                {{ $label }}
            </button>
            @endforeach
        </div>

        @if($rows->isEmpty())
        <div class="py-16 text-center text-sm text-gray-400">No entries in this period.</div>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    @if($groupBy === 'project')
                    <th class="text-left px-4 py-3 font-medium">Client</th>
                    @endif
                    @if($groupBy === 'task')
                    <th class="w-8 px-4 py-3"></th>
                    @endif
                    <th class="text-left px-4 py-3 font-medium">{{ ucfirst($groupBy) }}</th>
                    <th class="text-right px-4 py-3 font-medium">Hours</th>
                    <th class="text-right px-4 py-3 font-medium">Billable hrs</th>
                    <th class="text-right px-4 py-3 font-medium">Amount</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                <tr class="hover:bg-gray-50">
                    @if($groupBy === 'project')
                    <td class="px-4 py-3 text-gray-500">{{ $row->client_name }}</td>
                    @endif
                    @if($groupBy === 'task')
                    <td class="px-4 py-3">
                        @if($row->colour)
                        <span class="inline-block w-3 h-3 rounded-full" style="background:{{ $row->colour }}"></span>
                        @endif
                    </td>
                    @endif
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $row->label }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ HoursFormatter::asReportDecimal((float) $row->total_hours) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ HoursFormatter::asReportDecimal((float) $row->billable_hours) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->billable_amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
