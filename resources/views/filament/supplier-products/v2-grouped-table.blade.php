@php
    $table = $this->getTable();
    $columns = $table->getVisibleColumns();
    $actions = $table->getRecordActions();
    $selectionEnabled = $table->isSelectionEnabled();
    $selectionDisabled = $table->isSelectionDisabled();
    $colspan = count($columns) + (int) $selectionEnabled + (int) filled($actions);
    $recordsBySupplier = $records->groupBy('producer_id');
    $loadedSupplierIds = array_map('intval', array_keys($this->loadedSuppliers));
    $loadedWeekKeys = [];

    foreach ($this->loadedWeeks as $supplierId => $weekKeys) {
        foreach ($weekKeys as $weekKey) {
            $loadedWeekKeys[] = 'supplier:'.$supplierId.':week:'.$weekKey;
        }
    }
@endphp

<div
    class="fi-supplier-products-grouped"
    data-supplier-products-grouped
    wire:key="{{ $this->getId() }}.supplier-products-groups"
    wire:ignore.self
    x-data="{
        loadedSuppliers: @js($loadedSupplierIds),
        expandedSuppliers: @js($loadedSupplierIds),
        loadedWeeks: @js($loadedWeekKeys),
        expandedWeeks: @js($loadedWeekKeys),
        loadingSuppliers: [],
        loadingWeeks: [],
        async toggleSupplier(supplierId) {
            if (this.expandedSuppliers.includes(supplierId)) {
                this.expandedSuppliers = this.expandedSuppliers.filter((id) => id !== supplierId);
                return;
            }

            if (this.loadedSuppliers.includes(supplierId)) {
                this.expandedSuppliers.push(supplierId);
                return;
            }

            this.loadingSuppliers.push(supplierId);

            try {
                const latestWeek = await this.$wire.loadSupplier(supplierId);
                this.loadedSuppliers.push(supplierId);
                this.expandedSuppliers.push(supplierId);

                if (latestWeek !== null) {
                    const latestWeekKey = `supplier:${supplierId}:week:${latestWeek}`;
                    this.loadedWeeks.push(latestWeekKey);
                    this.expandedWeeks.push(latestWeekKey);
                }
            } finally {
                this.loadingSuppliers = this.loadingSuppliers.filter((id) => id !== supplierId);
            }
        },
        async toggleWeek(supplierId, weekKey, weekDate) {
            if (this.expandedWeeks.includes(weekKey)) {
                this.expandedWeeks = this.expandedWeeks.filter((key) => key !== weekKey);
                return;
            }

            if (this.loadedWeeks.includes(weekKey)) {
                this.expandedWeeks.push(weekKey);
                return;
            }

            this.loadingWeeks.push(weekKey);

            try {
                await this.$wire.loadWeek(supplierId, weekDate);
                this.loadedWeeks.push(weekKey);
                this.expandedWeeks.push(weekKey);
            } finally {
                this.loadingWeeks = this.loadingWeeks.filter((key) => key !== weekKey);
            }
        },
    }"
