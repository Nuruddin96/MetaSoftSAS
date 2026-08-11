<?php

/**
 * Master catalog of togglable plan features. Super Admin ticks/unticks
 * these per plan (super/plans.blade.php); the landing page's pricing
 * section (central/landing.blade.php) renders them dynamically from here
 * plus each plan's stored plans.features JSON column — never hardcoded
 * per-plan in the view. Same "fixed catalog in a config file, referenced
 * by key" shape config/themes.php already uses for storefront themes, not
 * a new pattern for this codebase.
 *
 * Adding a feature here is enough for Super Admin configuration + landing-
 * page display (see Plan::hasFeature()). Nothing yet gates tenant-facing
 * functionality on these — that's deliberately a later phase, not built
 * here per the current scope.
 */
return [
    'list' => [
        'facebook' => 'Facebook',
        'messenger' => 'Facebook Messenger',
        'whatsapp' => 'WhatsApp',
        'message_automation' => 'মেসেজ অটোমেশন',
        'auto_order' => 'অটো অর্ডার',
        'facebook_page_connection' => 'Facebook Page কানেকশন',
        'server_side_tracking' => 'সার্ভার-সাইড ট্র্যাকিং (CAPI)',
        'website_builder' => 'ওয়েবসাইট বিল্ডার',
    ],
];
