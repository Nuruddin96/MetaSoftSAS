<?php

namespace App\Support\Home;

use Illuminate\Support\Str;

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

    /** Keyed by slug. `tag` is the placement label: 'editor' (earned/editorial) or 'sponsored' (paid). */
    public static function brands(): array
    {
        $rows = [
            ['Nakshi Ghor', 'NG', 'F59E0B', 'B45309', 'Handicrafts', 'Jashore', 'Khulna', 'Hand-stitched nakshi kantha by 300+ rural women artisans of Jashore.', 2014, [['People’s Choice 2025', 'people']], '18.2K', 4.9, 'editor', 'nusrat-jahan'],
            ['Krishi Bondhu', 'KB', '16A34A', '14532D', 'AgriTech', 'Rajshahi', 'Rajshahi', 'An app connecting 40,000 farmers directly to wholesale buyers.', 2021, [['Jury Pick 2026', 'jury']], '22.6K', 4.8, null, 'tanvir-ahmed'],
            ['Rong Tuli', 'RT', 'EC4899', '9D174D', 'Home Décor', 'Narayanganj', 'Dhaka', 'Hand-painted rickshaw-art home décor shipped nationwide.', 2020, [['Finalist 2026', 'finalist']], '15.1K', 4.8, null, 'sadia-islam'],
            ['Mati Ceramics', 'MC', 'D97706', '78350F', 'Home & Living', 'Bogura', 'Rajshahi', 'Contemporary terracotta crafted with traditional potters.', 2019, [], '7.4K', 4.7, null, null],
            ['GreenCycle BD', 'GC', '0D9488', '134E4A', 'Sustainability', 'Gazipur', 'Dhaka', 'Turning garment waste into recycled yarn and textiles.', 2022, [['Rising Brand finalist', 'finalist']], '9.9K', 4.7, null, null],
            ['Jamdani House', 'JH', '7C3AED', '3B0764', 'Fashion', 'Narayanganj', 'Dhaka', 'Handwoven Jamdani sarees from third-generation weavers on the Shitalakshya.', 2011, [['Brand of the Year 2025', 'winner']], '26.4K', 4.9, 'editor', null],
            ['Sylhet Leaf Tea Co.', 'SL', '16A34A', '14532D', 'Food & Beverage', 'Moulvibazar', 'Sylhet', 'Single-estate teas sourced directly from small gardens in Srimangal.', 2017, [['Jury Choice 2025', 'jury']], '14.9K', 4.8, 'editor', 'farhana-rahman'],
            ['PayDesh', 'PD', '2563EB', '1E3A8A', 'Fintech', 'Dhaka', 'Dhaka', 'Simple digital payments and invoicing for 60,000 small shops.', 2020, [['Rising Brand finalist', 'finalist']], '31.2K', 4.6, 'editor', 'arif-hossain'],
            ['Sundarban Madhu', 'SM', 'D97706', '78350F', 'Organic Food', 'Khulna', 'Khulna', 'Raw mangrove honey collected with licensed mouals of the Sundarbans.', 2018, [], '9.8K', 4.7, 'sponsored', null],
            ['Bhorer Pitha', 'BP', 'F43F5E', '881337', 'Food', 'Cumilla', 'Chattogram', 'Winter pitha, made fresh every morning and delivered across Cumilla.', 2023, [], '11.3K', 4.9, null, null],
            ['ShikkhaPath', 'SP', '0EA5E9', '0C4A6E', 'EdTech', 'Chattogram', 'Chattogram', 'Bangla-first video lessons used by 210,000 SSC & HSC students.', 2019, [['Jury Choice 2024', 'jury']], '48.2K', 4.7, null, null],
            ['Dhaka Threads', 'DT', '8B5CF6', '4C1D95', 'Fashion', 'Dhaka', 'Dhaka', 'Everyday streetwear cut and sewn in small Mirpur workshops.', 2022, [], '19.7K', 4.6, null, null],
            ['Padma Leather', 'PL', '78716C', '292524', 'Leather Goods', 'Dhaka', 'Dhaka', 'Full-grain leather bags made by Hazaribagh-trained craftsmen in Savar.', 2016, [], '21.7K', 4.7, null, null],
            ['Shonar Bangla Spices', 'SB', 'EAB308', '713F12', 'Food', 'Bogura', 'Rajshahi', 'Stone-ground spices sourced from farmers across the north.', 2021, [], '6.2K', 4.8, null, null],
            ['Nirvana Skincare', 'NS', '14B8A6', '134E4A', 'Beauty', 'Dhaka', 'Dhaka', 'Clean skincare formulated for South Asian skin and humid weather.', 2021, [['People’s Choice nominee', 'finalist']], '28.5K', 4.6, null, null],
            ['Rangpur Shatranji', 'RS', 'DC2626', '7F1D1D', 'Handicrafts', 'Rangpur', 'Rangpur', 'Revived shatranji flat-weave rugs from Nisbetganj, sold in 11 countries.', 2015, [['District winner 2025', 'people']], '8.1K', 4.9, null, null],
            ['Kirtonkhola Foods', 'KF', '0891B2', '164E63', 'Food & Beverage', 'Barishal', 'Barishal', 'River-fresh hilsa and frozen seafood with a cold chain from Barishal.', 2020, [], '5.6K', 4.6, null, null],
            ['Brahmaputra Bamboo', 'BB', '65A30D', '365314', 'Sustainability', 'Mymensingh', 'Mymensingh', 'Bamboo furniture and home goods replacing single-use plastic.', 2022, [], '4.3K', 4.7, null, null],
        ];

        $brands = [];
        foreach ($rows as [$name, $ini, $from, $to, $cat, $district, $division, $desc, $founded, $badges, $followers, $rating, $tag, $founder]) {
            $slug = Str::slug($name);
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
                'founded' => $founded,
                'verified' => true,
                'badges' => array_map(fn ($b) => ['label' => $b[0], 'type' => $b[1]], $badges),
                'followers' => $followers,
                'rating' => $rating,
                'tag' => $tag,
                'founder' => $founder,
                'url' => '/brand/'.$slug,
            ];
        }

        return $brands;
    }

    public static function brand(string $slug): ?array
    {
        return self::brands()[$slug] ?? null;
    }

    /** Featured brands row (mixes editorial picks with one clearly labelled sponsored placement). */
    public static function featuredBrands(): array
    {
        return array_map(fn ($s) => self::brand($s), ['jamdani-house', 'sylhet-leaf-tea-co', 'paydesh', 'sundarban-madhu']);
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

    public static function entrepreneurs(): array
    {
        $rows = [
            ['Nusrat Jahan', 'NJ', 'B45309', '7C2D12', 'nakshi-ghor', 'Founder', 'We started with 12 women in one courtyard in Jashore. Today 300 artisans earn a steady income from their own craft.', ['Entrepreneur of the Year 2025', 'winner']],
            ['Tanvir Ahmed', 'TA', '0EA5E9', '1E3A8A', 'krishi-bondhu', 'Co-founder & CEO', 'Left a bank job to build a marketplace for 40,000 farmers in the north.', ['Jury Pick 2026', 'jury']],
            ['Farhana Rahman', 'FR', '16A34A', '14532D', 'sylhet-leaf-tea-co', 'Founder', 'Bringing Srimangal’s small tea gardens to shelves in 9 countries.', ['Women Entrepreneur finalist', 'finalist']],
            ['Arif Hossain', 'AH', '6366F1', '312E81', 'paydesh', 'Founder & CEO', 'Built a payments app for corner shops after his father’s grocery went cashless.', ['Rising Founder 2025', 'people']],
            ['Sadia Islam', 'SI', 'EC4899', '831843', 'rong-tuli', 'Founder & Designer', 'Turning rickshaw art into a design brand loved by a new generation.', ['People’s Choice nominee', 'finalist']],
        ];

        return array_map(function ($r) {
            [$name, $ini, $from, $to, $brandSlug, $role, $story, $rec] = $r;
            $brand = self::brand($brandSlug);

            return [
                'slug' => Str::slug($name),
                'name' => $name,
                'initials' => $ini,
                'from' => '#'.$from,
                'to' => '#'.$to,
                'role' => $role,
                'brand' => $brand['name'],
                'brand_slug' => $brandSlug,
                'category' => $brand['category'],
                'district' => $brand['district'],
                'story' => $story,
                'recognition' => ['label' => $rec[0], 'type' => $rec[1]],
                'url' => '/entrepreneur/'.Str::slug($name),
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
            'rising' => ['Rising Brand', [['krishi-bondhu', 4820], ['rong-tuli', 3610], ['mati-ceramics', 2490], ['greencycle-bd', 1780]]],
            'fashion' => ['Fashion', [['jamdani-house', 5140], ['dhaka-threads', 3920], ['padma-leather', 2210]]],
            'food' => ['Food & Beverage', [['bhorer-pitha', 4410], ['sylhet-leaf-tea-co', 4030], ['shonar-bangla-spices', 1960], ['kirtonkhola-foods', 1220]]],
            'tech' => ['Tech & Startups', [['shikkhapath', 6020], ['paydesh', 5470], ['krishi-bondhu', 3180]]],
            'crafts' => ['Handicrafts', [['nakshi-ghor', 3890], ['rangpur-shatranji', 3240], ['mati-ceramics', 1870]]],
            'women' => ['Women-led', [['nakshi-ghor', 4720], ['nirvana-skincare', 3360], ['rong-tuli', 2950], ['sylhet-leaf-tea-co', 2400]]],
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
            'trending' => ['Trending', 'flame', [['bhorer-pitha', '▲ 212%', [2, 3, 3, 5, 4, 7, 9, 12]], ['rong-tuli', '▲ 74%', [4, 4, 5, 5, 6, 7, 7, 9]], ['dhaka-threads', '▲ 96%', [1, 2, 2, 3, 5, 5, 6, 8]], ['rangpur-shatranji', '▲ 61%', [3, 3, 4, 4, 5, 6, 6, 8]], ['shonar-bangla-spices', '▲ 58%', [2, 2, 3, 4, 4, 5, 6, 7]], ['greencycle-bd', '▲ 44%', [3, 4, 4, 5, 5, 5, 6, 7]]]],
            'viewed' => ['Most Viewed', 'eye', [['shikkhapath', '48.2K views', [5, 6, 5, 7, 8, 8, 10, 11]], ['paydesh', '31.7K views', [6, 6, 7, 7, 8, 9, 9, 10]], ['nirvana-skincare', '28.5K views', [4, 5, 6, 5, 7, 7, 8, 9]], ['jamdani-house', '26.4K views', [6, 5, 6, 7, 7, 8, 8, 9]], ['padma-leather', '21.7K views', [6, 5, 7, 6, 8, 7, 9, 10]], ['nakshi-ghor', '18.2K views', [3, 4, 4, 5, 6, 6, 7, 8]]]],
            'voted' => ['Most Voted', 'vote', [['shikkhapath', '6,020 votes', [3, 4, 5, 6, 8, 9, 10, 12]], ['paydesh', '5,470 votes', [3, 4, 4, 6, 7, 8, 9, 11]], ['jamdani-house', '5,140 votes', [2, 3, 5, 5, 6, 8, 9, 10]], ['krishi-bondhu', '4,820 votes', [3, 4, 6, 6, 8, 9, 9, 12]], ['nakshi-ghor', '4,720 votes', [2, 4, 4, 6, 7, 7, 9, 10]], ['bhorer-pitha', '4,410 votes', [1, 2, 4, 5, 6, 7, 9, 11]]]],
            'rising' => ['Rising', 'rocket', [['brahmaputra-bamboo', 'New · Mymensingh', [1, 1, 2, 3, 3, 5, 6, 8]], ['kirtonkhola-foods', 'New · Barishal', [1, 2, 2, 2, 4, 4, 6, 7]], ['greencycle-bd', '▲ 44%', [3, 4, 4, 5, 5, 5, 6, 7]], ['mati-ceramics', '▲ 39%', [2, 3, 3, 4, 4, 5, 5, 6]], ['dhaka-threads', '▲ 96%', [1, 2, 2, 3, 5, 5, 6, 8]], ['bhorer-pitha', '▲ 212%', [2, 3, 3, 5, 4, 7, 9, 12]]]],
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
                'tag' => 'Founder Story',
                'title' => 'From a Jashore courtyard to 300 artisans: how Nakshi Ghor rebuilt the kantha economy',
                'excerpt' => 'Nusrat Jahan never planned to run a company. A decade later her brand ships hand-stitched kantha to 14 countries — and pays artisans 3× the local average.',
                'author' => 'Rafiq Karim',
                'meta' => '2 Oct 2026 · 8 min read',
                'video' => '4:12',
                'from' => '#B45309', 'to' => '#431407',
            ],
            'list' => [
                ['tag' => 'Interview', 'title' => 'Tanvir Ahmed on why agritech in Bangladesh needs patience, not hype', 'meta' => '6 min read · 28 Sep', 'from' => '#0EA5E9', 'to' => '#0C4A6E', 'bn' => false],
                ['tag' => 'Brand Journey', 'title' => 'পাটের ব্যাগ দিয়ে ইউরোপ জয়: এক তরুণ উদ্যোক্তার গল্প', 'meta' => '৫ মিনিট · ২৫ সেপ্টেম্বর', 'from' => '#65A30D', 'to' => '#365314', 'bn' => true],
                ['tag' => 'Success Story', 'title' => 'How PayDesh signed 60,000 corner shops without a single billboard', 'meta' => '7 min read · 21 Sep', 'from' => '#2563EB', 'to' => '#1E3A8A', 'bn' => false],
                ['tag' => 'Business Feature', 'title' => 'Inside Bogura’s terracotta revival: 5 brands to watch', 'meta' => '4 min read · 18 Sep', 'from' => '#D97706', 'to' => '#78350F', 'bn' => false],
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
                'subtitle' => $b['category'].' · '.$b['district'].', '.$b['division'],
                'description' => $b['description'],
                'facts' => [['Founded', (string) $b['founded']], ['Followers', $b['followers']], ['Rating', '★ '.$b['rating']]],
                'badges' => $b['badges'],
                'sponsored' => $b['tag'] === 'sponsored',
                'url' => $b['url'],
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
            ];
        }

        return $out;
    }
}
