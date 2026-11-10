<?php

namespace App\Modules\Reports\Filament\Pages;

use App\Modules\CustomerOffers\Filament\Resources\CustomerOffers\CustomerOfferResource;
use App\Modules\CustomerOffers\Models\CustomerOffer;
use App\Modules\Reports\Data\OfferRevenue;
use App\Modules\Reports\Services\OfferRevenueCalculator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class Revenues extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'revenues';

    protected Width|string|null $maxContentWidth = Width::Full;

    /** @var array<int, OfferRevenue> */
    protected array $revenueCache = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isSuperAdmin() ?? false)
            || (($user?->isSalesAgent() ?? false) && $user->can('ViewAny:CustomerOffer'));
    }

    public function getTitle(): string
    {
        return __('Revenue');
    }

    public static function getNavigationLabel(): string
    {
        return __('Revenue');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Sales');
    }

    public function getSubheading(): string
    {
        return __('Profit per offer, excluding VAT. Selected supplier quantities and product prices are used; transport is deducted once. Delivered, invoiced and paid sales are realized; other offers are estimated.');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getQuery())
            ->recordTitleAttribute('offer_number')
            ->defaultSort('offer_date', 'desc')
            ->columns([
                TextColumn::make('offer_number')->label(__('Offer number'))->searchable()->sortable(),
                TextColumn::make('customer.name')->label(__('Customer'))->searchable()->sortable()->limit(28),
                TextColumn::make('tenant.name')->label(__('Tenant'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('offer_date')->label(__('Offer date'))->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('salesOrder.order_number')->label(__('Order number'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gain_status')
                    ->label(__('Profit status'))
                    ->state(fn (CustomerOffer $record): string => $this->isRealized($record) ? __('Realized profit') : __('Estimated profit'))
                    ->badge()
                    ->wrap()
                    ->description(function (CustomerOffer $record): ?string {
                        $revenue = $this->revenue($record);

                        return ! $revenue->isComplete ? __('Incomplete quantities, costs or exchange rates')
                            : ($revenue->usesEstimatedQuantities ? __('Unconfirmed quantities allocated by supplier priority') : null);
                    })
                    ->color(fn (CustomerOffer $record): string => $this->isRealized($record) ? 'success' : 'warning'),
                $this->amountColumn('sales_total', __('Sales'), fn (OfferRevenue $revenue): ?float => $revenue->salesTotal)->tooltip(__('Total sales')),
                $this->amountColumn('product_cost', __('Product cost'), fn (OfferRevenue $revenue): ?float => $revenue->productCost)->tooltip(__('Product purchase cost')),
                $this->amountColumn('gross_margin', __('Margin'), fn (OfferRevenue $revenue): ?float => $revenue->margin())->tooltip(__('Margin before transport')),
                $this->amountColumn('transport_cost', __('Transport'), fn (OfferRevenue $revenue): ?float => $revenue->transportCost)
                    ->description(fn (CustomerOffer $record): ?string => $this->revenue($record)->hasBundledCosts ? __('Included transport is not deducted again') : null),
                $this->amountColumn('net_profit', __('Net profit'), fn (OfferRevenue $revenue): ?float => $revenue->profit())
                    ->tooltip(__('Profit after transport'))
                    ->weight('bold')
                    ->color(fn (CustomerOffer $record): string => ($this->revenue($record)->profit() ?? 0) < 0 ? 'danger' : 'success'),
            ])
            ->filters([
                SelectFilter::make('gain_status')
                    ->label(__('Profit status'))
                    ->options(['estimated' => __('Estimated profit'), 'realized' => __('Realized profit')])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'realized' => $query->whereHas('salesOrder', fn (Builder $order): Builder => $order->whereIn('status', OfferRevenueCalculator::REALIZED_ORDER_STATUSES)),
                            'estimated' => $query->whereDoesntHave('salesOrder', fn (Builder $order): Builder => $order->whereIn('status', OfferRevenueCalculator::REALIZED_ORDER_STATUSES)),
                            default => $query,
                        };
                    }),
                SelectFilter::make('currency')->label(__('Currency'))->options(['EUR' => 'EUR', 'RON' => 'RON', 'USD' => 'USD', 'GBP' => 'GBP']),
                Filter::make('offer_date')
                    ->schema([DatePicker::make('from')->label(__('From')), DatePicker::make('until')->label(__('To'))])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('offer_date', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('offer_date', '<=', $date))),
            ])
            ->recordActions([
                Action::make('openOffer')->label(__('Open offer'))->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->iconButton()
                    ->visible(fn (CustomerOffer $record): bool => CustomerOfferResource::canEdit($record))
                    ->url(fn (CustomerOffer $record): string => CustomerOfferResource::getUrl('edit', ['record' => $record])),
            ]);
    }

    public function getQuery(): Builder
    {
        return CustomerOfferResource::getEloquentQuery()
            ->with(['tenant', 'customer', 'items.suppliers', 'salesOrder.items'])
            ->whereNotIn('status', ['rejected', 'cancelled', 'expired'])
            ->whereDoesntHave('salesOrder', fn (Builder $query): Builder => $query->where('status', 'cancelled'));
    }

    private function isRealized(CustomerOffer $offer): bool
    {
        return in_array($offer->salesOrder?->status, OfferRevenueCalculator::REALIZED_ORDER_STATUSES, true);
    }

    private function revenue(CustomerOffer $offer): OfferRevenue
    {
        return $this->revenueCache[$offer->getKey()] ??= app(OfferRevenueCalculator::class)->calculate($offer);
    }

    private function amountColumn(string $name, string $label, \Closure $value): TextColumn
    {
        return TextColumn::make($name)->label($label)
            ->state(fn (CustomerOffer $record): ?float => $value($this->revenue($record)))
            ->money(fn (CustomerOffer $record): string => $record->currency)
            ->placeholder('—');
    }
}
