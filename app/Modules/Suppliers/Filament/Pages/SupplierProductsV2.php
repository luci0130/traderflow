<?php

namespace App\Modules\Suppliers\Filament\Pages;

use App\Modules\Producers\Models\SupplierProduct;
use App\Modules\Suppliers\Filament\Actions\ImportSupplierProductsAction;
use App\Modules\Suppliers\Filament\Resources\SupplierProducts\SupplierProductResource;
use App\Modules\Suppliers\Models\Supplier;
use App\Support\CreationDateTable;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use UnitEnum;

class SupplierProductsV2 extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 46;

    protected static ?string $slug = 'supplier-products-v2';

    protected string $view = 'filament.pages.supplier-products-v2';

    protected Width|string|null $maxContentWidth = 'full';

    /** @var array<int, true> */
    #[Locked]
    public array $loadedSuppliers = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    #[Locked]
    public array $supplierWeeks = [];

    /** @var array<int, array<int, string>> */
    #[Locked]
    public array $loadedWeeks = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('ViewAny:SupplierProduct') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('Supplier Products');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Catalog');
    }

    public function getTitle(): string
    {
        return __('Supplier Products');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        SupplierProductResource::table($table->query(fn (): Builder => $this->getLoadedProductsQuery()));

        return $table
            ->recordUrl(fn (SupplierProduct $record): ?string => SupplierProductResource::canEdit($record)
                ? SupplierProductResource::getUrl('edit', ['record' => $record])
                : null)
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (SupplierProduct $record): bool => SupplierProductResource::canEdit($record))
                    ->url(fn (SupplierProduct $record): string => SupplierProductResource::getUrl('edit', ['record' => $record])),
            ])
            ->paginated(false)
            ->content(fn (): View => view('filament.supplier-products.v2-grouped-table'))
            ->emptyState(fn (): View => view('filament.supplier-products.v2-grouped-table', ['records' => collect()]));
    }

    /** @return array<int, Supplier> */
    public function getSuppliers(): array
    {
        return Supplier::query()
            ->whereHas('supplierProducts')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function weeksFor(int $supplierId): array
    {
        return $this->supplierWeeks[$supplierId] ?? [];
    }

    public function loadSupplier(int $supplierId): ?string
    {
        if (isset($this->loadedSuppliers[$supplierId])) {
            return $this->loadedWeeks[$supplierId][0] ?? null;
        }

        $supplier = Supplier::query()->whereHas('supplierProducts')->findOrFail($supplierId);
        $this->loadedSuppliers[$supplierId] = true;
        $this->supplierWeeks[$supplierId] = $this->getWeekGroups($supplier);
        $latestWeek = $this->supplierWeeks[$supplierId][0]['key'] ?? null;

        if ($latestWeek !== null) {
            $this->loadedWeeks[$supplierId] = [$latestWeek];
        }

        return $latestWeek;
    }

    public function loadWeek(int $supplierId, string $weekKey): bool
    {
        abort_unless(isset($this->loadedSuppliers[$supplierId]), 404);

        $weekExists = collect($this->supplierWeeks[$supplierId] ?? [])->contains('key', $weekKey);
        abort_unless($weekExists, 404);

        if (in_array($weekKey, $this->loadedWeeks[$supplierId] ?? [], true)) {
            return false;
        }

        $this->loadedWeeks[$supplierId][] = $weekKey;

        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            ImportSupplierProductsAction::make(),
            CreateAction::make()->url(SupplierProductResource::getUrl('create')),
        ];
    }

    private function getLoadedProductsQuery(): Builder
    {
        $query = SupplierProduct::query()
            ->with(['supplier', 'packagingMethod', 'prices', 'imageGalleries.images.roles'])
            ->where(function (Builder $query): void {
                foreach ($this->loadedWeeks as $supplierId => $weekKeys) {
                    foreach ($weekKeys as $weekKey) {
                        $week = collect($this->supplierWeeks[$supplierId] ?? [])->firstWhere('key', $weekKey);

                        if ($week === null) {
                            continue;
                        }

                        $query->orWhere(function (Builder $query) use ($supplierId, $week): void {
                            $query->where('producer_id', $supplierId);

                            if ($week['key'] === 'undated') {
                                $query->whereNull('created_at');

                                return;
                            }

                            $query->whereBetween('created_at', [
                                Carbon::parse($week['start'])->startOfDay(),
                                Carbon::parse($week['end'])->endOfDay(),
                            ]);
                        });
                    }
                }
            })
            ->orderBy(Supplier::query()->select('name')->whereColumn('suppliers.id', 'supplier_products.producer_id'))
            ->orderBy('producer_id')
            ->orderByDesc('created_at')
            ->orderBy('name');

        if ($this->loadedWeeks === []) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    /** @return array<int, array<string, mixed>> */
    private function getWeekGroups(Supplier $supplier): array
    {
        $dates = $supplier->supplierProducts()
            ->selectRaw('DISTINCT DATE(created_at) as product_date')
            ->orderByDesc('product_date')
            ->get();

        $weeks = [];

        foreach ($dates as $date) {
            if ($date->product_date === null) {
                $weeks['undated'] = [
                    'key' => 'undated',
                    'start' => null,
                    'end' => null,
                    'label' => __('Without creation date'),
                ];

                continue;
            }

            $createdAt = Carbon::parse($date->product_date);
            $start = $createdAt->copy()->startOfWeek();
            $end = $createdAt->copy()->endOfWeek();
            $key = $start->toDateString();

            if (! isset($weeks[$key])) {
                $weeks[$key] = [
                    'key' => $key,
                    'start' => $start->toDateString(),
                    'end' => $end->toDateString(),
                    'label' => __('Week :number', ['number' => $start->isoWeek()]).' · '.CreationDateTable::weekLabel($start),
                ];
            }
        }

        return array_values($weeks);
    }
}
