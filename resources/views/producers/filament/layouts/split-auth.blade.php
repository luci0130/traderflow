@php
    $livewire ??= null;
@endphp

<x-filament-panels::layout.base :livewire="$livewire">
    <div class="min-h-screen w-full grid grid-cols-1 lg:grid-cols-2 bg-white dark:bg-gray-950">
        {{-- Left: form --}}
        <div class="flex items-center justify-center px-6 py-12 sm:px-12">
            <div class="w-full max-w-md">
                {{ $slot }}
            </div>
        </div>

        {{-- Right: marketing --}}
        <div class="relative hidden lg:block overflow-hidden">
            <div class="absolute inset-0 bg-gradient-to-br from-emerald-600 via-emerald-700 to-green-900"></div>

            {{-- Decorative pattern --}}
            <svg class="absolute inset-0 h-full w-full opacity-10" preserveAspectRatio="none" viewBox="0 0 800 800" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="leaves" x="0" y="0" width="80" height="80" patternUnits="userSpaceOnUse">
                        <path d="M40 10c-15 10-20 30-10 45 10-15 25-20 40-15-5-15-20-25-30-30z" fill="white" />
                    </pattern>
                </defs>
                <rect width="800" height="800" fill="url(#leaves)" />
            </svg>

            {{-- Decorative blobs --}}
            <div class="absolute -top-32 -right-32 h-96 w-96 rounded-full bg-emerald-400/20 blur-3xl"></div>
            <div class="absolute -bottom-40 -left-20 h-[28rem] w-[28rem] rounded-full bg-green-300/10 blur-3xl"></div>

            <div class="relative z-10 flex h-full flex-col justify-between p-12 text-white">
                <div class="flex items-center gap-3 text-lg font-semibold">
                    <span class="grid h-10 w-10 place-items-center rounded-xl bg-white/15 backdrop-blur">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-6 w-6">
                            <path d="M3 12 12 3l9 9v9a1 1 0 0 1-1 1h-5v-7h-6v7H4a1 1 0 0 1-1-1v-9Z" />
                        </svg>
                    </span>
                    TradeFlow
                </div>

                <div class="space-y-8 max-w-lg">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-3 py-1 text-xs font-medium uppercase tracking-wide backdrop-blur">
                        <span class="h-2 w-2 rounded-full bg-emerald-200 animate-pulse"></span>
                        {{ __('For producers & wholesalers') }}
                    </span>

                    <h2 class="text-4xl font-bold leading-tight">
                        {{ __('Sell faster across every European market.') }}
                    </h2>

                    <p class="text-lg text-emerald-50/90 leading-relaxed">
                        {{ __('TradeFlow connects producers, wholesalers and suppliers to buyers across Romania and the European Union — list your products once and reach traders in dozens of markets.') }}
                    </p>

                    <ul class="space-y-4">
                        @foreach ([
                            ['icon' => 'M12 2 4 6v6c0 5 3.5 9 8 10 4.5-1 8-5 8-10V6l-8-4Z', 'title' => __('One catalog, many markets'), 'body' => __('Publish a product and instantly offer it to buyers from Bucharest to Berlin.')],
                            ['icon' => 'M3 12h18M3 6h18M3 18h18', 'title' => __('Built-in invoicing'), 'body' => __('Add your company details once. Every order can be invoiced automatically.')],
                            ['icon' => 'M12 6v6l4 2', 'title' => __('Real-time availability'), 'body' => __('Set price, minimum quantity and validity — buyers always see what is still in stock.')],
                        ] as $item)
                            <li class="flex gap-4">
                                <span class="mt-1 grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-white/15 backdrop-blur">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" class="h-5 w-5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                                    </svg>
                                </span>
                                <div>
                                    <div class="font-semibold">{{ $item['title'] }}</div>
                                    <div class="text-sm text-emerald-50/80">{{ $item['body'] }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="flex items-center gap-6 text-sm text-emerald-50/80">
                    <div class="flex -space-x-2">
                        @foreach (['from-emerald-300 to-emerald-500', 'from-green-300 to-green-500', 'from-lime-300 to-emerald-500'] as $g)
                            <span class="h-9 w-9 rounded-full ring-2 ring-emerald-700 bg-gradient-to-br {{ $g }}"></span>
                        @endforeach
                    </div>
                    <div>
                        <div class="font-semibold text-white">{{ __('Trusted by producers across Europe') }}</div>
                        <div class="text-xs">{{ __('Join wholesalers already shipping faster with TradeFlow.') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::layout.base>
