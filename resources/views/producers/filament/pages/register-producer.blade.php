@php
    $hasLogo = $this->hasLogo();
@endphp

<div class="fi-simple-page">
    <div class="fi-simple-page-content space-y-8">
        @if ($hasLogo)
            <div class="flex justify-center">
                <x-filament-panels::logo />
            </div>
        @endif

        <div class="space-y-2 text-center">
            <h1 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                {{ __('Sell faster with TradeFlow') }}
            </h1>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('Create your producer account in under a minute. No credit card required.') }}
            </p>
        </div>

        {{ $this->content }}

        <p class="text-center text-sm text-gray-600 dark:text-gray-400">
            {{ __('Already have an account?') }}
            {{ $this->loginAction }}
        </p>
    </div>

    <x-filament-actions::modals />
</div>
