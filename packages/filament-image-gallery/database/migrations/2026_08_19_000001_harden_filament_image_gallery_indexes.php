<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = config('filament-image-gallery.tables.roles', 'image_gallery_roles');

        Schema::table(config('filament-image-gallery.tables.images', 'image_gallery_images'), function (Blueprint $table): void {
            $table->unique(['disk', 'path'], 'image_gallery_images_disk_path_unique');
        });

        Schema::table($rolesTable, function (Blueprint $table) use ($rolesTable): void {
            $table->dropUnique($rolesTable.'_image_id_role_unique');
        });
    }

    public function down(): void
    {
        Schema::table(config('filament-image-gallery.tables.roles', 'image_gallery_roles'), function (Blueprint $table): void {
            $table->unique(['image_id', 'role']);
        });

        Schema::table(config('filament-image-gallery.tables.images', 'image_gallery_images'), function (Blueprint $table): void {
            $table->dropUnique('image_gallery_images_disk_path_unique');
        });
    }
};
