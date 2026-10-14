<?php

namespace App\Support\Home;

/**
 * Sample content for the central homepage (metasoftbd.com) — the
 * Entrepreneur & Brand Recognition Platform.
 *
 * Everything here is illustrative: the brands, people, vote counts and
 * events are realistic Bangladesh-flavoured placeholders, not real records.
 * The homepage shows a "sample preview" notice while
 * config('platform.showcase_preview') is true (the default) so visitors
 * are never misled. When the real brand/award tables exist, replace these
 * methods with queries — every view only consumes the array shapes below,
 * so the templates don't need to change.
 */
class Showcase
{
    /**
     * The homepage's demo/showcase brands, keyed by slug. Logos and covers
     * are the supplied files in public/images/showcase/{logos,covers}
     * (used exactly as provided). Descriptions only restate what each
     * brand's own artwork says; no awards, follower counts, ratings or
     * founding years are invented for them, and none is marked verified
     * or sponsored. `tag` 'editor' = shown in the editorial Featured row.
     * Real approved brands always take these slots first
     * (App\Support\Platform\HomepageContent).
     */
    public static function brands(): array
    {
        $rows = [
            // slug, name, initials, from, to, category, district, division, description, tag, logo file, cover file
            ['girls-secret', 'Girls Secret', 'GS', 'E9B949', '9A6B0A', 'Beauty & Skincare', 'Bangladesh', 'Bangladesh', 'Beauty parlour for women — hair, skin and bridal care.', 'editor', 'brand1-logo.png.jpg', 'brand1-cover.jpg.png'],
            ['li-ummati', 'Li Ummati', 'LU', '3F3F46', '0A0A0A', 'Beauty & Skincare', 'Bangladesh', 'Bangladesh', 'Sunnah-inspired attar and perfumes — including make-your-own perfume blends.', null, 'brand2-logo.png.jpg', 'brand2-cover.jpg.jpg'],
            ['ayat-fashion', 'Ayat Fashion', 'AF', 'E11D48', '881337', 'Fashion & Apparel', 'Dhaka', 'Dhaka', 'Women’s fashion and occasion wear — outlet at Grand Plaza, Moghbazar, Dhaka.', 'editor', 'brand3-logo.png.jpg', 'brand3-cover.jpg.jpg'],
            ['respite-care', 'Respite Care', 'RC', '1E5BB8', '0B2E6B', 'Healthcare & Pharmacy', 'Bangladesh', 'Bangladesh', 'Home health care — nursing, ICU-level home care, physiotherapy, elderly and mother & baby care.', 'editor', 'brand4-logo.png.jpg', 'brand4-cover.jpg.png'],
            ['ragdhanu-mart', 'Ragdhanu Mart', 'RM', 'F59E0B', '0E7490', 'Retail & Consumer Products', 'Bangladesh', 'Bangladesh', 'Gadgets, skin care and imported products in one colourful mart.', null, 'brand5-logo.png.jpg', 'brand5-cover.jpg.png'],
            ['sariha-art', 'Sariha Art', 'SA', 'C8A27A', '7C5A3A', 'Jewelry & Accessories', 'Bangladesh', 'Bangladesh', 'Handmade floral jewellery and bridal accessories for weddings, gaye holud and special occasions.', 'editor', 'brand6-logo.png.jpg', 'brand6-cover.jpg.jpg'],
        ];

        $brands = [];
        foreach ($rows as [$slug, $name, $ini, $from, $to, $cat, $district, $division, $desc, $tag, $logo, $cover]) {
            $brands[$slug] = [
                'slug' => $slug,
                'name' => $name,
                'initials' => $ini,
                'from' => '#'.$from,
                'to' => '#'.$to,
                'category' => $cat,
                'district' => $district,
                'division' => $division,
                'description' => $desc,
                'founded' => null,
                'verified' => false,
                'badges' => [],
                'followers' => null,
                'rating' => null,
                'tag' => $tag,
                'founder' => null,
                'url' => '/brand/'.$slug,
                'logo' => self::art('logos', $logo),
                'cover' => self::art('covers', $cover),
            ];
        }

        return $brands;
    }

    public static function brand(string $slug): ?array
    {
        return self::brands()[$slug] ?? null;
    }

