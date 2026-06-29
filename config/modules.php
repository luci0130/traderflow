<?php

use App\Modules\BestPrices\BestPricesServiceProvider;
use App\Modules\CustomerOffers\CustomerOffersServiceProvider;
use App\Modules\Customers\CustomersServiceProvider;
use App\Modules\Dashboard\DashboardServiceProvider;
use App\Modules\Documents\DocumentsServiceProvider;
use App\Modules\Emails\EmailsServiceProvider;
use App\Modules\MarketComparison\MarketComparisonServiceProvider;
use App\Modules\NumberSequences\NumberSequencesServiceProvider;
use App\Modules\Producers\ProducersServiceProvider;
use App\Modules\ProductCategories\ProductCategoriesServiceProvider;
use App\Modules\Products\ProductsServiceProvider;
use App\Modules\Reports\ReportsServiceProvider;
use App\Modules\SalesOrders\SalesOrdersServiceProvider;
use App\Modules\Supermarkets\SupermarketsServiceProvider;
use App\Modules\SupplierOffers\SupplierOffersServiceProvider;
use App\Modules\SupplierOrders\SupplierOrdersServiceProvider;
use App\Modules\Suppliers\SuppliersServiceProvider;
use App\Modules\TenantSettings\TenantSettingsServiceProvider;
use App\Modules\Units\UnitsServiceProvider;

return [
    'modules' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'provider' => DashboardServiceProvider::class,
            'enabled' => true,
        ],
        'products' => [
            'label' => 'Produse',
            'provider' => ProductsServiceProvider::class,
            'enabled' => true,
        ],
        'product_categories' => [
            'label' => 'Categorii produse',
            'provider' => ProductCategoriesServiceProvider::class,
            'enabled' => true,
        ],
        'units' => [
            'label' => 'Unitati de masura',
            'provider' => UnitsServiceProvider::class,
            'enabled' => true,
        ],
        'suppliers' => [
            'label' => 'Furnizori',
            'provider' => SuppliersServiceProvider::class,
            'enabled' => true,
        ],
        'producers' => [
            'label' => 'Producatori',
            'provider' => ProducersServiceProvider::class,
            'enabled' => true,
        ],
        'customers' => [
            'label' => 'Clienti',
            'provider' => CustomersServiceProvider::class,
            'enabled' => true,
        ],
        'supplier_offers' => [
            'label' => 'Oferte furnizori',
            'provider' => SupplierOffersServiceProvider::class,
            'enabled' => true,
        ],
        'best_prices' => [
            'label' => 'Comparare preturi',
            'provider' => BestPricesServiceProvider::class,
            'enabled' => true,
        ],
        'supermarkets' => [
            'label' => 'Preturi supermarket',
            'provider' => SupermarketsServiceProvider::class,
            'enabled' => true,
        ],
        'market_comparison' => [
            'label' => 'Comparatie piata',
            'provider' => MarketComparisonServiceProvider::class,
            'enabled' => true,
        ],
        'customer_offers' => [
            'label' => 'Oferte clienti',
            'provider' => CustomerOffersServiceProvider::class,
            'enabled' => true,
        ],
        'sales_orders' => [
            'label' => 'Comenzi si vanzari',
            'provider' => SalesOrdersServiceProvider::class,
            'enabled' => true,
        ],
        'supplier_orders' => [
            'label' => 'Comenzi furnizori',
            'provider' => SupplierOrdersServiceProvider::class,
            'enabled' => true,
        ],
        'number_sequences' => [
            'label' => 'Serii de numerotare',
            'provider' => NumberSequencesServiceProvider::class,
            'enabled' => true,
        ],
        'documents' => [
            'label' => 'Documente',
            'provider' => DocumentsServiceProvider::class,
            'enabled' => true,
        ],
        'emails' => [
            'label' => 'Emailuri',
            'provider' => EmailsServiceProvider::class,
            'enabled' => true,
        ],
        'reports' => [
            'label' => 'Rapoarte',
            'provider' => ReportsServiceProvider::class,
            'enabled' => true,
        ],
        'tenant_settings' => [
            'label' => 'Setari tenant',
            'provider' => TenantSettingsServiceProvider::class,
            'enabled' => true,
        ],
    ],
];
