@use('App\Domain\TimeTracking\HoursFormatter')
<div>
    @include('livewire.reports.partials.header', ['title' => 'Team', 'totals' => $totals])

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        @if($rows->isEmpty())
        <div class="py-16 text-center text-sm text-gray-400">No entries in this period.</div>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="text-left px-4 py-3 font-medium">Team member</th>
                    <th class="text-left px-4 py-3 font-medium">Contract position</th>
                    <th class="text-right px-4 py-3 font-medium">Hours</th>
                    <th class="text-right px-4 py-3 font-medium">Billable hrs</th>
                    <th class="text-right px-4 py-3 font-medium">Billable %</th>
                    <th class="text-right px-4 py-3 font-medium">Amount</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($rows as $row)
                @php
                    $billablePercent = $row->total_hours > 0
                        ? round($row->billable_hours / $row->total_hours * 100, 1)
                        : 0;
                @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium text-gray-900">{{ $row->label }}</td>
                    <td class="px-4 py-3 text-xs text-gray-500">{{ $positions[$row->id] }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ HoursFormatter::asReportDecimal((float) $row->total_hours) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ HoursFormatter::asReportDecimal((float) $row->billable_hours) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-gray-500">{{ $billablePercent }}%</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($row->billable_amount, 2) }}</td>
                    <td class="px-4 py-3 text-right">
                        <a href="{{ route('reports.team.member', [
                            'user' => $row->id,
                            'preset' => $preset,
                            'from' => $from,
                            'to' => $to,
                        ]) }}"
                           class="text-xs text-blue-600 hover:underline">View →</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t border-gray-200 bg-gray-50">
                <tr class="text-sm font-semibold text-gray-900">
                    <td class="px-4 py-3">Total</td>
                    <td></td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ HoursFormatter::asReportDecimal((float) $totals->totalHours) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ HoursFormatter::asReportDecimal((float) $totals->billableHours) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $totals->billablePercent }}%</td>
                    <td class="px-4 py-3 text-right tabular-nums">£{{ number_format($totals->billableAmount, 2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        @endif
    </div>
</div>
