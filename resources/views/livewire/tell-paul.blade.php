<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <div class="flex items-center justify-between gap-4">
            <h1 class="text-2xl font-semibold text-gray-900">Idea Box</h1>
        </div>
        <p class="mt-3 text-gray-700">Have an idea for something we should do differently? We want to hear it.</p>
        <p class="mt-2 text-sm text-gray-600">Whether it’s a better way of working, a new technology or framework or something that would help a client, your suggestions are welcome. Tell us what you have in mind and why it matters. You don’t need a polished pitch or a finished prototype.</p>
    </div>

    @if(session()->has('suggestion-sent'))
        <div role="status" class="mb-6 rounded-lg border border-green-200 bg-green-50 p-4 text-green-800">{{ session('suggestion-sent') }}</div>
    @endif

    <form wire:submit="submit" class="rounded-lg border border-gray-200 bg-white p-6 space-y-6">
        <p class="text-sm text-gray-500">All questions are required. A few clear sentences for each is plenty.</p>
        <div>
            <label for="title" class="block text-sm font-semibold text-gray-900">Give your idea a short title</label>
            <p id="title-help" class="mt-1 text-sm text-gray-500">For example: Try a different framework for front-end sites.</p>
            <input type="text" maxlength="200" id="title" wire:model="title" required aria-describedby="title-help title-error" class="mt-2 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-600 focus:ring-blue-600">
            @error('title') <p id="title-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="proposal" class="block text-sm font-semibold text-gray-900">What should we do?</label>
            <p id="proposal-help" class="mt-1 text-sm text-gray-500">Describe the change you’d like us to make and what it would look like in practice.</p>
            <textarea rows="4" maxlength="10000" id="proposal" wire:model="proposal" required aria-describedby="proposal-help proposal-error" class="mt-2 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
            @error('proposal') <p id="proposal-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="reason" class="block text-sm font-semibold text-gray-900">Why should we do it?</label>
            <p id="reason-help" class="mt-1 text-sm text-gray-500">What problem or opportunity have you spotted? Why is our current approach worth changing?</p>
            <textarea rows="4" maxlength="10000" id="reason" wire:model="reason" required aria-describedby="reason-help reason-error" class="mt-2 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
            @error('reason') <p id="reason-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="benefits" class="block text-sm font-semibold text-gray-900">How would it benefit us or a client?</label>
            <p id="benefits-help" class="mt-1 text-sm text-gray-500">Who would benefit, and how? Think about quality, time, cost, accessibility or the client experience. How could we tell it had worked?</p>
            <textarea rows="4" maxlength="10000" id="benefits" wire:model="benefits" required aria-describedby="benefits-help benefits-error" class="mt-2 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
            @error('benefits') <p id="benefits-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="exploration" class="block text-sm font-semibold text-gray-900">What have you explored or tried so far?</label>
            <p id="exploration-help" class="mt-1 text-sm text-gray-500">Tell us about any research, prototypes, tests or conversations you’ve taken the initiative on, and what you learned. Include links if useful. If you haven’t tried anything yet, that’s fine — say so and suggest a first step.</p>
            <textarea rows="4" maxlength="10000" id="exploration" wire:model="exploration" required aria-describedby="exploration-help exploration-error" class="mt-2 block w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
            @error('exploration') <p id="exploration-error" class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <p class="border-t border-gray-200 pt-5 text-sm text-gray-500">Your suggestion is emailed to us with your account name and email so we can follow up. It isn’t saved in Internal Tools.</p>
        @error('submit') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror
        <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="rounded-md bg-blue-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50">
            <span wire:loading.remove wire:target="submit">Submit idea</span>
            <span wire:loading wire:target="submit">Submitting…</span>
        </button>
    </form>
</div>
