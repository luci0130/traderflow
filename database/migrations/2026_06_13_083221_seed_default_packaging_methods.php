<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<array{name: string, description: string, sort_order: int}>
     */
    private array $methods = [
        ['name' => 'Vrac', 'description' => 'Vandut fara ambalaj individual, de obicei per kg.', 'sort_order' => 10],
        ['name' => 'Plasă', 'description' => 'Ambalat in plasa, de exemplu plasa 1kg sau 2kg.', 'sort_order' => 20],
        ['name' => 'Ladă', 'description' => 'Ambalat in lada pentru cantitati mai mari.', 'sort_order' => 30],
        ['name' => 'Cutie', 'description' => 'Ambalat in cutie.', 'sort_order' => 40],
        ['name' => 'Bucată', 'description' => 'Vandut per bucata.', 'sort_order' => 50],
        ['name' => 'Sac', 'description' => 'Ambalat in sac.', 'sort_order' => 60],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $now = now();

        DB::table('packaging_methods')->insertOrIgnore(
            collect($this->methods)
                ->map(fn (array $method): array => [
                    ...$method,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('packaging_methods')
            ->whereIn('name', collect($this->methods)->pluck('name')->all())
            ->delete();
    }
};
