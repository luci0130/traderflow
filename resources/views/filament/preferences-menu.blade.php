@auth
    <x-filament::dropdown placement="bottom-end">
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="heroicon-o-language"
                color="gray"
                :label="__('Language').': '.\App\Support\LocaleOptions::nativeName(app()->getLocale())"
            />
        </x-slot>

        <x-filament::dropdown.header icon="heroicon-o-language">
            {{ __('Language') }}
        </x-filament::dropdown.header>

        <x-filament::dropdown.list>
            @foreach (\App\Support\LocaleOptions::supported() as $locale => $language)
                <x-filament::dropdown.list.item
                    tag="a"
                    :href="route('locale.switch', ['locale' => $locale])"
                    :icon="app()->getLocale() === $locale ? 'heroicon-o-check' : null"
                    :color="app()->getLocale() === $locale ? 'primary' : 'gray'"
                >
                    {{ $language['native_name'] }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>

    <x-filament::dropdown placement="bottom-end">
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="heroicon-o-banknotes"
                color="gray"
                :label="__('Display currency').': '.\App\Support\Currency::preferred()"
            />
        </x-slot>

        <x-filament::dropdown.header icon="heroicon-o-banknotes">
            {{ __('Display currency') }}
        </x-filament::dropdown.header>

        <x-filament::dropdown.list>
            @foreach (\App\Support\Currency::supported() as $currency)
                <x-filament::dropdown.list.item
                    tag="form"
                    method="post"
                    :action="route('currency.switch', ['currency' => $currency])"
                    :icon="\App\Support\Currency::preferred() === $currency ? 'heroicon-o-check' : null"
                    :color="\App\Support\Currency::preferred() === $currency ? 'primary' : 'gray'"
                >
                    {{ $currency === 'EUR' ? 'EUR (€)' : 'RON (lei)' }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endauth
