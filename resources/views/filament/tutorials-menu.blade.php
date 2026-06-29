@php
    /** @var array<int, array{key: string, label: string}> $tutorials */
@endphp

@if (! empty($tutorials))
    <x-filament::dropdown placement="bottom-end">
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="heroicon-o-question-mark-circle"
                color="gray"
                label="Tutoriale"
            />
        </x-slot>

        <x-filament::dropdown.header icon="heroicon-o-academic-cap">
            Tutoriale
        </x-filament::dropdown.header>

        <x-filament::dropdown.list>
            @foreach ($tutorials as $tutorial)
                @php($onTutorialClick = "close(); window.startTutorial && window.startTutorial('".e($tutorial['key'])."')")

                <x-filament::dropdown.list.item
                    icon="heroicon-o-play-circle"
                    :x-on:click="$onTutorialClick"
                >
                    {{ $tutorial['label'] }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
