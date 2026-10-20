<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A discovery listing for a well-known brand operating in Bangladesh
 * (database/sql/chunk70.sql) — researched by the MetaSoft BD team from the
 * brand's own public sources, NOT a registered brand. Kept out of `brands`
 * on purpose: listings never enter voting, awards or brand counts and never
 * carry the Verified badge. App\Support\Platform\DirectoryListings owns the
 * import, claim, replacement and archiving rules.
 */
class BrandDirectoryListing extends Model
{
    public const OWNERSHIP = [
        'bangladeshi' => 'Bangladeshi company',
        'international' => 'International group operating in Bangladesh',
        'unconfirmed' => null,
    ];

    protected $guarded = [];

    protected $casts = [
        'sources' => 'array',
        'source_checked_at' => 'date',
        'expires_at' => 'date',
        'claimed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (BrandDirectoryListing $l) {
            if ($l->isDirty('name') || ! $l->name_key) {
                $l->name_key = Brand::nameKey($l->name);
            }
            if ($l->isDirty('website')) {
                $l->official_domain = self::domainOf($l->website);
            }
        });
    }

    public function category()
    {
        return $this->belongsTo(BrandCategory::class, 'brand_category_id');
    }

    public function replacedBy()
    {
        return $this->belongsTo(Brand::class, 'replaced_by_brand_id');
    }

    public function claimedBy()
    {
        return $this->belongsTo(Brand::class, 'claimed_by_brand_id');
    }

    /** Shown publicly: active, facts confirmed from the official source, not past the re-check date, not replaced. */
    public function scopeListed($q)
    {
        return $q->where('status', 'active')
            ->where('verification_status', 'verified')
            ->whereNull('replaced_by_brand_id')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString()));
    }

    public function isListed(): bool
    {
        return $this->status === 'active' && $this->verification_status === 'verified'
            && ! $this->replaced_by_brand_id && (! $this->expires_at || ! $this->expires_at->endOfDay()->isPast());
    }

    /** "www.Example.com.bd/path" → "example.com.bd"; null for anything that isn't a web address. */
    public static function domainOf(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $host = parse_url(preg_match('#^https?://#i', $url) ? $url : 'https://'.$url, PHP_URL_HOST);
        if (! $host || ! str_contains($host, '.')) {
            return null;
        }
        $host = preg_replace('/^(www\d*|shop|m)\./', '', mb_strtolower($host));

        return mb_substr($host, 0, 190);
    }

    /**
     * The brand's own site rather than a page on someone else's platform:
     * at most one path segment ("aarong.com/bgd" yes, "daraz.com.bd/shop/x"
     * no). Only such URLs count as an official-domain match, so a seller
     * page on a marketplace never matches the marketplace's listing.
     */
    public static function isSiteRoot(?string $url): bool
    {
        if (! self::domainOf($url)) {
            return false;
        }
        $path = trim((string) parse_url(preg_match('#^https?://#i', $url) ? $url : 'https://'.$url, PHP_URL_PATH), '/');

        return $path === '' || ! str_contains($path, '/');
    }

    public function url(): string
    {
        return route('directory.show', $this->slug);
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name)) ?: [];
        $ini = '';
        foreach (array_slice($words, 0, 2) as $w) {
            $ini .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        return $ini ?: 'B';
    }

    public function ownershipLabel(): ?string
    {
        return self::OWNERSHIP[$this->ownership] ?? null;
    }
}
