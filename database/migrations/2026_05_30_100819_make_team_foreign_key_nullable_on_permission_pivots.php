<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles are global in this application, so the team key (tenant_id) on the
     * permission pivots is always null. Postgres forbids a nullable column
     * inside a primary key, so there we drop tenant_id from the pivot primary
     * keys (falling back to Spatie's non-teams key) before relaxing NOT NULL.
     * SQLite tolerates a nullable primary-key column, so the local/test schema
     * keeps the simpler column change.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT model_has_roles_pkey');
            DB::statement('ALTER TABLE model_has_roles ALTER COLUMN tenant_id DROP NOT NULL');
            DB::statement('ALTER TABLE model_has_roles ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (role_id, model_id, model_type)');

            DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT model_has_permissions_pkey');
            DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN tenant_id DROP NOT NULL');
            DB::statement('ALTER TABLE model_has_permissions ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (permission_id, model_id, model_type)');

            return;
        }

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE model_has_roles DROP CONSTRAINT model_has_roles_pkey');
            DB::statement('ALTER TABLE model_has_roles ALTER COLUMN tenant_id SET NOT NULL');
            DB::statement('ALTER TABLE model_has_roles ADD CONSTRAINT model_has_roles_pkey PRIMARY KEY (tenant_id, role_id, model_id, model_type)');

            DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT model_has_permissions_pkey');
            DB::statement('ALTER TABLE model_has_permissions ALTER COLUMN tenant_id SET NOT NULL');
            DB::statement('ALTER TABLE model_has_permissions ADD CONSTRAINT model_has_permissions_pkey PRIMARY KEY (tenant_id, permission_id, model_id, model_type)');

            return;
        }

        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });

        Schema::table('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
        });
    }
};
