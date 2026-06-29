<?php

namespace App\Modules\ProductCategories\Filament\Resources\ProductCategories\Pages;

use App\Modules\ProductCategories\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Modules\ProductCategories\Models\ProductCategory;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ProductCategoryTree extends Page
{
    protected static string $resource = ProductCategoryResource::class;

    protected static ?string $title = 'Product Categories Tree';

    protected string $view = 'filament.resources.product-categories.pages.product-category-tree';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public ?int $selectedCategoryId = null;

    public function mount(): void
    {
        $this->selectedCategoryId = $this->getScopedCategoryQuery()
            ->orderBy('name')
            ->value('id');

        $this->fillFormFromSelectedCategory();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Select::make('parent_id')
                        ->label('Parent category')
                        ->options(fn (): array => $this->getParentCategoryOptions())
                        ->placeholder('No parent category')
                        ->selectablePlaceholder()
                        ->nullable()
                        ->searchable(),
                    Select::make('status')
                        ->options([
                            'active' => 'Active',
                            'inactive' => 'Inactive',
                        ])
                        ->default('active')
                        ->required(),
                ])
                    ->columns(2)
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save changes')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->record($this->getSelectedCategory())
            ->statePath('data');
    }

    public function selectCategory(int $categoryId): void
    {
        $category = $this->getScopedCategoryQuery()
            ->whereKey($categoryId)
            ->first();

        if ($category === null) {
            return;
        }

        $this->selectedCategoryId = $category->getKey();

        $this->fillFormFromSelectedCategory();
    }

    public function save(): void
    {
        $category = $this->getSelectedCategory();

        if ($category === null) {
            return;
        }

        $data = $this->form->getState();
        $parentId = filled($data['parent_id'] ?? null) ? (int) $data['parent_id'] : null;

        if (($parentId !== null) && in_array($parentId, $this->getForbiddenParentIds(), true)) {
            throw ValidationException::withMessages([
                'data.parent_id' => __('A category cannot be moved under itself or one of its children.'),
            ]);
        }

        $category->fill([
            'name' => $data['name'],
            'parent_id' => $parentId,
            'status' => $data['status'],
        ]);
        $category->save();

        $this->fillFormFromSelectedCategory();

        Notification::make()
            ->success()
            ->title('Category saved')
            ->send();
    }

    /**
     * @return array<int, array{id: int, name: string, status: string, products_count: int, children: array<int, mixed>}>
     */
    public function getCategoryTree(): array
    {
        $categories = $this->getTreeCategories();
        $categoryIds = $categories->pluck('id')->all();

        $childrenByParent = $categories->groupBy('parent_id');
        $roots = $categories->filter(
            fn (ProductCategory $category): bool => ($category->parent_id === null) || ! in_array($category->parent_id, $categoryIds, true),
        );

        return $roots
            ->map(fn (ProductCategory $category): array => $this->mapCategoryTreeNode($category, $childrenByParent))
            ->values()
            ->all();
    }

    public function getSelectedCategory(): ?ProductCategory
    {
        if ($this->selectedCategoryId === null) {
            return null;
        }

        return $this->getScopedCategoryQuery()
            ->whereKey($this->selectedCategoryId)
            ->first();
    }

    public function getTotalCategoryCount(): int
    {
        return $this->getScopedCategoryQuery()->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('table')
                ->label('Table')
                ->icon(Heroicon::OutlinedListBullet)
                ->url($this->getResourceUrl())
                ->color('gray'),
        ];
    }

    protected function fillFormFromSelectedCategory(): void
    {
        $category = $this->getSelectedCategory();

        $this->form->fill($category?->only([
            'name',
            'parent_id',
            'status',
        ]) ?? []);
    }

    protected function getScopedCategoryQuery(): Builder
    {
        return ProductCategoryResource::getEloquentQuery();
    }

    /**
     * @return array<int|string, string>
     */
    protected function getParentCategoryOptions(): array
    {
        $forbiddenParentIds = $this->getForbiddenParentIds();

        $categoryOptions = $this->getScopedCategoryQuery()
            ->whereNotIn('id', $forbiddenParentIds)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return ['' => 'No parent category'] + $categoryOptions;
    }

    /**
     * @return array<int, int>
     */
    protected function getForbiddenParentIds(): array
    {
        if ($this->selectedCategoryId === null) {
            return [];
        }

        $categories = $this->getScopedCategoryQuery()
            ->select(['id', 'parent_id'])
            ->get();

        $childrenByParent = $categories->groupBy('parent_id');
        $forbiddenParentIds = [$this->selectedCategoryId];

        $collectDescendants = function (int $parentId) use (&$collectDescendants, $childrenByParent, &$forbiddenParentIds): void {
            foreach ($childrenByParent->get($parentId, collect()) as $child) {
                $childId = (int) $child->id;

                $forbiddenParentIds[] = $childId;

                $collectDescendants($childId);
            }
        };

        $collectDescendants($this->selectedCategoryId);

        return array_values(array_unique($forbiddenParentIds));
    }

    /**
     * @return Collection<int, ProductCategory>
     */
    protected function getTreeCategories(): Collection
    {
        return $this->getScopedCategoryQuery()
            ->select(['id', 'tenant_id', 'parent_id', 'name', 'status'])
            ->withCount('products')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int|string, Collection<int, ProductCategory>>  $childrenByParent
     * @return array{id: int, name: string, status: string, products_count: int, children: array<int, mixed>}
     */
    protected function mapCategoryTreeNode(ProductCategory $category, Collection $childrenByParent): array
    {
        return [
            'id' => (int) $category->id,
            'name' => $category->name,
            'status' => $category->status,
            'products_count' => (int) ($category->products_count ?? 0),
            'children' => $childrenByParent
                ->get($category->id, collect())
                ->map(fn (ProductCategory $child): array => $this->mapCategoryTreeNode($child, $childrenByParent))
                ->values()
                ->all(),
        ];
    }
}
