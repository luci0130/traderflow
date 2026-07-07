@foreach ($nodes as $node)
    @php
        $isSelected = $selectedCategoryId === $node['id'];
        $hasChildren = count($node['children']) > 0;
    @endphp

    <div
        class="tf-product-category-tree-node-group"
        @if ($hasChildren)
            x-data="{ expanded: false }"
            x-on:tree-expand-all.window="expanded = true"
            x-on:tree-collapse-all.window="expanded = false"
        @endif
    >
        <div class="tf-product-category-tree-row">
            @if ($hasChildren)
                <button
                    type="button"
                    x-on:click="expanded = ! expanded"
                    :aria-expanded="expanded ? 'true' : 'false'"
                    aria-label="{{ __('Toggle category') }}"
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-gray-200"
                >
                    <svg
                        class="tf-product-category-tree-chevron h-4 w-4"
                        x-bind:class="expanded && 'tf-product-category-tree-chevron-open'"
                        viewBox="0 0 20 20"
                        fill="currentColor"
                        aria-hidden="true"
                    >
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd" />
                    </svg>
                </button>
            @else
                <span class="h-7 w-7 shrink-0"></span>
            @endif

            <button
                type="button"
                wire:click="selectCategory({{ $node['id'] }})"
                @if ($hasChildren) x-on:click="expanded = ! expanded" @endif
                @class([
                    'group flex min-h-10 w-full items-center gap-2 rounded-lg px-3 py-2 text-start text-sm transition',
                    'bg-primary-50 text-primary-800 shadow-sm ring-1 ring-primary-200 dark:bg-primary-500/10 dark:text-primary-200 dark:ring-primary-500/30' => $isSelected,
                    'text-gray-700 hover:bg-gray-50 hover:text-gray-950 dark:text-gray-200 dark:hover:bg-white/5 dark:hover:text-white' => ! $isSelected,
                ])
            >
                <span class="relative shrink-0">
                    @if (! empty($node['image_url']))
                        <img
                            src="{{ $node['image_url'] }}"
                            alt=""
                            loading="lazy"
                            class="h-8 w-8 rounded-md object-cover ring-1 ring-gray-200 dark:ring-white/10"
                        />
                    @else
                        <span class="flex h-8 w-8 items-center justify-center rounded-md bg-gray-100 text-gray-300 ring-1 ring-gray-200 dark:bg-white/5 dark:text-white/30 dark:ring-white/10">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M4 3a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2H4Zm12 12H4l3.5-4.5 2.5 3 3.5-4.5L16 15Z" />
                            </svg>
                        </span>
                    @endif
                    <span
                        @class([
                            'absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full ring-2 ring-white dark:ring-gray-900',
                            'bg-success-500' => $node['status'] === 'active',
                            'bg-gray-400 dark:bg-gray-500' => $node['status'] !== 'active',
                        ])
                    ></span>
                </span>

                <span class="min-w-0 truncate font-medium">{{ $node['name'] }}</span>

                <span @class([
                    'shrink-0 text-xs',
                    'font-medium text-primary-600 dark:text-primary-400' => $node['products_count'] > 0,
                    'text-gray-400 dark:text-gray-500' => $node['products_count'] === 0,
                ])>({{ $node['products_count'] }})</span>
            </button>
        </div>

        @if ($hasChildren)
            <div
                x-show="expanded"
                x-collapse
                x-cloak
                class="tf-product-category-tree-children mt-1 space-y-1"
            >
                @include('filament.resources.product-categories.pages.partials.tree-nodes', [
                    'nodes' => $node['children'],
                    'level' => $level + 1,
                    'selectedCategoryId' => $selectedCategoryId,
                ])
            </div>
        @endif
    </div>
@endforeach
