<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $galleriesTable = config('filament-image-gallery.tables.galleries', 'image_galleries');
        $imagesTable = config('filament-image-gallery.tables.images', 'image_gallery_images');
        $rolesTable = config('filament-image-gallery.tables.roles', 'image_gallery_roles');

        Schema::create($galleriesTable, function (Blueprint $table): void {
            $table->id();
            $this->morphs($table, 'owner');
            $table->string('collection');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'collection'], 'image_galleries_owner_collection_unique');
        });

        Schema::create($imagesTable, function (Blueprint $table) use ($galleriesTable): void {
            $table->id();
            $table->foreignId('gallery_id')->constrained($galleriesTable)->cascadeOnDelete();
            $this->morphs($table, 'uploader', nullable: true);
            $table->string('disk');
            $table->string('path');
            $table->string('visibility')->default('public');
            $table->string('original_filename');
            $table->string('title');
            $table->text('alt_text')->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('original_size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->decimal('focal_x', 8, 5)->nullable();
            $table->decimal('focal_y', 8, 5)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['gallery_id', 'path']);
            $table->index(['gallery_id', 'sort_order']);
        });

        Schema::create($rolesTable, function (Blueprint $table) use ($galleriesTable, $imagesTable): void {
            $table->id();
            $table->foreignId('gallery_id')->constrained($galleriesTable)->cascadeOnDelete();
            $table->foreignId('image_id')->constrained($imagesTable)->cascadeOnDelete();
            $table->string('role');
            $table->timestamps();

            $table->unique(['gallery_id', 'role']);
            $table->unique(['image_id', 'role']);
            $table->index(['gallery_id', 'image_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('filament-image-gallery.tables.roles', 'image_gallery_roles'));
        Schema::dropIfExists(config('filament-image-gallery.tables.images', 'image_gallery_images'));
        Schema::dropIfExists(config('filament-image-gallery.tables.galleries', 'image_galleries'));
    }

    private function morphs(Blueprint $table, string $name, bool $nullable = false): void
    {
        $method = match (config('filament-image-gallery.morph_key_type', 'id')) {
            'uuid' => $nullable ? 'nullableUuidMorphs' : 'uuidMorphs',
            'ulid' => $nullable ? 'nullableUlidMorphs' : 'ulidMorphs',
            default => $nullable ? 'nullableMorphs' : 'morphs',
        };

        $table->{$method}($name);
    }
};
