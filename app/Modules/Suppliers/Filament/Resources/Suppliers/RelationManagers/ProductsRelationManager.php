<?php

namespace App\Modules\Suppliers\Filament\Resources\Suppliers\RelationManagers;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Products\Models\PackagingMethod;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Support\Countries;
use App\Support\StatusColors;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierProducts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Products');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->supplierProducts()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Product'))
                    ->schema([
                        TextInput::make('name')->label(__('Name'))->required()->maxLength(255),
                        Select::make('category')
                            ->label(__('Category'))
                            ->options(fn (): array => SupplierProductResource::categoryOptions())
                            ->searchable(),
                        Select::make('country_of_origin')
                            ->label(__('Country of origin'))
                            ->options(Countries::options())
                            ->searchable()
                            ->default('RO'),
                        Toggle::make('is_bio')
                            ->label(__('Eco (organic)'))
                            ->inline(false),
                    ])
                    ->columns(2),
                Section::make(__('Advanced details'))
                    ->collapsed()
                    ->schema([
                        Select::make('status')
                            ->label(__('Status'))
                            ->options([
                                'active' => __('Active'),
                                'archived' => __('Archived'),
                            ])
                            ->default('active')
                            ->required(),
                        TextInput::make('variety')->label(__('Variety'))->maxLength(255),
                        TextInput::make('caliber')->label(__('Caliber'))->maxLength(255),
                        TextInput::make('quality')->label(__('Quality'))->maxLength(255),
                        TextInput::make('default_packaging')->label(__('Default packaging'))->maxLength(255),
                        Textarea::make('description')->label(__('Description'))->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make(__('Packaging'))
                    ->columns(3)
                    ->schema([
                        Select::make('packaging_method_id')
                            ->label(__('Packaging method'))
                            ->options(fn (): array => SupplierProductResource::packagingMethodOptions())
                            ->default(fn (): ?int => PackagingMethod::query()->where('name', 'Vrac')->value('id'))
                            ->searchable()
                            ->preload(),
                        TextInput::make('package_size')
                            ->label(__('Package size'))
                            ->numeric()
                            ->minValue(0),
                        Select::make('min_quantity_unit')
                            ->label(__('Unit'))
                            ->options(fn (): array => SupplierProductResource::unitOptions())
                            ->default('kg')
                            ->searchable()
                            ->required(),
                    ]),
                Section::make(__('Pricing'))
                    ->schema([
                        TextInput::make('min_quantity_value')->label(__('Minimum quantity'))->numeric(),
                        TextInput::make('unit_price')->label(__('Unit price'))->numeric(),
                        Select::make('currency')
                            ->label(__('Currency'))
                            ->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP']),
                    ])
                    ->columns(2),
                Section::make(__('Sourcing cost overrides'))
                    ->description(__('Leave a field empty to inherit the supplier default.'))
                    ->collapsed()
                    ->relationship('costOverride')
                    ->schema([
                        TextInput::make('packaging_cost')
                            ->label(__('Packaging cost'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('transport_cost')
                            ->label(__('Transport cost'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('commission')
                            ->label(__('Commission'))
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('profit_margin')
                            ->label(__('Profit margin'))
                            ->numeric()
                            ->minValue(0),
                    ])
                    ->columns(2),
                Section::make(__('Offer'))
                    ->schema([
                        TextInput::make('quantity_available')->label(__('Quantity available'))->numeric(),
                        DatePicker::make('valid_until')
                            ->label(__('Offer valid until'))
                            ->native(false),
                    ])
                    ->columns(2),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('image_path')
                    ->label(__('Photo'))
                    ->disk('public')
                    ->square()
                    ->size(40),
                TextColumn::make('name')->label(__('Name'))->searchable()->sortable(),
                IconColumn::make('is_bio')
                    ->label(__('Bio'))
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('quantity_available')
                    ->label(__('Available'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->suffix(fn (SupplierProduct $record): string => ' '.($record->min_quantity_unit ?? '')),
                TextColumn::make('unit_price')
                    ->label(__('Price'))
                    ->numeric(decimalPlaces: 2)
                    ->sortable()
                    ->suffix(fn (SupplierProduct $record): string => ' '.($record->currency ?? '')),
                TextColumn::make('min_quantity_value')
                    ->label(__('Min qty'))
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),
                TextColumn::make('valid_until')
                    ->label(__('Valid until'))
                    ->date()
                    ->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()->color(fn (?string $state): array => StatusColors::badge($state)),
                TextColumn::make('created_at')
                    ->label(__('Added'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => __('Active'),
                        'archived' => __('Archived'),
                    ]),
                TernaryFilter::make('valid')
                    ->label(__('Currently valid'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Valid'))
                    ->falseLabel(__('Expired'))
                    ->queries(
                        true: fn (Builder $q): Builder => $q->where('status', 'active')->whereDate('valid_until', '>=', today()),
                        false: fn (Builder $q): Builder => $q->where(fn (Builder $sub): Builder => $sub->where('status', '!=', 'active')->orWhereDate('valid_until', '<', today())),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
