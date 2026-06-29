<?php

namespace App\Modules\Support;

use Illuminate\Support\ServiceProvider;

abstract class FeatureModuleServiceProvider extends ServiceProvider
{
    public static function key(): string
    {
        return static::KEY;
    }

    public static function label(): string
    {
        return static::LABEL;
    }
}
