<div class="grid min-h-screen w-full grid-cols-1 bg-white lg:grid-cols-2 dark:bg-gray-950"
    x-data
    x-on:registration-language-changed.window="document.documentElement.lang = $event.detail.locale; document.title = $event.detail.title">
    <main class="flex items-center justify-center px-6 py-8 sm:px-12 lg:py-12">
        <div class="w-full max-w-md space-y-8">
            <nav class="flex justify-end gap-1" aria-label="{{ __('Registration language') }}">
                @foreach (['ro' => 'Română', 'en' => 'English'] as $locale => $label)
                    <button type="button" wire:click="switchLanguage('{{ $locale }}')" wire:loading.attr="disabled"
                        lang="{{ $locale }}" aria-pressed="{{ app()->getLocale() === $locale ? 'true' : 'false' }}"
                        @class([
                            'rounded-lg px-3 py-2 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600',
                            'bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white' => app()->getLocale() === $locale,
                            'text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5' => app()->getLocale() !== $locale,
                        ])>
                        {{ $label }}
                    </button>
                @endforeach
            </nav>

            <a href="{{ config('malcara.website') }}" class="mx-auto block w-56" aria-label="{{ __('Malcara website') }}">
                <img src="{{ asset(config('malcara.logo')) }}" alt="Malcara" width="1810" height="415" class="h-auto w-full dark:hidden" />
                <img src="{{ asset(config('malcara.logo_white')) }}" alt="Malcara" width="1803" height="415" class="hidden h-auto w-full dark:block" />
            </a>

            <div class="space-y-2 text-center">
                <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">{{ __('Create your Malcara account') }}</h1>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Register your company and connect with the Malcara team.') }}</p>
            </div>

            {{ $this->content }}

            <p class="text-center text-sm text-gray-600 dark:text-gray-400">
                {{ __('Already have an account?') }}
                {{ $this->loginAction }}
            </p>

            <div class="border-t border-gray-200 pt-6 text-gray-600 lg:hidden dark:border-white/10 dark:text-gray-400">
                @include('producers.filament.partials.malcara-contact')
            </div>
        </div>
    </main>

    <aside class="relative hidden overflow-hidden bg-[#3d2f2f] lg:block" aria-label="{{ __('About Malcara') }}">
        <div class="absolute -right-32 -top-32 h-96 w-96 rounded-full bg-[#c12026]/30 blur-3xl" aria-hidden="true"></div>
        <div class="absolute -bottom-40 -left-20 h-96 w-96 rounded-full bg-white/5 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex min-h-screen flex-col justify-between gap-12 p-10 text-white xl:p-14">
            <a href="{{ config('malcara.website') }}" class="block w-56" aria-label="{{ __('Malcara website') }}">
                <img src="{{ asset(config('malcara.logo_white')) }}" alt="Malcara" width="1803" height="415" class="h-auto w-full" />
            </a>

            <div class="max-w-lg space-y-8">
                <p class="text-sm font-semibold uppercase tracking-widest text-red-200">{{ __('Quality. Collaboration. Growth.') }}</p>
                <h2 class="text-4xl font-bold leading-tight">{{ __('Your partner in international trade.') }}</h2>
                <p class="text-lg leading-relaxed text-white/80">
                    {{ __('Malcara connects producers and buyers of livestock, meat and agricultural products, building long-term partnerships through quality and collaboration.') }}
                </p>
                <ul class="space-y-5">
                    @foreach ([
                        ['icon' => 'heroicon-o-check-badge', 'title' => __('Livestock and meat'), 'body' => __('Trade built around quality and the needs of our partners.')],
                        ['icon' => 'heroicon-o-sun', 'title' => __('Cereals and agricultural products'), 'body' => __('Connecting agricultural supply with market demand.')],
                        ['icon' => 'heroicon-o-globe-europe-africa', 'title' => __('Import and export'), 'body' => __('Professional solutions for producers, suppliers and buyers.')],
                    ] as $feature)
                        <li class="flex gap-4">
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-white/10">
                                <x-filament::icon :icon="$feature['icon']" class="h-5 w-5" />
                            </span>
                            <div>
                                <h3 class="font-semibold">{{ $feature['title'] }}</h3>
                                <p class="text-sm text-white/70">{{ $feature['body'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="border-t border-white/15 pt-6 text-white/80">
                @include('producers.filament.partials.malcara-contact')
            </div>
        </div>
    </aside>
    <x-filament-actions::modals />
</div>
