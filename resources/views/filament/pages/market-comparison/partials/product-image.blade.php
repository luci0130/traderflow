@php
    $record = $getRecord();
    $imageUrl = $record?->displayImageUrl();
@endphp

<div class="h-9 w-9 shrink-0 overflow-hidden rounded-md bg-gray-100 ring-1 ring-inset ring-gray-200 dark:bg-white/10 dark:ring-white/10">
    @if ($imageUrl)
        <img src="{{ $imageUrl }}" alt="" class="h-full w-full object-cover" />
    @else
        <div class="flex h-full w-full items-center justify-center text-gray-300 dark:text-white/30">
            <x-filament::icon icon="heroicon-o-cube" class="h-4 w-4" />
        </div>
    @endif
</div>
