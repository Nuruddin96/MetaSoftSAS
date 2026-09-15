<?php

/**
 * Landing page starter templates (Phase 10/11) — mirrors config/themes.php's
 * convention: a template only picks an ordered section list + a design
 * preset (typography/buttons/global spacing feel), it never sets brand
 * colors itself, so a tenant's own primary_color/secondary_color always
 * still drives the page (see DesignResolver::resolveGlobal()). Selected
 * once at creation (Tenant\LandingPageController::store) — a tenant can
 * freely add/remove/reorder/restyle afterwards, this is only a starting
 * point, not a locked-in layout.
 *
 * `sections` is the ordered type list (LandingPage::defaultSections()
 * builds the actual section rows from it). `section_data` optionally
 * pre-fills specific fields per type. `design` is merged as the page's
 * initial `design` column value (same shape as LandingPage::defaultDesign()
 * — LandingPageController::store() only sets the keys a template defines,
 * DesignResolver fills in the rest from defaultDesign()).
 */
return [

    'default' => [
        'name' => 'ডিফল্ট (৯টি সেকশন)',
        'sections' => ['hero', 'media', 'benefits', 'features', 'image_text', 'reviews', 'faq', 'cta', 'checkout'],
        'section_data' => [],
        'design' => null,
    ],

    'skincare' => [
        'name' => 'স্কিনকেয়ার / বিউটি',
        'sections' => ['announcement_bar', 'hero', 'trust_badges', 'benefits', 'image_text', 'gallery', 'reviews', 'faq', 'delivery_info', 'cta', 'checkout'],
        'section_data' => [
            'announcement_bar' => ['text' => '🌸 ফ্রি ডেলিভারি — আজকের অর্ডারে'],
            'hero' => ['layout' => 'centered'],
        ],
        'design' => [
            'typography' => ['heading_font' => 'display', 'body_font' => 'body', 'line_height' => 'relaxed'],
            'buttons' => ['style' => 'solid', 'radius' => 'full', 'size' => 'md'],
            'global' => ['container_width' => 'narrow', 'section_spacing' => 'spacious', 'border_radius' => 'lg', 'shadow' => 'sm'],
        ],
    ],

    'fashion' => [
        'name' => 'ফ্যাশন',
        'sections' => ['hero', 'gallery', 'benefits', 'image_text', 'reviews', 'trust_badges', 'faq', 'cta', 'checkout'],
        'section_data' => [
            'hero' => ['layout' => 'split'],
        ],
        'design' => [
            'typography' => ['heading_font' => 'modern', 'body_font' => 'modern', 'font_weight' => 'bold', 'line_height' => 'tight'],
            'buttons' => ['style' => 'solid', 'radius' => 'none', 'size' => 'lg'],
            'global' => ['container_width' => 'wide', 'section_spacing' => 'normal', 'border_radius' => 'none', 'shadow' => 'none'],
        ],
    ],

    'electronics' => [
        'name' => 'ইলেকট্রনিক্স / গ্যাজেট',
        'sections' => ['hero', 'trust_badges', 'features', 'media', 'image_text', 'gallery', 'reviews', 'faq', 'delivery_info', 'cta', 'checkout'],
        'section_data' => [
            'hero' => ['layout' => 'split'],
        ],
        'design' => [
            'typography' => ['heading_font' => 'modern', 'body_font' => 'modern', 'font_weight' => 'semibold', 'line_height' => 'normal'],
            'buttons' => ['style' => 'solid', 'radius' => 'md', 'size' => 'md'],
            'global' => ['container_width' => 'normal', 'section_spacing' => 'normal', 'border_radius' => 'md', 'shadow' => 'md'],
        ],
    ],

    'food' => [
        'name' => 'খাবার / ফুড',
        'sections' => ['hero', 'benefits', 'image_text', 'gallery', 'reviews', 'video_reviews', 'faq', 'delivery_info', 'cta', 'checkout'],
        'section_data' => [
            'hero' => ['layout' => 'centered'],
        ],
        'design' => [
            'typography' => ['heading_font' => 'display', 'body_font' => 'body', 'line_height' => 'relaxed'],
            'buttons' => ['style' => 'solid', 'radius' => 'full', 'size' => 'lg'],
            'global' => ['container_width' => 'narrow', 'section_spacing' => 'spacious', 'border_radius' => 'lg', 'shadow' => 'sm'],
        ],
    ],

    'single_product_sales' => [
        'name' => 'সিঙ্গেল প্রোডাক্ট সেলস পেজ',
        'sections' => ['hero', 'trust_badges', 'benefits', 'media', 'reviews', 'video_reviews', 'faq', 'delivery_info', 'cta', 'checkout'],
        'section_data' => [
            'hero' => ['layout' => 'centered'],
        ],
        'design' => [
            'typography' => ['heading_font' => 'display', 'body_font' => 'body', 'font_weight' => 'semibold'],
            'buttons' => ['style' => 'solid', 'radius' => 'md', 'size' => 'lg'],
            'global' => ['container_width' => 'normal', 'section_spacing' => 'normal', 'border_radius' => 'md', 'shadow' => 'none'],
        ],
    ],

];
