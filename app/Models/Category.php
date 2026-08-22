<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /** Top-level category this one is a subcategory of, if any. */
    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** Subcategories of this category. */
    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
