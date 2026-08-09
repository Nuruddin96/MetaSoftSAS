<?php

/**
 * Niche website themes. Products, banners, uploaded images, and the
 * tenant's own color choices are NEVER touched — only typography,
 * shape language (rounded vs sharp), and layout "feel" change.
 *
 * All fonts below are real Google Fonts with full Bengali support.
 */
return [

    'default' => [
        'name' => 'ডিফল্ট',
        'niche' => 'সব ধরনের দোকানের জন্য',
        'font_heading' => 'Noto+Serif+Bengali:wght@700;800',
        'font_body' => 'Hind+Siliguri:wght@400;500;600;700',
        'heading_family' => "'Noto Serif Bengali', serif",
        'body_family' => "'Hind Siliguri', sans-serif",
        'radius' => '1rem',       // banners, big blocks
        'card_radius' => '0.75rem',    // product cards
        'btn_radius' => '0.75rem',
        'header_style' => 'centered',
        'spacing' => 'normal',
        'swatch' => ['#128155', '#F5B31A', '#F4F2EA'],
    ],

    'skincare' => [
        'name' => 'স্কিনকেয়ার ও বিউটি',
        'niche' => 'কসমেটিকস, বিউটি প্রোডাক্ট',
        'font_heading' => 'Tiro+Bangla:ital@0;1',
        'font_body' => 'Hind+Siliguri:wght@300;400;500;600',
        'heading_family' => "'Tiro Bangla', serif",
        'body_family' => "'Hind Siliguri', sans-serif",
        'radius' => '1.5rem',
        'card_radius' => '1.25rem',
        'btn_radius' => '9999px',      // pill buttons
        'header_style' => 'minimal',
        'spacing' => 'airy',
        'swatch' => ['#D8A7B1', '#F7E7E1', '#FFFFFF'],
    ],

    'organic_food' => [
        'name' => 'অরগানিক ফুড ও গ্রোসারি',
        'niche' => 'অরগানিক পণ্য, খাবার',
        'font_heading' => 'Galada&family=Hind+Siliguri:wght@600;700',
        'font_body' => 'Hind+Siliguri:wght@400;500;600',
        'heading_family' => "'Galada', 'Hind Siliguri', serif",
        'body_family' => "'Hind Siliguri', sans-serif",
        'radius' => '1.25rem',
        'card_radius' => '1rem',
        'btn_radius' => '1rem',
        'header_style' => 'centered',
        'spacing' => 'normal',
        'swatch' => ['#5B7B4F', '#F1EADA', '#8C5A3B'],
    ],

    'gadgets' => [
        'name' => 'গ্যাজেট ও ইলেকট্রনিক্স',
        'niche' => 'মোবাইল, ইলেকট্রনিক্স, টেক প্রোডাক্ট',
        'font_heading' => 'Baloo+Da+2:wght@700;800',
        'font_body' => 'Hind+Siliguri:wght@400;500;600;700',
        'heading_family' => "'Baloo Da 2', sans-serif",
        'body_family' => "'Hind Siliguri', sans-serif",
        'radius' => '0.5rem',
        'card_radius' => '0.375rem',
        'btn_radius' => '0.375rem',   // sharp, techy
        'header_style' => 'bold_dark',
        'spacing' => 'tight',
        'swatch' => ['#1A1A2E', '#00D9C0', '#F2F2F7'],
    ],

    'fashion' => [
        'name' => 'ফ্যাশন ও পোশাক',
        'niche' => 'কাপড়, বুটিক, ফ্যাশন হাউজ',
        'font_heading' => 'Noto+Serif+Bengali:wght@600;700',
        'font_body' => 'Hind+Siliguri:wght@300;400;500',
        'heading_family' => "'Noto Serif Bengali', serif",
        'body_family' => "'Hind Siliguri', sans-serif",
        'radius' => '0rem',        // sharp, editorial
        'card_radius' => '0rem',
        'btn_radius' => '0rem',
        'header_style' => 'editorial',
        'spacing' => 'airy',
        'swatch' => ['#132A21', '#FFFFFF', '#C9A227'],
    ],

    'jewelry' => [
        'name' => 'জুয়েলারি ও গহনা',
        'niche' => 'গহনা, লাক্সারি আইটেম',
        'font_heading' => 'Mina:wght@700',
        'font_body' => 'Hind+Siliguri:wght@400;500;600',
        'heading_family' => "'Mina', serif",
        'body_family' => "'Hind Siliguri', sans-serif",
        'radius' => '1rem',
        'card_radius' => '0.5rem',
        'btn_radius' => '0.25rem',
        'header_style' => 'bold_dark',
        'spacing' => 'airy',
        'swatch' => ['#7A1F3D', '#D4AF37', '#FBF8F1'],
    ],

];
