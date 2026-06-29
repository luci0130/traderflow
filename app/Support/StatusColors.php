<?php

namespace App\Support;

use Filament\Support\Colors\Color;

/**
 * Single source of truth for the colours used to render offer/order/entity
 * statuses. The hex palette is shared by the admin table badges (via
 * {@see self::badge()}) and the supermarket-margin report chart (via
 * {@see self::hex()}), so the two stay visually in sync.
 */
class StatusColors
{
    // Colour variables — reused across every status below. Keep these as the
    // only place hex values are declared.
    public const GREEN = '#16a34a';

    public const RED = '#dc2626';

    public const BLUE = '#2563eb';

    public const VIOLET = '#7c3aed';

    public const GRAY = '#9ca3af';

    public const AMBER = '#d97706';

    public const SLATE = '#475569';

    public const TEAL = '#0d9488';

    public const INDIGO = '#4f46e5';

    public const EMERALD = '#059669';

    /**
     * Status key → hex colour, covering offer, order and entity statuses.
     *
     * @var array<string, string>
     */
    public const MAP = [
        // Offer / order lifecycle
        'draft' => self::GRAY,
        'sent' => self::BLUE,
        'received' => self::VIOLET,
        'processed' => self::TEAL,
        'accepted' => self::GREEN,
        'approved' => self::GREEN,
        'rejected' => self::RED,
        'expired' => self::AMBER,
        'cancelled' => self::SLATE,
        'confirmed' => self::BLUE,
        'in_preparation' => self::AMBER,
        'shipped' => self::TEAL,
        'delivered' => self::TEAL,
        'invoiced' => self::INDIGO,
        'paid' => self::EMERALD,
        // Entity status
        'active' => self::GREEN,
        'inactive' => self::SLATE,
    ];

    public const FALLBACK = self::GRAY;

    /**
     * Raw hex colour for a status, used where a plain string is needed (e.g. the
     * report chart datasets).
     */
    public static function hex(?string $status): string
    {
        return self::MAP[$status] ?? self::FALLBACK;
    }

    /**
     * Filament colour palette (shade map) for a status badge.
     *
     * @return array<int, string>
     */
    public static function badge(?string $status): array
    {
        return Color::hex(self::hex($status));
    }
}
