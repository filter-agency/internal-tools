@props(['lock'])

<span
    title="{{ $lock->label() }} is locked: report issued {{ $lock->issued_on->format('j F Y') }}"
    {{ $attributes->merge(['class' => 'inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-500']) }}
>Locked</span>
