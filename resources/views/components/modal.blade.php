@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl'
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth];
@endphp

<dialog
    data-modal="{{ $name }}"
    data-modal-backdrop-close
    @if ($show) data-modal-initial-open @endif
    @if ($attributes->has('focusable')) data-modal-autofocus @endif
    class="mx-auto my-auto w-[calc(100%-2rem)] {{ $maxWidth }} rounded-lg border-0 bg-white p-0 shadow-xl backdrop:bg-gray-500/75"
>
    <div class="overflow-hidden rounded-lg">
        {{ $slot }}
    </div>
</dialog>
