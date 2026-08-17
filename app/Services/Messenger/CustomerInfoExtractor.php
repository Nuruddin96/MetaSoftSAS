<?php

namespace App\Services\Messenger;

use Illuminate\Support\Facades\DB;

/**
 * Regex/keyword extraction of customer info (phone/name/address/district/
 * upazila) from Messenger message text. Deliberately deterministic, no LLM
 * — the Order-auto-creation trigger is specifically "a valid BD phone
 * number was found", which is a regex match, so this stays fast and
 * dependency-free on the webhook's synchronous path. Free-text intent-only
 * messages ("আমি একটা ড্রেস নিতে চাই") correctly extract nothing.
 */
class CustomerInfoExtractor
{
    /** @var array<int, object>|null cached bd_upazilas rows (joined to district/division), loaded once per request */
    protected static ?array $upazilaCache = null;

    /** @var array<int, object>|null cached bd_districts rows, loaded once per request */
    protected static ?array $districtCache = null;

    public function phone(string $text): ?string
    {
        $normalized = preg_replace('/[\s\-]/', '', $text);

        if (! preg_match('/(?:\+?88)?(01[3-9]\d{8})/', $normalized, $m)) {
            return null;
        }

        return $m[1];
    }

    public function name(string $text): ?string
    {
        if (preg_match('/(?:নাম|Name)\s*[:：]\s*([^\n,،]+)/iu', $text, $m)) {
            return trim($m[1]) ?: null;
        }

        if (preg_match('/আমার\s+নাম\s+([^\n,،.।]+)/u', $text, $m)) {
            return trim($m[1]) ?: null;
        }

        return null;
    }

    /**
     * @return array{address: ?string, division_id: ?int, district_id: ?int, upazila_id: ?int}
     */
    public function location(string $text): array
    {
        $result = ['address' => null, 'division_id' => null, 'district_id' => null, 'upazila_id' => null];

        if (preg_match('/(?:ঠিকানা|Address)\s*[:：]\s*(.+)/iu', $text, $m)) {
            $result['address'] = trim($m[1]) ?: null;
        }

        // Upazilas checked before districts so a specific thana name
        // ("Mirpur") wins over a same-text district name ("Dhaka") when
        // both appear; within each list, longest name first so a longer
        // name wins over a shorter one that happens to be its substring.
        foreach ([static::upazilas(), static::districts()] as $list) {
            foreach ($list as $loc) {
                if (mb_stripos($text, $loc->name) !== false || mb_stripos($text, $loc->bn_name) !== false) {
                    $result['division_id'] = $loc->division_id;
                    $result['district_id'] = $loc->district_id;
                    $result['upazila_id'] = $loc->upazila_id;

                    if (! $result['address']) {
                        $result['address'] = trim($text);
                    }

                    return $result;
                }
            }
        }

        return $result;
    }

    /**
     * Merges phone/name/location across a conversation's inbound messages,
     * oldest first — later messages override phone/name (treated as
     * corrections), location is kept from the first successful match but
     * address text keeps updating as long as a match is present.
     *
     * @param  iterable<\App\Models\MessengerMessage>  $messages
     * @return array{phone: ?string, name: ?string, address: ?string, division_id: ?int, district_id: ?int, upazila_id: ?int}
     */
    public function fromConversation(iterable $messages): array
    {
        $result = ['phone' => null, 'name' => null, 'address' => null, 'division_id' => null, 'district_id' => null, 'upazila_id' => null];

        foreach ($messages as $message) {
            $text = (string) $message->message_text;

            if ($text === '') {
                continue;
            }

            $result['phone'] = $this->phone($text) ?? $result['phone'];
            $result['name'] = $this->name($text) ?? $result['name'];

            $location = $this->location($text);

            if ($location['district_id'] && ! $result['district_id']) {
                $result['division_id'] = $location['division_id'];
                $result['district_id'] = $location['district_id'];
                $result['upazila_id'] = $location['upazila_id'];
            }

            $result['address'] = $location['address'] ?? $result['address'];
        }

        return $result;
    }

    /** @return array<int, object{name:string,bn_name:string,division_id:int,district_id:int,upazila_id:int}> */
    protected static function upazilas(): array
    {
        if (static::$upazilaCache !== null) {
            return static::$upazilaCache;
        }

        return static::$upazilaCache = DB::table('bd_upazilas as u')
            ->join('bd_districts as d', 'd.id', '=', 'u.district_id')
            ->select('u.name', 'u.bn_name', 'd.division_id', 'u.district_id', 'u.id as upazila_id')
            ->get()
            ->sortByDesc(fn ($l) => max(mb_strlen($l->name), mb_strlen($l->bn_name)))
            ->values()->all();
    }

    /** @return array<int, object{name:string,bn_name:string,division_id:int,district_id:int,upazila_id:null}> */
    protected static function districts(): array
    {
        if (static::$districtCache !== null) {
            return static::$districtCache;
        }

        return static::$districtCache = DB::table('bd_districts')
            ->select('name', 'bn_name', 'division_id', DB::raw('id as district_id'), DB::raw('NULL as upazila_id'))
            ->get()
            ->sortByDesc(fn ($l) => max(mb_strlen($l->name), mb_strlen($l->bn_name)))
            ->values()->all();
    }
}
