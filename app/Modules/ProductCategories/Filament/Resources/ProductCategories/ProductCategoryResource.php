<?php

namespace App\Modules\ProductCategories\Filament\Resources\ProductCategories;

use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\ProductCategoryTree;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Support\StatusColors;
use App\Support\Tenancy\ActiveTenant;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProductCategoryResource extends Resource
{
    protected static ?string $model = ProductCategory::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('Product Category');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Product Categories');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleToTenant(static::getActiveTenantId());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('parent_id')
                    ->label('Parent category')
                    ->options(fn (): array => ProductCategory::query()
                        ->visibleToTenant(static::getActiveTenantId())
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ])
                    ->default('active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): array => StatusColors::badge($state))
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductCategories::route('/'),
            'tree' => ProductCategoryTree::route('/tree'),
            'create' => CreateProductCategory::route('/create'),
            'edit' => EditProductCategory::route('/{record}/edit'),
        ];
    }

    public static function getActiveTenantId(): ?int
    {
        return Filament::getTenant()?->getKey() ?? app(ActiveTenant::class)->id();
    }
}
