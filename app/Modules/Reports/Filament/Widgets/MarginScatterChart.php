<?php

namespace App\Modules\Reports\Filament\Widgets;

use App\Modules\Customers\Models\Customer;
use App\Modules\Products\Models\Product;
use App\Support\StatusColors;
use Carbon\CarbonInterface;
use Filament\Forms\Components\Select;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\ChartWidget\Concerns\HasFiltersSchema;
use Illuminate\Support\Collection;

/**
 * Shared base for the accept/reject margin scatter charts. One entity is picked as
 * the primary filter (product or supermarket) and the chart plots, over time, the
 * profit margins the counterpart accepted (green, joined by a line) or rejected
 * (red); each point is labelled with the counterpart's name. Subclasses only supply
 * the filters, the per-entity query (as rows) and the heading/description.
 */
abstract class MarginScatterChart extends ChartWidget
{
    use HasFiltersSchema;

    // Render eagerly: lazy widgets mount in a follow-up request that drops the page's
    // query string, so the primary-entity filter could never default from a deep link.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '480px';

    protected function getType(): string
    {
        return 'scatter';
    }

    /**
     * Offer statuses plotted, each with its own colour and legend entry. Order here
     * is the dataset/legend order. Only accepted offers are joined by a line (the
     * successful margin trajectory); the rest are scatter points.
     *
     * @return array<string, array{label: string, color: string, line: bool, labelPosition: string}>
     */
    protected function statusStyles(): array
    {
        return [
            'accepted' => ['label' => __('Accepted'), 'color' => StatusColors::hex('accepted'), 'line' => true, 'labelPosition' => 'above'],
            'rejected' => ['label' => __('Rejected'), 'color' => StatusColors::hex('rejected'), 'line' => false, 'labelPosition' => 'below'],
            'sent' => ['label' => __('Sent'), 'color' => StatusColors::hex('sent'), 'line' => false, 'labelPosition' => 'above'],
            'received' => ['label' => __('Received'), 'color' => StatusColors::hex('received'), 'line' => false, 'labelPosition' => 'below'],
            'draft' => ['label' => __('Draft'), 'color' => StatusColors::hex('draft'), 'line' => false, 'labelPosition' => 'below'],
            'expired' => ['label' => __('Expired'), 'color' => StatusColors::hex('expired'), 'line' => false, 'labelPosition' => 'above'],
            'cancelled' => ['label' => __('Cancelled'), 'color' => StatusColors::hex('cancelled'), 'line' => false, 'labelPosition' => 'below'],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function chartStatuses(): array
    {
        return array_keys($this->statusStyles());
    }

    /**
     * Turn flat rows into one coloured dataset per offer status, plus the data-borne
     * axis hints (margin unit, time bounds) the JS callbacks/plugins read live.
     *
     * @param  array<int, array{x: ?string, y: float, label: string, status: string, currency: ?string}>  $rows
     * @return array<string, mixed>
     */
    protected function buildDatasets(array $rows, bool $usesPercent): array
    {
        $pointsByStatus = [];
        $labelsByStatus = [];
        $currencies = [];

        foreach ($rows as $row) {
            $status = $row['status'];
            $pointsByStatus[$status][] = ['x' => $row['x'], 'y' => $row['y']];
            $labelsByStatus[$status][] = $row['label'];
            $currencies[(string) $row['currency']] = true;
        }

        $datasets = [];
        $allPoints = [];

        foreach ($this->statusStyles() as $status => $style) {
            if (empty($pointsByStatus[$status])) {
                continue;
            }

            // Sort each status by time so the (accepted) line runs left to right.
            [$points, $labels] = $this->sortByTime($pointsByStatus[$status], $labelsByStatus[$status]);
            $allPoints = array_merge($allPoints, $points);

            $datasets[] = [
                'label' => $style['label'],
                'data' => $points,
                'pointLabels' => $labels,
                'labelPosition' => $style['labelPosition'],
                'backgroundColor' => $style['color'],
                'borderColor' => $style['color'],
                'showLine' => $style['line'],
                'pointRadius' => 5,
                'pointHoverRadius' => 7,
            ];
        }

        [$xMin, $xMax] = $this->timeBounds($allPoints, []);

        return [
            // The y-axis unit and x-axis time bounds are read live from `chart.data`
            // by the tick/tooltip callbacks and the timeRange plugin, because Filament
            // only re-applies chart *data* (not options) when a filter changes.
            'marginUnit' => $this->marginUnit($usesPercent, array_keys($currencies)),
            'xMin' => $xMin,
            'xMax' => $xMax,
            'datasets' => $datasets,
        ];
    }

    protected function getOptions(): RawJs
    {
        $yTitle = __('Profit margin');
        $xTitle = __('Accepted / rejected at');

        // RawJs is emitted verbatim into a double-quoted `x-data="..."` attribute,
        // so string literals must be single-quoted (and escaped) to avoid a double
        // quote prematurely closing the attribute.
        $js = fn (string $value): string => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";

        $yTitleJs = $js($yTitle);
        $xTitleJs = $js($xTitle);

        return RawJs::make(<<<JS
            {
                layout: { padding: { top: 28, bottom: 28, right: 16 } },
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            tooltipFormat: 'dd LLL yyyy HH:mm',
                            displayFormats: {
                                millisecond: 'HH:mm:ss',
                                second: 'HH:mm:ss',
                                minute: 'dd LLL HH:mm',
                                hour: 'dd LLL HH:mm',
                                day: 'dd LLL yyyy',
                                week: 'dd LLL yyyy',
                                month: 'LLL yyyy',
                                quarter: 'LLL yyyy',
                                year: 'yyyy',
                            },
                        },
                        ticks: { autoSkip: true, maxTicksLimit: 8, maxRotation: 0 },
                        title: { display: true, text: {$xTitleJs} },
                    },
                    y: {
                        title: { display: true, text: {$yTitleJs} },
                        ticks: {
                            callback: function (value) {
                                return (Math.round(value * 100) / 100) + (this.chart.data.marginUnit ?? '');
                            },
                        },
                    },
                },
                plugins: {
                    legend: { display: true },
                    tooltip: {
                        callbacks: {
                            title: (items) => {
                                if (! items.length) {
                                    return '';
                                }
                                return new Date(items[0].parsed.x).toLocaleString(undefined, {
                                    day: '2-digit', month: 'short', year: 'numeric',
                                    hour: '2-digit', minute: '2-digit',
                                });
                            },
                            label: (ctx) => {
                                const name = ctx.dataset.pointLabels?.[ctx.dataIndex] ?? '';
                                const unit = ctx.chart.data.marginUnit ?? '';
                                return name + ': ' + (Math.round(ctx.parsed.y * 100) / 100) + unit;
                            },
                        },
                    },
                    zoom: {
                        zoom: {
                            wheel: { enabled: true },
                            pinch: { enabled: true },
                            mode: 'x',
                        },
                        pan: { enabled: true, mode: 'x' },
                    },
                },
            }
            JS);
    }

    protected function productFilter(): Select
    {
        return Select::make('product_id')
            ->label(__('Product'))
            ->options(fn (): array => $this->productNames()->all())
            ->searchable()
            ->native(false);
    }

    protected function supermarketFilter(): Select
    {
        return Select::make('supermarket_id')
            ->label(__('Supermarket'))
            ->options(fn (): array => $this->supermarketNames()->all())
            ->searchable()
            ->native(false);
    }

    protected function marginTypeFilter(): Select
    {
        return Select::make('margin_type')
            ->label(__('Profit margin'))
            ->options([
                'percent' => __('Percentage'),
                'fixed' => __('Fixed amount per unit'),
            ])
            ->default('percent')
            ->selectablePlaceholder(false)
            ->native(false);
    }

    protected function periodFilter(): Select
    {
        return Select::make('period')
            ->label(__('Period'))
            ->options([
                'all' => __('All time'),
                'last_month' => __('Last month'),
                'last_3_months' => __('Last 3 months'),
                'last_year' => __('Last year'),
            ])
            ->default('all')
            ->selectablePlaceholder(false)
            ->native(false);
    }

    protected function usesPercent(): bool
    {
        return ($this->filters['margin_type'] ?? 'percent') === 'percent';
    }

    /**
     * The suffix appended to y-axis values: '%' for percentage margins, the shared
     * currency (e.g. ' RON') for fixed per-unit margins, or '' when the plotted
     * offers span multiple currencies (which cannot be compared on one axis).
     *
     * @param  array<int, string>  $currencies
     */
    protected function marginUnit(bool $usesPercent, array $currencies): string
    {
        if ($usesPercent) {
            return '%';
        }

        return count($currencies) === 1 ? ' '.$currencies[0] : '';
    }

    /**
     * Lower bound for the selected period filter; null means all time (no bound).
     */
    protected function periodStart(?string $period): ?CarbonInterface
    {
        return match ($period) {
            'last_month' => now()->subMonth(),
            'last_3_months' => now()->subMonths(3),
            'last_year' => now()->subYear(),
            default => null,
        };
    }

    /**
     * Earliest and latest point timestamps so the chart's time axis spans exactly
     * the first to the last decision in view, instead of padding to round ticks.
     *
     * @param  array<int, array{x: ?string, y: float}>  $accepted
     * @param  array<int, array{x: ?string, y: float}>  $rejected
     * @return array{0: ?string, 1: ?string}
     */
    protected function timeBounds(array $accepted, array $rejected): array
    {
        $times = array_values(array_filter(array_merge(
            array_column($accepted, 'x'),
            array_column($rejected, 'x'),
        )));

        if ($times === []) {
            return [null, null];
        }

        // ISO 8601 strings with a fixed offset sort chronologically as plain strings.
        sort($times);

        return [$times[0], end($times)];
    }

    /**
     * Supermarkets are the global (tenant-less) customers; bypass the active-tenant
     * scope so they resolve regardless of which tenant is selected.
     *
     * @return Collection<int, string>
     */
    protected function supermarketNames(): Collection
    {
        return Customer::query()
            ->withoutGlobalScope('active_tenant')
            ->global()
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    /**
     * @return Collection<int, string>
     */
    protected function productNames(): Collection
    {
        return Product::query()
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    protected function selectedProduct(): ?Product
    {
        $productId = $this->filters['product_id'] ?? null;

        return $productId ? Product::find($productId) : null;
    }

    protected function selectedSupermarket(): ?Customer
    {
        $supermarketId = $this->filters['supermarket_id'] ?? null;

        return $supermarketId
            ? Customer::query()->withoutGlobalScope('active_tenant')->find($supermarketId)
            : null;
    }

    /**
     * @param  array<int, array{x: ?string, y: float}>  $points
     * @param  array<int, string>  $labels
     * @return array{0: array<int, array{x: ?string, y: float}>, 1: array<int, string>}
     */
    protected function sortByTime(array $points, array $labels): array
    {
        $combined = collect($points)
            ->map(fn (array $point, int $index): array => ['point' => $point, 'label' => $labels[$index]])
            ->sortBy(fn (array $entry): string => $entry['point']['x'] ?? '')
            ->values();

        return [
            $combined->pluck('point')->all(),
            $combined->pluck('label')->all(),
        ];
    }
}
