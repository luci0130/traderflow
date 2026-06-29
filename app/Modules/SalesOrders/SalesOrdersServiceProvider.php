<?php

namespace App\Modules\SalesOrders;

use App\Modules\Support\FeatureModuleServiceProvider;

final class SalesOrdersServiceProvider extends FeatureModuleServiceProvider
{
    public const KEY = 'sales_orders';

    public const LABEL = 'Comenzi si vanzari';
}
