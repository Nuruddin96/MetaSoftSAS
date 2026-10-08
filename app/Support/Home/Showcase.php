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
    public static function stats(): array
    {
        return [
            ['value' => '12,400+', 'label' => 'Brand profiles', 'bn' => 'ব্র্যান্ড প্রোফাইল'],
            ['value' => '8,900+', 'label' => 'Entrepreneurs', 'bn' => 'উদ্যোক্তা'],
            ['value' => '64', 'label' => 'Districts', 'bn' => 'জেলা'],
            ['value' => '1.2M+', 'label' => 'Verified votes', 'bn' => 'যাচাইকৃত ভোট'],
        ];
    }

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
            ['name' => 'Fashion & Apparel', 'bn' => 'ফ্যাশন', 'count' => '1,240', 'icon' => 'sparkles', 'color' => '#7C3AED'],
            ['name' => 'Food & Beverage', 'bn' => 'খাদ্য ও পানীয়', 'count' => '2,180', 'icon' => 'heart', 'color' => '#EA580C'],
            ['name' => 'Tech & Startups', 'bn' => 'টেক ও স্টার্টআপ', 'count' => '860', 'icon' => 'rocket', 'color' => '#2563EB'],
            ['name' => 'Handicrafts', 'bn' => 'হস্তশিল্প', 'count' => '640', 'icon' => 'award', 'color' => '#B45309'],
            ['name' => 'Health & Beauty', 'bn' => 'স্বাস্থ্য ও সৌন্দর্য', 'count' => '720', 'icon' => 'star', 'color' => '#DB2777'],
            ['name' => 'Agro & Organic', 'bn' => 'কৃষি ও অর্গানিক', 'count' => '510', 'icon' => 'layers', 'color' => '#16A34A'],
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
            'status' => 'Voting open',
            'closes_at' => '2026-11-30T23:59:00+06:00',
            'closes_label' => '30 Nov 2026, 11:59 PM',
            'stats' => [
                ['value' => '25', 'label' => 'Categories'],
                ['value' => '50', 'label' => 'Awards'],
                ['value' => '2,380', 'label' => 'Nominees'],
                ['value' => '1.2M', 'label' => 'Verified votes'],
            ],
            'steps' => [
                ['label' => 'Nomination', 'meta' => 'Free · closed', 'state' => 'done'],
                ['label' => 'Public voting', 'meta' => '40% weight', 'state' => 'current'],
                ['label' => 'Jury review', 'meta' => '60% weight', 'state' => 'next'],
                ['label' => 'Winners', 'meta' => '12 Dec', 'state' => 'next'],
            ],
            'url' => '/award/bangladesh-brand-entrepreneur-awards-2026',
        ];
    }

    public static function otherAwards(): array
    {
        return [
            ['title' => 'Women Entrepreneur Awards 2026', 'category' => 'Leadership & Impact', 'nominees' => 186, 'status' => 'Nominations open', 'state' => 'nominate', 'when' => 'Closes 15 Nov', 'initials' => 'WE', 'from' => '#DB2777', 'to' => '#831843'],
            ['title' => 'Bangladesh Startup Awards', 'category' => 'Tech & Innovation', 'nominees' => 412, 'status' => 'Jury review', 'state' => 'jury', 'when' => 'Results 20 Dec', 'initials' => 'SA', 'from' => '#2563EB', 'to' => '#1E3A8A'],
            ['title' => 'Best Local Food Brand — Dhaka', 'category' => 'Food & Beverage · District round', 'nominees' => 96, 'status' => 'Voting open', 'state' => 'voting', 'when' => 'Closes 25 Nov', 'initials' => 'FB', 'from' => '#F97316', 'to' => '#9A3412'],
        ];
    }

    /** URL of a supplied showcase image: public/images/showcase/{logos|covers}/{file} (file name used verbatim). */
    public static function art(string $kind, string $file): string
    {
        return asset('images/showcase/'.$kind.'/'.$file);
    }

    /** District → Division → National ladder, with brand counts per division (sum = headline brand count). */
    public static function divisions(): array
    {
        return [
            ['name' => 'Dhaka', 'bn' => 'ঢাকা', 'brands' => 5180, 'districts' => 13],
            ['name' => 'Chattogram', 'bn' => 'চট্টগ্রাম', 'brands' => 2140, 'districts' => 11],
            ['name' => 'Rajshahi', 'bn' => 'রাজশাহী', 'brands' => 1320, 'districts' => 8],
            ['name' => 'Khulna', 'bn' => 'খুলনা', 'brands' => 1160, 'districts' => 10],
            ['name' => 'Rangpur', 'bn' => 'রংপুর', 'brands' => 840, 'districts' => 8],
            ['name' => 'Sylhet', 'bn' => 'সিলেট', 'brands' => 720, 'districts' => 4],
            ['name' => 'Barishal', 'bn' => 'বরিশাল', 'brands' => 580, 'districts' => 6],
            ['name' => 'Mymensingh', 'bn' => 'ময়মনসিংহ', 'brands' => 460, 'districts' => 4],
        ];
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

    public static function trending(): array
    {
        $tabs = [
            'trending' => ['Trending', 'flame', [['ayat-fashion', '▲ 86%', [2, 3, 3, 5, 4, 7, 9, 12]], ['sariha-art', '▲ 74%', [4, 4, 5, 5, 6, 7, 7, 9]], ['girls-secret', '▲ 61%', [1, 2, 2, 3, 5, 5, 6, 8]], ['ragdhanu-mart', '▲ 58%', [3, 3, 4, 4, 5, 6, 6, 8]], ['li-ummati', '▲ 44%', [2, 2, 3, 4, 4, 5, 6, 7]], ['respite-care', '▲ 39%', [3, 4, 4, 5, 5, 5, 6, 7]]]],
            'viewed' => ['Most Viewed', 'eye', [['ayat-fashion', '12.4K views', [5, 6, 5, 7, 8, 8, 10, 11]], ['ragdhanu-mart', '9.8K views', [6, 6, 7, 7, 8, 9, 9, 10]], ['girls-secret', '8.6K views', [4, 5, 6, 5, 7, 7, 8, 9]], ['respite-care', '7.1K views', [6, 5, 6, 7, 7, 8, 8, 9]], ['li-ummati', '6.3K views', [6, 5, 7, 6, 8, 7, 9, 10]], ['sariha-art', '5.9K views', [3, 4, 4, 5, 6, 6, 7, 8]]]],
            'voted' => ['Most Voted', 'vote', [['ayat-fashion', '5,140 votes', [3, 4, 5, 6, 8, 9, 10, 12]], ['girls-secret', '4,410 votes', [3, 4, 4, 6, 7, 8, 9, 11]], ['li-ummati', '4,030 votes', [2, 3, 5, 5, 6, 8, 9, 10]], ['sariha-art', '3,920 votes', [3, 4, 6, 6, 8, 9, 9, 12]], ['respite-care', '3,890 votes', [2, 4, 4, 6, 7, 7, 9, 10]], ['ragdhanu-mart', '3,610 votes', [1, 2, 4, 5, 6, 7, 9, 11]]]],
            'rising' => ['Rising', 'rocket', [['sariha-art', 'New listing', [1, 1, 2, 3, 3, 5, 6, 8]], ['respite-care', 'New listing', [1, 2, 2, 2, 4, 4, 6, 7]], ['ragdhanu-mart', '▲ 58%', [3, 4, 4, 5, 5, 5, 6, 7]], ['li-ummati', '▲ 44%', [2, 3, 3, 4, 4, 5, 5, 6]], ['ayat-fashion', '▲ 86%', [1, 2, 2, 3, 5, 5, 6, 8]], ['girls-secret', '▲ 61%', [2, 3, 3, 5, 4, 7, 9, 12]]]],
        ];

        $out = [];
        foreach ($tabs as $key => [$label, $icon, $rows]) {
            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'icon' => $icon,
                'rows' => array_map(fn ($r) => [
                    'brand' => self::brand($r[0]),
                    'metric' => $r[1],
                    'up' => str_starts_with($r[1], '▲') || str_starts_with($r[1], 'New'),
                    'spark' => self::sparkPath($r[2], 72, 24),
                ], $rows),
            ];
        }

        return $out;
    }

    public static function recognitionTypes(): array
    {
        return [
            ['name' => 'People’s Choice', 'bn' => 'জনগণের পছন্দ', 'desc' => 'Decided only by verified public votes.', 'how' => '100% public vote', 'color' => '#E5383B', 'icon' => 'users'],
            ['name' => 'Jury Choice', 'bn' => 'জুরি নির্বাচন', 'desc' => 'Selected by an independent jury of 21 industry leaders.', 'how' => '100% jury', 'color' => '#6366F1', 'icon' => 'scale'],
            ['name' => 'Rising Brand', 'bn' => 'উদীয়মান ব্র্যান্ড', 'desc' => 'For brands under 3 years with real growth and impact.', 'how' => '40% vote · 60% jury', 'color' => '#12A06E', 'icon' => 'rocket'],
            ['name' => 'Brand of the Year', 'bn' => 'বর্ষসেরা ব্র্যান্ড', 'desc' => 'The highest honour: jury review backed by business data.', 'how' => 'Jury + data review', 'color' => '#E9C46A', 'icon' => 'trophy'],
            ['name' => 'Featured Brand', 'bn' => 'ফিচার্ড ব্র্যান্ড', 'desc' => 'An editorial pick by the MetaSoft BD team. Never paid.', 'how' => 'Editorial', 'color' => '#0EA5E9', 'icon' => 'star'],
            ['name' => 'Entrepreneur of the Year', 'bn' => 'বর্ষসেরা উদ্যোক্তা', 'desc' => 'Honouring a founder’s journey, leadership and impact.', 'how' => 'Nomination + jury', 'color' => '#F59E0B', 'icon' => 'award'],
        ];
    }

    /** Every label used on the platform and how it is earned — the anti-pay-to-win legend. */
    public static function labels(): array
    {
        return [
            ['kind' => 'Earned', 'name' => 'Nomination', 'desc' => 'Open to every brand, free to apply', 'tone' => 'navy'],
            ['kind' => 'Earned', 'name' => 'Public Voting', 'desc' => 'Phone-verified, audited votes', 'tone' => 'rose'],
            ['kind' => 'Earned', 'name' => 'Jury Recognition', 'desc' => 'Independent, named jury', 'tone' => 'indigo'],
            ['kind' => 'Trust', 'name' => 'Verified Business', 'desc' => 'Trade licence & identity checked', 'tone' => 'sky'],
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
            ['day' => '24', 'month' => 'OCT', 'type' => 'Seminar', 'title' => 'Scaling your F-commerce brand in 2027', 'place' => 'Gulshan, Dhaka', 'time' => 'Sat · 3:00 PM', 'price' => 'Free', 'going' => '186 going', 'cta' => 'Register', 'from' => '#2563EB', 'to' => '#1E3A8A'],
            ['day' => '08', 'month' => 'NOV', 'type' => 'Networking', 'title' => 'Chattogram Founders Meetup', 'place' => 'GEC Circle, Chattogram', 'time' => 'Sat · 5:30 PM', 'price' => 'Free', 'going' => '92 going', 'cta' => 'RSVP', 'from' => '#0D9488', 'to' => '#134E4A'],
            ['day' => '19', 'month' => 'NOV', 'type' => 'Brand Expo', 'title' => 'Made in Bangladesh Brand Expo 2026', 'place' => 'Purbachal, Dhaka', 'time' => '19–21 Nov · 10 AM', 'price' => '৳200', 'going' => '2.4K going', 'cta' => 'Get tickets', 'from' => '#EA580C', 'to' => '#7C2D12'],
            ['day' => '12', 'month' => 'DEC', 'type' => 'Awards Night', 'title' => 'Brand & Entrepreneur Awards 2026 — Grand Finale', 'place' => 'Dhaka', 'time' => 'Sat · 6:00 PM', 'price' => '৳2,500', 'going' => 'Limited passes', 'cta' => 'Get passes', 'from' => '#A87B12', 'to' => '#3F2D06', 'gold' => true],
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

    /** SVG polyline path for a small sparkline. */
    public static function sparkPath(array $pts, int $w, int $h): string
    {
        $max = max($pts);
        $min = min($pts);
        $range = ($max - $min) ?: 1;
        $n = count($pts) - 1;

        return collect($pts)->map(function ($p, $i) use ($w, $h, $min, $range, $n) {
            $x = round($i * $w / $n, 1);
            $y = round($h - 2 - ($p - $min) / $range * ($h - 4), 1);

            return ($i ? 'L' : 'M').$x.' '.$y;
        })->implode(' ');
    }

    /** Lightweight JSON for the client-side quick-view drawer (keyed by slug). */
    public static function profilesForClient(): array
    {
        $out = [];
        foreach (self::brands() as $slug => $b) {
            $out['brand:'.$slug] = [
                'type' => 'brand',
                'name' => $b['name'],
                'initials' => $b['initials'],
                'from' => $b['from'],
                'to' => $b['to'],
                'subtitle' => $b['category'].' · '.implode(', ', array_unique([$b['district'], $b['division']])),
                'description' => $b['description'],
                'facts' => [['Category', $b['category']], ['Location', $b['district']]],
                'badges' => $b['badges'],
                'sponsored' => $b['tag'] === 'sponsored',
                'url' => $b['url'],
                'verified' => $b['verified'],
                'logo' => $b['logo'],
                'cover' => $b['cover'],
            ];
        }
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
