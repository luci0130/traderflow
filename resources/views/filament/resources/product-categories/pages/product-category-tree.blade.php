<x-filament-panels::page>
    @once
        <style>
            [x-cloak] {
                display: none !important;
            }

            .tf-product-category-tree-grid {
                display: grid;
                gap: 1.5rem;
            }

            .tf-product-category-tree-panel .fi-section-content {
                padding: 0;
            }

            .tf-product-category-tree-scroll {
                max-height: min(42rem, calc(100vh - 18rem));
                overflow-y: auto;
                padding: 0.75rem;
            }

            .tf-product-category-tree-node-group {
                position: relative;
            }

            .tf-product-category-tree-row {
                display: flex;
                align-items: center;
                gap: 0.25rem;
            }

            .tf-product-category-tree-chevron {
                transition: transform 0.15s ease;
            }

            .tf-product-category-tree-chevron-open {
                transform: rotate(90deg);
            }

            .tf-product-category-tree-children {
                border-inline-start: 1px solid rgb(229 231 235);
                margin-inline-start: 1.2rem;
                padding-inline-start: 0.55rem;
            }

            .dark .tf-product-category-tree-children {
                border-inline-start-color: color-mix(in oklab, white 12%, transparent);
            }

            @media (min-width: 1280px) {
                .tf-product-category-tree-grid {
                    align-items: start;
                    grid-template-columns: minmax(22rem, 26rem) minmax(0, 1fr);
                }
            }
        </style>
    @endonce

    <div class="tf-product-category-tree-grid">
        <x-filament::section
            class="tf-product-category-tree-panel"
            heading="Product Categories"
        >
            <x-slot name="afterHeader">
                <div class="flex items-center gap-2">
                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-m-arrows-pointing-out"
                        x-on:click="$dispatch('tree-expand-all')"
                    >
                        {{ __('Expand all') }}
                    </x-filament::button>

                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-m-arrows-pointing-in"
                        x-on:click="$dispatch('tree-collapse-all')"
                    >
                        {{ __('Collapse all') }}
                    </x-filament::button>

                    <x-filament::badge color="gray">
                        {{ $this->getTotalCategoryCount() }}
                    </x-filament::badge>
                </div>
            </x-slot>

            @php
                $categoryTree = $this->getCategoryTree();
            @endphp

            <div class="tf-product-category-tree-scroll">
                @if ($categoryTree === [])
                    <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                        No categories found.
                    </div>
                @else
                    <nav class="space-y-1" aria-label="Product categories tree">
                        @include('filament.resources.product-categories.pages.partials.tree-nodes', [
                            'nodes' => $categoryTree,
                            'level' => 0,
                            'selectedCategoryId' => $selectedCategoryId,
                        ])
                    </nav>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section
            heading="Edit Category"
            :description="$this->getSelectedCategory()?->name"
        >
            @if ($this->getSelectedCategory() === null)
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Select a category from the tree to edit it.
                </div>
            @else
                {{ $this->form }}
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