    /** Featured row: the editorial picks among the demo brands (no paid placement is shown for them). */
    public static function featuredBrands(): array
    {
        return array_map(fn ($s) => self::brand($s), ['ayat-fashion', 'girls-secret', 'respite-care', 'sariha-art']);
    }

    public static function categories(): array
    {
        return [
            ['name' => 'Fashion & Apparel', 'bn' => 'ফ্যাশন', 'icon' => 'sparkles'],
            ['name' => 'Food & Beverage', 'bn' => 'খাদ্য ও পানীয়', 'icon' => 'heart'],
            ['name' => 'Tech & Startups', 'bn' => 'টেক ও স্টার্টআপ', 'icon' => 'rocket'],
            ['name' => 'Handicrafts', 'bn' => 'হস্তশিল্প', 'icon' => 'award'],
            ['name' => 'Health & Beauty', 'bn' => 'স্বাস্থ্য ও সৌন্দর্য', 'icon' => 'star'],
            ['name' => 'Agro & Organic', 'bn' => 'কৃষি ও অর্গানিক', 'icon' => 'layers'],
        ];
    }

    /**
     * Entrepreneur spotlight for the demo brands. No founder names are
     * invented for these businesses: each card presents the brand's team,
     * and the line is a restatement of the brand's own artwork.
     */
    public static function entrepreneurs(): array
    {
        $rows = [
            ['girls-secret', 'Beauty, hair and bridal care — in a parlour made just for women.'],
            ['respite-care', 'Professional nursing, ICU-level care and physiotherapy at the comfort of your home. Always beside you.'],
            ['ayat-fashion', 'Style that inspires — women’s fashion and occasion wear, now at our Grand Plaza outlet in Moghbazar.'],
            ['sariha-art', 'Handmade floral jewellery and bridal accessories for weddings, gaye holud and every special occasion.'],
            ['li-ummati', 'Follow the Sunnah: attar and perfumes — or make your own perfume blend.'],
        ];

        return array_map(function ($r) {
            [$brandSlug, $story] = $r;
            $brand = self::brand($brandSlug);

            return [
                'slug' => $brandSlug.'-team',
                'name' => $brand['name'].' team',
                'initials' => $brand['initials'],
                'from' => $brand['from'],
                'to' => $brand['to'],
                'role' => 'Founders',
                'brand' => $brand['name'],
                'brand_slug' => $brandSlug,
                'category' => $brand['category'],
                'district' => $brand['district'],
                'story' => $story,
                'recognition' => ['label' => 'Showcase brand', 'type' => 'editor'],
                'url' => $brand['url'],
                'verified' => false,
                'avatar' => $brand['logo'],
                'brand_logo' => $brand['logo'],
                'cover' => $brand['cover'],
            ];
        }, $rows);
    }

    public static function featuredAward(): array
    {
        return [
            'title' => 'Bangladesh Brand & Entrepreneur Awards 2026',
            'bn' => 'বাংলাদেশ ব্র্যান্ড ও উদ্যোক্তা অ্যাওয়ার্ড ২০২৬',
            'edition' => 'National · 1st edition',
            // Sample card: no voting state, dates or countdown are claimed until a real campaign exists.
            'voting_open' => false,
            'status' => 'Voting not open yet',
            'closes_at' => '',
            'closes_label' => null,
            'stats' => [
                // Only the configured category count; no sample nominee or vote totals.
                ['value' => (string) count(config('platform.award_categories')), 'label' => 'Categories'],
            ],
            'steps' => [
                ['label' => 'Nomination', 'meta' => 'Free', 'state' => 'next'],
                ['label' => 'Public voting', 'meta' => 'People’s Choice', 'state' => 'next'],
                ['label' => 'Jury review', 'meta' => 'Jury Choice', 'state' => 'next'],
                ['label' => 'Winners', 'meta' => 'Announced', 'state' => 'next'],
            ],
            'url' => '/award/bangladesh-brand-entrepreneur-awards-2026',
        ];
    }

