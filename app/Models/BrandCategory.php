<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Platform-wide brand category (not the tenant shop `Category`). */
class BrandCategory extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered($q)
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? asset('storage/'.$this->image_path) : null;
    }
}
