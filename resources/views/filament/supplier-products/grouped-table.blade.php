@php
    $table = $this->getTable();
    $columns = $table->getVisibleColumns();
    $actions = $table->getRecordActions();
    $selectionEnabled = $table->isSelectionEnabled();
    $selectionDisabled = $table->isSelectionDisabled();
    $colspan = count($columns) + (int) $selectionEnabled + (int) filled($actions);
    $previousSupplierKey = null;
    $previousWeekKey = null;
@endphp

<div class="fi-supplier-products-grouped" data-supplier-products-grouped>
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
                            <button
                                type="button"
                                class="fi-ta-header-cell-sort-btn"
                                wire:click="sortTable('{{ $column->getName() }}')"
                                wire:loading.attr="disabled"
                            >
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
            @foreach ($records as $record)
                @php
                    $recordKey = $this->getTableRecordKey($record);
                    $recordUrl = $table->getRecordUrl($record);
                    $supplierKey = 'supplier:'.$record->producer_id;
                    $weekKey = $supplierKey.':week:'.($this->getTableGrouping()->getStringKey($record) ?? 'undated');
                @endphp

                @if ($supplierKey !== $previousSupplierKey)
                    <tr
                        class="fi-supplier-header-row"
                        wire:key="{{ $this->getId() }}.supplier-group.{{ $supplierKey }}"
                        data-supplier-group="{{ $record->producer_id }}"
                    >
                        <td colspan="{{ $colspan }}">
                            <button
                                type="button"
                                class="fi-supplier-group-button"
                                x-on:click="toggleCollapseGroup(@js($supplierKey))"
                                x-bind:aria-expanded="! isGroupCollapsed(@js($supplierKey))"
                            >
                                <span
                                    class="fi-supplier-group-chevron"
                                    x-bind:class="{ 'fi-expanded': ! isGroupCollapsed(@js($supplierKey)) }"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="size-4" />
                                </span>
                                {{ $record->supplier?->name ?? __('Supplier') }}
                            </button>
                        </td>
                    </tr>
                @endif

                @if ($weekKey !== $previousWeekKey)
                    <tr
                        class="fi-supplier-week-row"
                        wire:key="{{ $this->getId() }}.supplier-week.{{ $weekKey }}"
                        data-supplier-week="{{ $weekKey }}"
                        x-show="! isGroupCollapsed(@js($supplierKey))"
                    >
                        <td colspan="{{ $colspan }}">
                            <button
                                type="button"
                                class="fi-supplier-group-button fi-supplier-week-button"
                                x-on:click="toggleCollapseGroup(@js($weekKey))"
                                x-bind:aria-expanded="! isGroupCollapsed(@js($weekKey))"
                                x-bind:class="{ 'fi-expanded': ! isGroupCollapsed(@js($weekKey)) }"
                            >
                                <span
                                    class="fi-supplier-group-chevron"
                                    x-bind:class="{ 'fi-expanded': ! isGroupCollapsed(@js($weekKey)) }"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="size-4" />
                                </span>
                                @if ($record->created_at)
                                    <span>{{ __('Week :number', ['number' => $record->created_at->isoWeek()]) }}</span>
                                    <span class="fi-supplier-week-dates">{{ \App\Support\CreationDateTable::weekLabel($record->created_at) }}</span>
                                @else
                                    <span>{{ __('Without creation date') }}</span>
                                @endif
                            </button>
                        </td>
                    </tr>
                @endif

                <tr
                    class="fi-ta-row"
                    wire:key="{{ $this->getId() }}.table.records.{{ $recordKey }}"
                    data-supplier-product="{{ $recordKey }}"
                    data-supplier-owner="{{ $record->producer_id }}"
                    data-product-week="{{ $weekKey }}"
                    x-show="! isGroupCollapsed(@js($supplierKey)) && ! isGroupCollapsed(@js($weekKey))"
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
                        @php
                            $column->record($record)->recordKey($recordKey)->rowLoop($loop->parent);
                        @endphp
                        <td
                            wire:key="{{ $this->getId() }}.table.record.{{ $recordKey }}.column.{{ $column->getName() }}"
                            {{ $column->getExtraCellAttributeBag()->class(['fi-ta-cell']) }}
                        >
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

                @php
                    $previousSupplierKey = $supplierKey;
                    $previousWeekKey = $weekKey;
                @endphp
            @endforeach
        </tbody>
    </table>
</div>
