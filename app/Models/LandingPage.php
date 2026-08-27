<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class LandingPage extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = [
        'sections' => 'array',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (LandingPage $p) {
            $p->slug = $p->slug ?: Str::slug($p->title).'-'.Str::lower(Str::random(4));
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** Section types the builder supports, in display order for the "add section" picker. */
    public static function sectionTypes(): array
    {
        return [
            'hero' => 'Hero',
            'media' => 'ছবি / ভিডিও',
            'benefits' => 'সুবিধা (Benefits)',
            'image_text' => 'ছবি + লেখা',
            'features' => 'প্রোডাক্ট বিবরণ',
            'gallery' => 'ইমেজ গ্যালারি',
            'reviews' => 'কাস্টমার রিভিউ',
            'video_reviews' => 'ভিডিও রিভিউ',
            'cta' => 'CTA',
            'faq' => 'FAQ',
            'checkout' => 'চেকআউট / অর্ডার ফর্ম',
        ];
    }

    /** One blank data shape per section type — used both for a freshly added section and as the shape the editor form fields expect. */
    public static function blankSectionData(string $type): array
    {
        return match ($type) {
            'hero' => ['headline' => '', 'subheadline' => '', 'image_path' => '', 'video_url' => '', 'cta_text' => 'এখনই অর্ডার করুন'],
            'media' => ['image_path' => '', 'video_url' => ''],
            'benefits' => ['heading' => 'কেন এই প্রোডাক্ট নিবেন', 'items' => []],
            'image_text' => ['image_path' => '', 'heading' => '', 'description' => '', 'layout' => 'image-left'],
            'features' => ['heading' => 'প্রোডাক্ট বিবরণ', 'description' => ''],
            'gallery' => ['images' => []],
            'reviews' => ['heading' => 'কাস্টমারদের মতামত', 'items' => []],
            'video_reviews' => ['heading' => 'ভিডিও রিভিউ', 'items' => []],
            'cta' => ['heading' => '', 'button_text' => 'এখনই অর্ডার করুন'],
            'faq' => ['heading' => 'সাধারণ জিজ্ঞাসা', 'items' => []],
            'checkout' => ['heading' => 'অর্ডার করুন'],
            default => [],
        };
    }

    /**
     * The recommended default template (spec: Hero → Media → Benefits →
     * Product Details → Image/Text → Reviews → FAQ → CTA → Checkout),
     * pre-filled from the bound product so a tenant isn't starting from a
     * fully blank page. Tenant can freely add/remove/reorder afterwards.
     */
    public static function defaultSections(Product $product): array
    {
        $types = ['hero', 'media', 'benefits', 'features', 'image_text', 'reviews', 'faq', 'cta', 'checkout'];

        $sections = [];
        foreach ($types as $type) {
            $data = static::blankSectionData($type);

            if ($type === 'hero') {
                $data['headline'] = $product->name;
                $data['image_path'] = $product->thumbnail_path ?? '';
            }
            if ($type === 'features') {
                $data['description'] = (string) $product->description;
            }
            if ($type === 'cta') {
                $data['heading'] = $product->name.' এখনই অর্ডার করুন';
            }

            $sections[] = ['id' => Str::random(10), 'type' => $type, 'data' => $data];
        }

        return $sections;
    }
}
