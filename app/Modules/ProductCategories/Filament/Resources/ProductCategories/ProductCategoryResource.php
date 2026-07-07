<?php

namespace App\Modules\ProductCategories\Filament\Resources\ProductCategories;

use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages\ProductCategoryTree;
use App\Modules\ProductCategories\Models\ProductCategory;
use App\Support\RemoteImage;
use App\Support\StatusColors;
use App\Support\Tenancy\ActiveTenant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

class ProductCategoryResource extends Resource
{
    protected static ?string $model = ProductCategory::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

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
        return __('Administration');
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
                static::imageUploadField()
                    ->columnSpanFull(),
                static::imageUrlField()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * A square category thumbnail. FilePond crops to 1:1 and downscales the
     * image to 400×400 in the browser before uploading, so every category gets
     * a small, uniform, already-optimized picture without any server tooling.
     */
    public static function imageUploadField(): FileUpload
    {
        return FileUpload::make('image_path')
            ->label(__('Image'))
            ->image()
            ->disk('public')
            ->directory('product-categories')
            ->imageEditor()
            ->imageEditorAspectRatios(['1:1'])
            ->automaticallyCropImagesToAspectRatio('1:1')
            ->automaticallyResizeImagesMode('cover')
            ->automaticallyResizeImagesToWidth('400')
            ->automaticallyResizeImagesToHeight('400')
            ->maxSize(5120);
    }

    /**
     * Paste an image URL and download it straight onto the category. The image
     * is optimized (square, 400×400, WebP) exactly like an uploaded one, then
     * dropped into the upload field above so it previews and saves normally.
     */
    public static function imageUrlField(): TextInput
    {
        return TextInput::make('image_url')
            ->label(__('Download image from URL'))
            ->placeholder('https://…')
            ->url()
            ->dehydrated(false)
            ->live(onBlur: true)
            ->suffixAction(
                Action::make('fetchImageFromUrl')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(function (Get $get, Set $set): void {
                        $url = trim((string) $get('image_url'));

                        if ($url === '') {
                            Notification::make()->warning()->title(__('Enter an image URL first.'))->send();

                            return;
                        }

                        try {
                            $path = app(RemoteImage::class)->storeSquare(
                                $url,
                                'product-categories',
                                Str::slug(Str::of((string) $get('name'))->limit(40, '') ?: 'category').'-'.Str::random(6),
                            );
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title(__('Could not download the image'))->body($e->getMessage())->send();

                            return;
                        }

                        // FileUpload state is an array of file paths, not a bare string.
                        $set('image_path', [$path]);
                        $set('image_url', null);

                        Notification::make()->success()->title(__('Image downloaded'))->send();
                    }),
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                ImageColumn::make('image_path')
                    ->label(__('Image'))
                    ->disk('public')
                    ->square()
                    ->size(44),
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