    /** Placeholder award cards; only resources/branding/showcase-art.php uses them. The homepage shows real awards only (HomepageContent::otherAwards). */
    public static function otherAwards(): array
    {
        return [
            ['title' => 'Women Entrepreneur Awards 2026', 'category' => 'Leadership & Impact', 'status' => 'Nominations open', 'state' => 'nominate', 'when' => 'Closes 15 Nov', 'initials' => 'WE', 'from' => '#1769AA', 'to' => '#073B66'],
            ['title' => 'Bangladesh Startup Awards', 'category' => 'Tech & Innovation', 'status' => 'Jury review', 'state' => 'jury', 'when' => 'Results 20 Dec', 'initials' => 'SA', 'from' => '#2F80C9', 'to' => '#0B4A80'],
            ['title' => 'Best Local Food Brand 2026', 'category' => 'Food & Beverage', 'status' => 'Voting open', 'state' => 'voting', 'when' => 'Closes 25 Nov', 'initials' => 'FB', 'from' => '#0E528C', 'to' => '#052B4C'],
        ];
    }

    /** URL of a supplied showcase image: public/images/showcase/{logos|covers}/{file} (file name used verbatim). */
    public static function art(string $kind, string $file): string
    {
        return asset('images/showcase/'.$kind.'/'.$file);
    }

    /**
     * Live voting: one tab per category, each with its own nominees.
     * Percentages are computed from the vote counts so they always add up.
     */
    public static function votingCategories(): array
    {
        $cats = [
            'rising' => ['Rising Brand', [['ayat-fashion', 4820], ['ragdhanu-mart', 3610], ['sariha-art', 2490], ['li-ummati', 1780]]],
            'fashion' => ['Fashion & Accessories', [['ayat-fashion', 5140], ['sariha-art', 3920]]],
            'beauty' => ['Beauty & Skincare', [['girls-secret', 4410], ['li-ummati', 4030], ['ragdhanu-mart', 1960]]],
            'services' => ['Health & Services', [['respite-care', 3890], ['girls-secret', 2240]]],
        ];

        $out = [];
        foreach ($cats as $key => [$label, $noms]) {
            $total = array_sum(array_column($noms, 1));
            $max = max(array_column($noms, 1));
            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'total' => $total,
                // Sample numbers only order the preview nominees; they are never shown as vote counts.
                'show_counts' => false,
                'nominees' => array_map(function ($n, $i) use ($total, $max) {
                    return [
                        'brand' => self::brand($n[0]),
                        'votes' => $n[1],
                        'pct' => (int) round($n[1] / $total * 100),
                        'bar' => (int) round($n[1] / $max * 100),
                        'rank' => $i + 1,
                    ];
                }, $noms, array_keys($noms)),
            ];
        }

