<div class="space-y-4">
    <div class="flex items-center justify-between gap-4">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Select products and suppliers to add to this offer. Existing lines will be preserved.') }}</p>
        <x-filament::button wire:click="addSelected" wire:loading.attr="disabled" :disabled="$selectedSupplierProductIds === []">
            {{ __('Add selected products') }} ({{ count($selectedSupplierProductIds) }})
        </x-filament::button>
    </div>

    @error('selection')
        <p class="text-sm text-danger-600">{{ $message }}</p>
    @enderror

    <div class="overflow-x-auto">
        <div class="min-w-[1200px]">
            {{ $this->table }}
        </div>
    </div>
</div>
