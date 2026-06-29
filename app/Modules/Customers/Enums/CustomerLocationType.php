<?php

namespace App\Modules\Customers\Enums;

enum CustomerLocationType: string
{
    case Supermarket = 'supermarket';
    case Hypermarket = 'hypermarket';
    case Warehouse = 'warehouse';

    public function label(): string
    {
        return match ($this) {
            self::Supermarket => __('Supermarket'),
            self::Hypermarket => __('Hypermarket'),
            self::Warehouse => __('Depozit'),
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
