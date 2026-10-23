@use('App\Domain\Reporting\ReconciliationRow')
@use('App\Domain\TimeTracking\HoursFormatter')
<div>
    @php
        $hours = fn (float $value): string => HoursFormatter::asReportDecimal($value);
        $signed = fn (float $value): string => ($value > 0 ? '+' : '').HoursFormatter::asReportDecimal($value);
    @endphp

    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900">Reconciliation</h1>
            <p class="text-sm text-gray-500 mt-1">
                Hours issued when each client's month was locked, against the hours booked now. Rows where they differ are highlighted.
            </p>
        </div>

        @if($months !== [])
        <div class="relative">
            <label for="reconciliation-month" class="sr-only">Month</label>
            <select id="reconciliation-month" wire:model.live="month"
                    class="text-sm border border-gray-300 rounded-md bg-white cursor-pointer focus:outline-none focus:ring-2 focus:ring-blue-500" style="padding: 0.5rem 2rem 0.5rem 0.75rem; -webkit-appearance: none; -moz-appearance: none; appearance: none;">
                @foreach($months as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <div style="pointer-events:none; position:absolute; top:0; bottom:0; right:0.5rem; display:flex; align-items:center;">
                <svg style="width:1rem;height:1rem;color:#6b7280;" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/>
                </svg>
            </div>
        </div>
        @endif
    </div>

    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto mb-8">
        @if($months === [])
        <div class="py-16 text-center text-sm text-gray-400">No months have been locked yet.</div>
        @elseif($clients->isEmpty())
        <div class="py-16 text-center text-sm text-gray-400">No hours were issued or booked for this month.</div>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="text-left px-4 py-3 font-medium">Client, project and task</th>
                    <th class="text-right px-4 py-3 font-medium">Issued hrs</th>
                    <th class="text-right px-4 py-3 font-medium">Current hrs</th>
                    <th class="text-right px-4 py-3 font-medium">Difference</th>
                </tr>
            </thead>
            @foreach($clients as $client)
            <tbody wire:key="client-{{ $client['id'] }}" class="divide-y divide-gray-50 border-b border-gray-200">
                @php $clientMismatch = ReconciliationRow::isMismatch($client['difference']); @endphp
                <tr @class(['font-semibold text-gray-900', 'bg-gray-50' => ! $clientMismatch, 'bg-amber-50' => $clientMismatch])>
                    <td class="px-4 py-3">{{ $client['name'] }} <span class="ml-1 text-xs font-normal text-gray-400">subtotal</span></td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $hours($client['issued']) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $hours($client['current']) }}</td>
                    <td @class(['px-4 py-3 text-right tabular-nums', 'text-amber-700' => $clientMismatch])>{{ $signed($client['difference']) }}</td>
                </tr>
                @foreach($client['projects'] as $project)
                @php $projectMismatch = ReconciliationRow::isMismatch($project['difference']); @endphp
                <tr wire:key="project-{{ $project['id'] }}" @class(['font-medium text-gray-800', 'bg-amber-50' => $projectMismatch])>
                    <td class="pl-8 pr-4 py-3">{{ $project['name'] }} <span class="ml-1 text-xs font-normal text-gray-400">subtotal</span></td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $hours($project['issued']) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $hours($project['current']) }}</td>
                    <td @class(['px-4 py-3 text-right tabular-nums', 'text-amber-700' => $projectMismatch])>{{ $signed($project['difference']) }}</td>
                </tr>
                @foreach($project['rows'] as $row)
                @php $rowMismatch = ReconciliationRow::isMismatch($row->difference); @endphp
                <tr @class(['text-gray-700', 'bg-amber-50' => $rowMismatch, 'hover:bg-gray-50' => ! $rowMismatch])>
                    <td class="pl-14 pr-4 py-3">{{ $row->taskName }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $hours($row->issuedHours) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $hours($row->currentHours) }}</td>
                    <td @class(['px-4 py-3 text-right tabular-nums', 'text-amber-700 font-medium' => $rowMismatch, 'text-gray-400' => ! $rowMismatch])>{{ $signed($row->difference) }}</td>
                </tr>
                @endforeach
                @endforeach
            </tbody>
            @endforeach
        </table>
        @endif
    </div>

    @if($months !== [])
    <h2 class="text-sm font-semibold text-gray-700 mb-3">Override log</h2>
    <div class="bg-white rounded-lg border border-gray-200 overflow-x-auto">
        @if($changes->isEmpty())
        <div class="py-10 text-center text-sm text-gray-400">No changes have been made under an override for this month.</div>
        @else
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-500 uppercase tracking-wide border-b border-gray-100">
                    <th class="text-left px-4 py-3 font-medium">When</th>
                    <th class="text-left px-4 py-3 font-medium">Client</th>
                    <th class="text-left px-4 py-3 font-medium">Who</th>
                    <th class="text-left px-4 py-3 font-medium">Reason</th>
                    <th class="text-left px-4 py-3 font-medium">Entry</th>
                    <th class="text-left px-4 py-3 font-medium">Action</th>
                    <th class="text-left px-4 py-3 font-medium">Changed</th>
                    <th class="text-right px-4 py-3 font-medium">Hours</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @foreach($changes as $change)
                @php
                    $before = $change->before['hours'] ?? null;
                    $after = $change->after['hours'] ?? null;
                    $changed = $change->changedFieldLabels();
                @endphp
                <tr wire:key="change-{{ $change->id }}" class="hover:bg-gray-50 align-top">
                    <td class="px-4 py-3 tabular-nums whitespace-nowrap">{{ $change->created_at->format('j M Y H:i') }}</td>
                    <td class="px-4 py-3 text-gray-900">{{ $change->override->monthLock->client->name }}</td>
                    <td class="px-4 py-3 text-gray-900">{{ $change->override->user->name }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $change->override->reason }}</td>
                    <td class="px-4 py-3 tabular-nums text-gray-500">#{{ $change->time_entry_id }}</td>
                    <td class="px-4 py-3">{{ ucfirst($change->action) }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $changed === [] ? '—' : implode(', ', $changed) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ $before === null ? '—' : $hours((float) $before) }} → {{ $after === null ? '—' : $hours((float) $after) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
    @endif
</div>
