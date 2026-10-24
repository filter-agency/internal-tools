<div>
    <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Audit export</h1>
        <p class="text-sm text-gray-500 mt-1">
            One row per person, month, client, project and task. No notes, rates or amounts.
        </p>
    </div>

    <div class="bg-white rounded-lg border border-gray-200 p-4 max-w-3xl">
        <div class="grid grid-cols-2 gap-3 mb-4 max-w-md">
            <div>
                <label for="audit-export-from" class="block text-xs font-medium text-gray-600 mb-1">From</label>
                <input id="audit-export-from" wire:model.live="from" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                @error('from')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="audit-export-to" class="block text-xs font-medium text-gray-600 mb-1">To</label>
                <input id="audit-export-to" wire:model.live="to" type="date" class="w-full border border-gray-300 rounded text-sm px-2 py-1.5">
                @error('to')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            </div>
        </div>

        <fieldset class="mb-4">
            <legend class="block text-xs font-medium text-gray-600 mb-1">Clients</legend>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-1">
                @foreach($clients as $client)
                    <label wire:key="client-{{ $client->id }}" class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" wire:model.live="clientIds" value="{{ $client->id }}" class="rounded">
                        <span>{{ $client->name }}@if($client->is_archived) <span class="text-xs text-gray-400">(archived)</span>@endif</span>
                    </label>
                @endforeach
            </div>
            @error('clientIds')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
            @error('clientIds.*')<p class="text-red-600 text-xs mt-1">{{ $message }}</p>@enderror
        </fieldset>

        <button type="button" wire:click="download"
                class="text-sm text-gray-600 border border-gray-300 rounded-md px-3 py-2 hover:bg-gray-50 inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Download CSV
        </button>
    </div>
</div>
