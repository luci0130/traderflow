<?php

namespace Turiac\FilamentImageGallery\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Turiac\FilamentImageGallery\Concerns\HasImageGalleries;

class Product extends Model
{
    use HasImageGalleries;
    use SoftDeletes;

    protected $table = 'test_products';

    protected $guarded = [];
}
