<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document number sequences
    |--------------------------------------------------------------------------
    |
    | Each tenant gets one sequence per document type below. A number is built
    | as: prefix + zero-padded counter + suffix (e.g. "OC-01412"). The values
    | here are only the defaults used when a tenant's sequence is first created;
    | they can be edited per tenant in the "Number sequences" settings.
    |
    */

    'types' => [
        'customer_offer' => ['label' => 'Customer offer', 'prefix' => 'OC-', 'padding' => 5],
        'sales_order' => ['label' => 'Sales order', 'prefix' => 'SO-', 'padding' => 5],
        'supplier_offer' => ['label' => 'Supplier offer', 'prefix' => 'OF-', 'padding' => 5],
        'supplier_order' => ['label' => 'Supplier order', 'prefix' => 'CF-', 'padding' => 5],
    ],
];
