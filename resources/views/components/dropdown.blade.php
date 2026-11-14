@props(['align' => 'right', 'width' => '48', 'contentClasses' => 'py-1 bg-white'])

@php
$alignmentClasses = match ($align) {
    'left' => 'ltr:origin-top-left rtl:origin-top-right start-0',
    'top' => 'origin-top',
    default => 'ltr:origin-top-right rtl:origin-top-left end-0',
};

$width = match ($width) {
    '48' => 'w-48',
    default => $width,
};
@endphp

<div class="relative" data-dropdown>
    <div data-dropdown-toggle>
        {{ $trigger }}
    </div>

    <div hidden
            class="absolute z-50 mt-2 {{ $width }} rounded-md shadow-lg {{ $alignmentClasses }}"
            data-dropdown-menu>
        <div class="rounded-md ring-1 ring-black/5 {{ $contentClasses }}">
            {{ $content }}
        </div>
    </div>
</div>
