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

<div
    data-modal="{{ $name }}"
    role="dialog"
    aria-modal="true"
    aria-label="確認ダイアログ"
    tabindex="-1"
    @if (! $show) hidden @endif
    class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
>
    <button
        type="button"
        data-modal-backdrop
        class="fixed inset-0 h-full w-full cursor-default bg-gray-500 opacity-75"
        aria-label="ダイアログを閉じる"
    ></button>

    <div class="relative mb-6 overflow-hidden rounded-lg bg-white shadow-xl sm:mx-auto sm:w-full {{ $maxWidth }}">
        {{ $slot }}
    </div>
</div>
