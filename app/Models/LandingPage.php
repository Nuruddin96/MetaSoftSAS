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
        'design' => 'array',
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
            'announcement_bar' => 'অ্যানাউন্সমেন্ট বার',
            'hero' => 'Hero',
            'media' => 'ছবি / ভিডিও',
            'trust_badges' => 'ট্রাস্ট ব্যাজ',
            'benefits' => 'সুবিধা (Benefits)',
            'image_text' => 'ছবি + লেখা',
            'features' => 'প্রোডাক্ট বিবরণ',
            'gallery' => 'ইমেজ গ্যালারি',
            'rich_text' => 'ব্র্যান্ড স্টোরি / বিস্তারিত লেখা',
            'countdown' => 'কাউন্টডাউন অফার',
            'reviews' => 'কাস্টমার রিভিউ',
            'video_reviews' => 'ভিডিও রিভিউ',
            'delivery_info' => 'ডেলিভারি তথ্য',
            'cta' => 'CTA',
            'faq' => 'FAQ',
            'checkout' => 'চেকআউট / অর্ডার ফর্ম',
        ];
    }

    /** One blank data shape per section type — used both for a freshly added section and as the shape the editor form fields expect. */
    public static function blankSectionData(string $type): array
    {
        return match ($type) {
            'hero' => ['headline' => '', 'subheadline' => '', 'image_path' => '', 'video_url' => '', 'cta_text' => 'এখনই অর্ডার করুন', 'layout' => 'centered'],
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
            'trust_badges' => ['items' => []],
            'countdown' => ['heading' => 'অফারটি শেষ হচ্ছে', 'end_at' => '', 'expired_text' => 'অফারটি শেষ হয়ে গেছে'],
            'announcement_bar' => ['text' => '', 'link_url' => '', 'dismissible' => true],
            'delivery_info' => ['heading' => 'ডেলিভারি তথ্য', 'note' => '', 'eta_text' => ''],
            'rich_text' => ['heading' => '', 'body' => ''],
            default => [],
        };
    }

    /** Global design token defaults — a landing page with `design === null` renders identically to this. */
    public static function defaultDesign(): array
    {
        return [
            'brand' => [
                'primary_color' => null,   // null = inherit tenant primary_color (--color-brand)
                'secondary_color' => null, // null = inherit tenant secondary_color (--color-accent)
                'background_color' => null,
                'text_color' => null,
            ],
            'typography' => [
                'heading_font' => 'display',  // display | body | modern
                'body_font' => 'body',
                'font_size' => 'base',        // sm | base | lg
                'font_weight' => 'normal',    // normal | semibold | bold
                'line_height' => 'normal',    // tight | normal | relaxed
            ],
            'buttons' => [
                'style' => 'solid',   // solid | outline | ghost
                'radius' => 'md',     // none | md | full
                'size' => 'md',       // sm | md | lg
            ],
            'global' => [
                'container_width' => 'normal', // narrow | normal | wide
                'section_spacing' => 'normal', // compact | normal | spacious
                'border_radius' => 'md',       // none | md | lg
                'shadow' => 'none',            // none | sm | md | lg
            ],
        ];
    }

    /**
     * The recommended default template (spec: Hero → Media → Benefits →
     * Product Details → Image/Text → Reviews → FAQ → CTA → Checkout),
     * pre-filled from the bound product so a tenant isn't starting from a
     * fully blank page. Tenant can freely add/remove/reorder afterwards.
     *
     * $templateKey selects a niche preset from config('landing_templates')
     * — 'default' reproduces the original 9-section behavior exactly, so
     * every pre-template-system landing page (and any caller that doesn't
     * pass one) is unaffected.
     */
    public static function defaultSections(Product $product, string $templateKey = 'default'): array
    {
        $template = config("landing_templates.{$templateKey}") ?? config('landing_templates.default');
        $types = $template['sections'] ?? ['hero', 'media', 'benefits', 'features', 'image_text', 'reviews', 'faq', 'cta', 'checkout'];

        $sections = [];
        foreach ($types as $type) {
            $data = array_merge(static::blankSectionData($type), $template['section_data'][$type] ?? []);

            if ($type === 'hero') {
                $data['headline'] = $data['headline'] ?: $product->name;
                $data['image_path'] = $data['image_path'] ?: ($product->thumbnail_path ?? '');
            }
            if ($type === 'features') {
                $data['description'] = $data['description'] ?: (string) $product->description;
            }
            if ($type === 'cta') {
                $data['heading'] = $data['heading'] ?: $product->name.' এখনই অর্ডার করুন';
            }

            $sections[] = ['id' => Str::random(10), 'type' => $type, 'hidden' => false, 'data' => $data];
        }

        return $sections;
    }
}