        return $out;
    }

    /**
     * "Trending" list. There is no real view or vote ranking to draw on, so
     * this is a single list of the showcase brands with no metrics: no
     * invented growth percentages, view counts, vote totals or sparklines.
     * The tab shape is kept so a real ranking can slot in later.
     */
    public static function trending(): array
    {
        return [
            'trending' => [
                'key' => 'trending',
                'label' => 'Brands to watch',
                'icon' => 'flame',
                'rows' => array_map(fn ($slug) => ['brand' => self::brand($slug), 'metric' => null, 'up' => false, 'spark' => null],
                    ['ayat-fashion', 'sariha-art', 'girls-secret', 'ragdhanu-mart', 'li-ummati', 'respite-care']),
            ],
        ];
    }

    /**
     * The six recognitions. People's Choice and Jury Choice are the two
     * awards Super Admin's programme setup creates in every category; jury
     * size, vote/jury weighting and brand-age limits are not enforced
     * anywhere, so they appear only when set in config('platform.award_rules').
     */
    public static function recognitionTypes(): array
    {
        $rules = config('platform.award_rules', []);
        $jury = ! empty($rules['jury_size']) ? 'Selected by a jury of '.$rules['jury_size'].'.' : 'Selected by the award jury.';
        $weight = $rules['public_vote_weight'] ?? null;
        $rising = ! empty($rules['rising_brand_max_years'])
            ? 'For brands under '.$rules['rising_brand_max_years'].' years old.'
            : 'For newer brands showing real growth. Criteria are set for each award.';

        return [
            ['name' => 'People’s Choice', 'bn' => 'জনগণের পছন্দ', 'desc' => 'Decided by public votes in each category.', 'how' => 'Public vote', 'color' => '#4C9BE0', 'icon' => 'users'],
            ['name' => 'Jury Choice', 'bn' => 'জুরি নির্বাচন', 'desc' => $jury, 'how' => 'Jury review', 'color' => '#2F80C9', 'icon' => 'scale'],
            ['name' => 'Rising Brand', 'bn' => 'উদীয়মান ব্র্যান্ড', 'desc' => $rising, 'how' => $weight ? $weight.'% vote · '.(100 - $weight).'% jury' : 'Public vote + jury', 'color' => '#5FA8E8', 'icon' => 'rocket'],
            ['name' => 'Brand of the Year', 'bn' => 'বর্ষসেরা ব্র্যান্ড', 'desc' => 'Recognises business performance and impact.', 'how' => 'Jury review', 'color' => '#9DCBF2', 'icon' => 'trophy'],
            ['name' => 'Featured Brand', 'bn' => 'ফিচার্ড ব্র্যান্ড', 'desc' => 'Selected by MetaSoft BD editors, never paid.', 'how' => 'Editorial selection', 'color' => '#3B82C4', 'icon' => 'star'],
            ['name' => 'Entrepreneur of the Year', 'bn' => 'বর্ষসেরা উদ্যোক্তা', 'desc' => 'Honours founder leadership and impact.', 'how' => 'Jury review', 'color' => '#7FB8EC', 'icon' => 'award'],
        ];
    }

    /** Every label used on the platform and how it is earned — the anti-pay-to-win legend. */
    public static function labels(): array
    {
        return [
            ['kind' => 'Earned', 'name' => 'Nomination', 'desc' => 'Open to every brand, free to apply', 'tone' => 'navy'],
            ['kind' => 'Earned', 'name' => 'Public Voting', 'desc' => 'One vote per number, audited', 'tone' => 'rose'],
            ['kind' => 'Earned', 'name' => 'Jury Recognition', 'desc' => 'Decided by the award jury', 'tone' => 'indigo'],
            ['kind' => 'Trust', 'name' => 'Verified Business', 'desc' => 'Identity & contact checked by admin', 'tone' => 'sky'],
            ['kind' => 'Editorial', 'name' => 'Featured Brand', 'desc' => 'Chosen by our editors', 'tone' => 'cyan'],
            ['kind' => 'Paid', 'name' => 'Sponsored', 'desc' => 'Always labelled, never affects results', 'tone' => 'paid'],
        ];
    }

    public static function stories(): array
    {
        return [
            'lead' => [
                'tag' => 'Brand Story',
                'title' => 'Ayat Fashion opens its new outlet at Grand Plaza, Moghbazar',
                'excerpt' => 'The women’s fashion brand brings its occasion wear to a new outlet at Grand Plaza, Moghbazar (Level 1, Shop 116) — style that inspires, for moments that belong to you.',
                'author' => 'MetaSoft BD Desk',
                'meta' => 'Brand feature · 5 min read',
                'video' => '4:12',
                'from' => '#E11D48', 'to' => '#881337',
                'cover' => self::art('covers', 'brand3-cover.jpg.jpg'),
            ],
            'list' => [
                ['tag' => 'Interview', 'title' => 'Respite Care on bringing nursing and ICU-level care into the home', 'meta' => '6 min read', 'from' => '#1E5BB8', 'to' => '#0B2E6B', 'bn' => false, 'cover' => self::art('covers', 'brand4-cover.jpg.png')],
                ['tag' => 'Brand Journey', 'title' => 'ফুলের গয়নায় বিয়ের সাজ: সারিহা আর্টের গল্প', 'meta' => '৫ মিনিট', 'from' => '#C8A27A', 'to' => '#7C5A3A', 'bn' => true, 'cover' => self::art('covers', 'brand6-cover.jpg.jpg')],
                ['tag' => 'Success Story', 'title' => 'Girls Secret: beauty, hair and bridal care in a parlour made for women', 'meta' => '4 min read', 'from' => '#E9B949', 'to' => '#9A6B0A', 'bn' => false, 'cover' => self::art('covers', 'brand1-cover.jpg.png')],
                ['tag' => 'Business Feature', 'title' => 'Ragdhanu Mart: gadgets, skin care and imported products under one roof', 'meta' => '4 min read', 'from' => '#F59E0B', 'to' => '#0E7490', 'bn' => false, 'cover' => self::art('covers', 'brand5-cover.jpg.png')],
            ],
            'tags' => ['All stories', 'Founder Stories', 'Brand Journeys', 'Success Stories', 'Interviews', 'Business Features'],
        ];
    }

    public static function events(): array
    {
        return [
            ['day' => '24', 'month' => 'OCT', 'type' => 'Seminar', 'title' => 'Scaling your F-commerce brand in 2027', 'place' => 'Gulshan, Dhaka', 'time' => 'Sat · 3:00 PM', 'price' => 'Free', 'cta' => 'Register', 'from' => '#2F80C9', 'to' => '#0B4A80'],
            ['day' => '08', 'month' => 'NOV', 'type' => 'Networking', 'title' => 'Chattogram Founders Meetup', 'place' => 'GEC Circle, Chattogram', 'time' => 'Sat · 5:30 PM', 'price' => 'Free', 'cta' => 'RSVP', 'from' => '#1769AA', 'to' => '#073B66'],
            ['day' => '19', 'month' => 'NOV', 'type' => 'Brand Expo', 'title' => 'Made in Bangladesh Brand Expo 2026', 'place' => 'Purbachal, Dhaka', 'time' => '19–21 Nov · 10 AM', 'price' => '৳200', 'cta' => 'Get tickets', 'from' => '#3B82C4', 'to' => '#0E528C'],
            ['day' => '12', 'month' => 'DEC', 'type' => 'Awards Night', 'title' => 'Brand & Entrepreneur Awards 2026 — Grand Finale', 'place' => 'Dhaka', 'time' => 'Sat · 6:00 PM', 'price' => '৳2,500', 'cta' => 'Get passes', 'from' => '#0B4A80', 'to' => '#052B4C', 'gold' => true],
        ];
    }

    public static function automationFeatures(): array
    {
        return [
            ['icon' => 'store', 'title' => 'Ecommerce automation', 'desc' => 'Store, orders, courier & payments'],
            ['icon' => 'chart', 'title' => 'Business management', 'desc' => 'Inventory, POS, accounts & reports'],
            ['icon' => 'megaphone', 'title' => 'Marketing automation', 'desc' => 'Facebook, WhatsApp & SMS campaigns'],
            ['icon' => 'users', 'title' => 'Customer management', 'desc' => 'Inbox, CRM, follow-ups & fraud check'],
            ['icon' => 'sparkles', 'title' => 'AI-powered tools', 'desc' => 'Auto-replies, content & insights'],
        ];
    }

    public static function sponsorTiers(): array
    {
        return [
            ['name' => 'Title Partner', 'slots' => 1, 'size' => 'xl'],
            ['name' => 'Gold Sponsors', 'slots' => 3, 'size' => 'lg'],
            ['name' => 'Silver Sponsors', 'slots' => 5, 'size' => 'md'],
        ];
    }

    /**
     * Case-insensitive search over the sample brands and entrepreneurs
     * (name, category, district, division, brand). Returns at most 8 of each.
     */
    public static function search(string $q): array
    {
        $needle = mb_strtolower(trim($q));
        if ($needle === '') {
            return ['brands' => [], 'entrepreneurs' => []];
        }

        $match = fn (array $fields) => collect($fields)->contains(fn ($f) => str_contains(mb_strtolower((string) $f), $needle));

        $brands = array_values(array_filter(self::brands(), fn ($b) => $match([$b['name'], $b['category'], $b['district'], $b['division'], $b['description']])));
        $people = array_values(array_filter(self::entrepreneurs(), fn ($e) => $match([$e['name'], $e['brand'], $e['category'], $e['district']])));

        return ['brands' => array_slice($brands, 0, 8), 'entrepreneurs' => array_slice($people, 0, 8)];
    }

    /** Lightweight JSON for the client-side entrepreneur profile dialog (brands have their own /brand/{slug} page). */
    public static function profilesForClient(): array
    {
        $out = [];
        foreach (self::entrepreneurs() as $e) {
            $out['person:'.$e['slug']] = [
                'type' => 'person',
                'name' => $e['name'],
                'initials' => $e['initials'],
                'from' => $e['from'],
                'to' => $e['to'],
                'subtitle' => $e['role'].', '.$e['brand'],
                'description' => $e['story'],
                'facts' => [['Brand', $e['brand']], ['Category', $e['category']], ['District', $e['district']]],
                'badges' => [$e['recognition']],
                'sponsored' => false,
                'url' => $e['url'],
                'verified' => $e['verified'],
                'logo' => $e['avatar'] ?? null,
                'cover' => $e['cover'],
            ];
        }

        return $out;
    }
}