>
    <table class="fi-ta-table">
        <thead>
            <tr>
                @if ($selectionEnabled)
                    <th class="fi-ta-cell fi-ta-selection-cell">
                        <input
                            type="checkbox"
                            class="fi-ta-page-checkbox fi-checkbox-input"
                            aria-label="{{ __('filament-tables::table.fields.bulk_select_page.label') }}"
                            @disabled($selectionDisabled)
                            x-on:click="toggleSelectRecords(getRecordsOnPage())"
                            x-bind:checked="getRecordsOnPage().length && areRecordsSelected(getRecordsOnPage())"
                            wire:loading.attr="disabled"
                        />
                    </th>
                @endif

                @foreach ($columns as $column)
                    <th
                        {{ $column->getExtraHeaderAttributeBag()->class(['fi-ta-header-cell']) }}
                        @if ($column->isSortable() && $this->getTableSortColumn() === $column->getName())
                            aria-sort="{{ $this->getTableSortDirection() === 'desc' ? 'descending' : 'ascending' }}"
                        @endif
                    >
                        @if ($column->isSortable())
                            <button type="button" class="fi-ta-header-cell-sort-btn" wire:click="sortTable('{{ $column->getName() }}')" wire:loading.attr="disabled">
                                {{ $column->getLabel() }}
                                <x-filament::icon
                                    :icon="$this->getTableSortColumn() === $column->getName() && $this->getTableSortDirection() === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down'"
                                    class="fi-icon"
                                />
                            </button>
                        @else
                            {{ $column->getLabel() }}
                        @endif
                    </th>
                @endforeach

                @if (filled($actions))
                    <th class="fi-ta-actions-header-cell fi-ta-empty-header-cell">
                        <span class="fi-sr-only">{{ trans_choice('filament-tables::table.columns.actions.label', count($actions)) }}</span>
                    </th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($this->getSuppliers() as $supplier)
                @php
                    $supplierKey = 'supplier:'.$supplier->id;
                    $isLoaded = isset($this->loadedSuppliers[$supplier->id]);
                    $weeks = $this->weeksFor($supplier->id);
                    $supplierRecords = $recordsBySupplier->get($supplier->id, collect());
                @endphp
                <tr class="fi-supplier-header-row" wire:key="{{ $this->getId() }}.supplier-group.{{ $supplier->id }}" data-supplier-group="{{ $supplier->id }}">
                    <td colspan="{{ $colspan }}">
                        <button
                            type="button"
                            class="fi-supplier-group-button"
                            x-on:click="toggleSupplier({{ $supplier->id }})"
                            x-bind:disabled="loadingSuppliers.includes({{ $supplier->id }})"
                            x-bind:aria-expanded="expandedSuppliers.includes({{ $supplier->id }})"
                        >
                            <span class="fi-supplier-group-chevron" x-bind:class="{ 'fi-expanded': expandedSuppliers.includes({{ $supplier->id }}) }">
                                <x-filament::icon icon="heroicon-m-chevron-right" class="size-4" />
                            </span>
                            {{ $supplier->name }}
                        </button>
                    </td>
                </tr>

                @if ($isLoaded)
                    @foreach ($weeks as $week)
                        @php
                            $weekKey = $supplierKey.':week:'.$week['key'];
                            $weekIsLoaded = in_array($week['key'], $this->loadedWeeks[$supplier->id] ?? [], true);
                            $weekRecords = $supplierRecords->filter(fn ($record): bool => $week['key'] === 'undated'
                                ? $record->created_at === null
                                : $record->created_at?->toDateString() >= $week['start'] && $record->created_at?->toDateString() <= $week['end']);
                        @endphp
                        <tr class="fi-supplier-week-row" wire:key="{{ $this->getId() }}.supplier-week.{{ $weekKey }}" data-supplier-week="{{ $weekKey }}" x-show="expandedSuppliers.includes({{ $supplier->id }})">
                            <td colspan="{{ $colspan }}">
                                <button
                                    type="button"
                                    class="fi-supplier-group-button fi-supplier-week-button"
                                    x-on:click="toggleWeek({{ $supplier->id }}, @js($weekKey), @js($week['key']))"
                                    x-bind:disabled="loadingWeeks.includes(@js($weekKey))"
                                    x-bind:aria-expanded="expandedWeeks.includes(@js($weekKey))"
                                >
                                    <span class="fi-supplier-group-chevron" x-bind:class="{ 'fi-expanded': expandedWeeks.includes(@js($weekKey)) }">
                                        <x-filament::icon icon="heroicon-m-chevron-right" class="size-4" />
                                    </span>
                                    <span>{{ $week['label'] }}</span>
                                </button>
                            </td>
                        </tr>

                        @if ($weekIsLoaded)
                            @foreach ($weekRecords as $record)
                                @php
                                    $recordKey = $this->getTableRecordKey($record);
                                    $recordUrl = $table->getRecordUrl($record);
                                @endphp
                                <tr
                                    class="fi-ta-row"
                                    wire:key="{{ $this->getId() }}.table.records.{{ $recordKey }}"
                                    data-supplier-product="{{ $recordKey }}"
                                    data-supplier-owner="{{ $record->producer_id }}"
                                    data-product-week="{{ $weekKey }}"
                                    x-show="expandedSuppliers.includes({{ $supplier->id }}) && expandedWeeks.includes(@js($weekKey))"
                                    x-bind:class="{ 'fi-selected': isRecordSelected(@js($recordKey)) }"
                                >
                                    @if ($selectionEnabled)
                                        <td class="fi-ta-cell fi-ta-selection-cell">
                                            @if ($table->isRecordSelectable($record))
                                                <input
                                                    type="checkbox"
                                                    class="fi-ta-record-checkbox fi-checkbox-input"
                                                    aria-label="{{ __('filament-tables::table.fields.bulk_select_record.label', ['key' => $recordKey]) }}"
                                                    value="{{ $recordKey }}"
                                                    data-group="{{ $weekKey }}"
                                                    @disabled($selectionDisabled)
                                                    x-on:click="toggleSelectedRecord(@js($recordKey))"
                                                    x-bind:checked="isRecordSelected(@js($recordKey))"
                                                    wire:loading.attr="disabled"
                                                />
                                            @endif
                                        </td>
                                    @endif

                                    @foreach ($columns as $column)
                                        @php($column->record($record)->recordKey($recordKey)->rowLoop($loop->parent))
                                        <td wire:key="{{ $this->getId() }}.table.record.{{ $recordKey }}.column.{{ $column->getName() }}" {{ $column->getExtraCellAttributeBag()->class(['fi-ta-cell']) }}>
                                            @if ($column->getName() !== 'supplier.name')
                                                <{{ $recordUrl ? 'a' : 'div' }} class="fi-ta-col" @if ($recordUrl) href="{{ $recordUrl }}" @endif>
                                                    {{ $column }}
                                                </{{ $recordUrl ? 'a' : 'div' }}>
                                            @endif
                                        </td>
                                    @endforeach

                                    @if (filled($actions))
                                        <td class="fi-ta-cell">
                                            <div class="fi-ta-actions">
                                                @foreach ($actions as $action)
                                                    {{ $action->getClone()->record($record) }}
                                                @endforeach
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        @endif
                    @endforeach
                @endif
            @endforeach
        </tbody>
    </table>
</div>
