<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A brand on the recognition platform (database/sql/chunk64.sql).
 *
 * Lifecycle: pending → approved (public, gets its permanent slug) |
 * rejected; approved ↔ suspended. Only Super Admin changes status.
 *
 * Earned recognition, trust and paid promotion are separate columns and
 * must never be derived from one another — see chunk64.sql's header.
 */
class Brand extends Model
{
    use SoftDeletes;

    /**
     * Fields that, once a brand is approved, an owner can only *request*
     * to change (BrandChangeRequest) — they identify the brand publicly.
     * Contact phone/email join them once the brand is verified.
     */
    public const IDENTITY_FIELDS = ['name', 'logo_path', 'brand_category_id', 'district', 'division', 'founder_name'];

    public const VERIFIED_CONTACT_FIELDS = ['phone', 'email'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'suspended'];

    protected $guarded = [];

    protected $casts = [
        'gallery' => 'array',
        'is_verified' => 'boolean',
        'is_featured' => 'boolean',
        'is_sponsored' => 'boolean',
        'approved_at' => 'datetime',
        'verified_at' => 'datetime',
        'sponsored_until' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (Brand $b) {
            if ($b->isDirty('name') || ! $b->name_key) {
                $b->name_key = self::nameKey($b->name);
            }
        });
    }

    public function owner()
    {
        return $this->belongsTo(BrandOwner::class, 'brand_owner_id');
    }

    public function category()
    {
        return $this->belongsTo(BrandCategory::class, 'brand_category_id');
    }

    public function changeRequests()
    {
        return $this->hasMany(BrandChangeRequest::class);
    }

    public function pendingChange()
    {
        return $this->hasOne(BrandChangeRequest::class)->where('status', 'pending')->latestOfMany();
    }

    public function nominations()
    {
        return $this->hasMany(AwardNomination::class);
    }

    public function recognitions()
    {
        return $this->hasMany(AwardRecognition::class);
    }

    public function finalistNominations()
    {
        return $this->hasMany(AwardNomination::class)->where('status', 'finalist')->latest();
    }

    /** Eager loads needed by toCard()/badges() for a list of brands. */
    public const CARD_RELATIONS = ['category', 'recognitions.award', 'finalistNominations.award'];

    public function voteEntries()
    {
        return $this->hasMany(VoteEntry::class);
    }

    public function scopePublished($q)
    {
        return $q->where('status', 'approved')->whereNotNull('slug');
    }

    public function isPublished(): bool
    {
        return $this->status === 'approved' && $this->slug !== null && ! $this->trashed();
    }

    /** Normalized name for duplicate detection: case/space/punctuation-insensitive, Bangla-safe. */
    public static function nameKey(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '', $key) ?? $key;

        return mb_substr($key, 0, 150);
    }

    /** Another live (not rejected/deleted) brand already uses this name. */
    public static function nameTaken(string $name, ?int $exceptId = null): bool
    {
        return self::where('name_key', self::nameKey($name))
            ->where('status', '!=', 'rejected')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    /**
     * Permanent public slug, assigned once on first approval. Duplicate
     * names get -2, -3…; soft-deleted brands keep their slug reserved so an
     * old shared link never starts pointing at a different brand. Names
     * with no Latin characters (e.g. Bangla-only) fall back to brand-{id}.
     */
    public function assignSlug(): string
    {
        if ($this->slug) {
            return $this->slug;
        }

        $base = Str::slug(Str::limit($this->name, 120, '')) ?: 'brand-'.$this->id;
        $slug = $base;
        $n = 2;
        while (self::withTrashed()->where('slug', $slug)->where('id', '!=', $this->id)->exists()) {
            $slug = $base.'-'.$n++;
        }

        $this->slug = $slug;

        return $slug;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? asset('storage/'.$this->logo_path) : null;
    }

    public function galleryUrls(): array
    {
        return array_map(fn ($p) => asset('storage/'.$p), $this->gallery ?? []);
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

    /** Stable cover/monogram gradient for brands (picked from the id, not random per render). */
    public function gradient(): array
    {
        $pairs = [['#128155', '#0C5C3C'], ['#2563EB', '#1E3A8A'], ['#7C3AED', '#3B0764'], ['#EA580C', '#7C2D12'], ['#0D9488', '#134E4A'], ['#DB2777', '#831843'], ['#B45309', '#78350F'], ['#0EA5E9', '#0C4A6E']];

        return $pairs[($this->id ?? 0) % count($pairs)];
    }

    public function profileUrl(): ?string
    {
        return $this->slug ? route('brands.show', $this->slug) : null;
    }

    public function voteUrl(): ?string
    {
        return $this->slug ? route('vote.show', $this->slug) : null;
    }

    /** Paid placement currently running (always shown as "Sponsored", never as recognition). */
    public function isSponsoredNow(): bool
    {
        return $this->is_sponsored && (! $this->sponsored_until || $this->sponsored_until->endOfDay()->isFuture());
    }

    /** Fields that currently need Super Admin review before they change. */
    public function reviewFields(): array
    {
        if (! in_array($this->status, ['approved', 'suspended'], true)) {
            return [];
        }

        return $this->is_verified ? array_merge(self::IDENTITY_FIELDS, self::VERIFIED_CONTACT_FIELDS) : self::IDENTITY_FIELDS;
    }

    /**
     * Profile completeness. Required registration fields are worth half;
     * each optional section adds to the rest. Optional fields never block
     * listing or approval — this only drives the dashboard's suggestions.
     */
    public function completion(): array
    {
        $optional = [
            'description' => ['Add a business description', (bool) $this->description],
            'website' => ['Add your website', (bool) $this->website],
            'social' => ['Add social media links', (bool) ($this->facebook || $this->instagram || $this->tiktok || $this->youtube)],
            'products' => ['Add product / service information', (bool) $this->products_info],
            'gallery' => ['Add photos to your gallery', ! empty($this->gallery)],
        ];

        $required = (bool) ($this->name && $this->logo_path && $this->brand_category_id && $this->founder_name && $this->phone && $this->email && $this->district);
        $done = count(array_filter($optional, fn ($o) => $o[1]));
        $percent = ($required ? 50 : 30) + (int) round($done / count($optional) * 50);

        return [
            'percent' => min(100, $percent),
            'missing' => array_values(array_map(fn ($o) => $o[0], array_filter($optional, fn ($o) => ! $o[1]))),
        ];
    }

    /** Earned badges (recognitions + finalist nominations) in x-plat.badge shape. */
    public function badges(): array
    {
        $map = ['winner' => 'winner', 'peoples_choice' => 'people', 'jury_choice' => 'jury'];
        $labels = ['winner' => 'Winner', 'peoples_choice' => 'People’s Choice', 'jury_choice' => 'Jury Choice'];

        $recognitions = $this->relationLoaded('recognitions') ? $this->recognitions : $this->recognitions()->with('award')->latest()->get();
        $finalists = $this->relationLoaded('finalistNominations') ? $this->finalistNominations : $this->finalistNominations()->with('award')->get();

        $out = [];
        foreach ($recognitions as $r) {
            $out[] = ['type' => $map[$r->type], 'label' => $r->title ?: $labels[$r->type].' '.($r->award?->year ?? '')];
        }
        foreach ($finalists as $n) {
            $out[] = ['type' => 'finalist', 'label' => 'Finalist '.($n->award?->year ?? '')];
        }

        return array_map(fn ($b) => ['type' => $b['type'], 'label' => trim($b['label'])], $out);
    }

    /** Array in App\Support\Home\Showcase's brand shape, so x-plat.brand-card renders real brands too. */
    public function toCard(): array
    {
        [$from, $to] = $this->gradient();

        return [
            'live' => true,
            'slug' => $this->slug,
            'name' => $this->name,
            'initials' => $this->initials(),
            'logo' => $this->logoUrl(),
            'cover' => $this->galleryUrls()[0] ?? null,
            'from' => $from,
            'to' => $to,
            'category' => $this->category?->name ?? 'Brand',
            'district' => $this->district,
            'division' => $this->division,
            'description' => $this->description ?: 'A '.($this->category?->name ?? 'Bangladeshi').' brand from '.$this->district.'.',
            'founded' => $this->founded_year,
            'verified' => $this->is_verified,
            'badges' => $this->badges(),
            'followers' => null,
            'rating' => null,
            'tag' => $this->isSponsoredNow() ? 'sponsored' : ($this->is_featured ? 'editor' : null),
            'founder' => $this->founder_name,
            'url' => $this->profileUrl(),
            'vote_url' => $this->voteUrl(),
        ];
    }
}
