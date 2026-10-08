<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** An award season (e.g. "Bangladesh Brand Awards 2026"), managed by Super Admin. */
class Award extends Model
{
    public const STATUSES = [
        'draft' => 'Draft',
        'nominations_open' => 'Nominations open',
        'voting' => 'Public voting',
        'jury_review' => 'Jury review',
        'completed' => 'Completed',
        'archived' => 'Archived',
    ];

    protected $guarded = [];

    protected $casts = [
        'is_featured' => 'boolean',
        'nomination_starts_at' => 'datetime',
        'nomination_ends_at' => 'datetime',
        'voting_starts_at' => 'datetime',
        'voting_ends_at' => 'datetime',
    ];

    public function categories()
    {
        return $this->hasMany(AwardCategory::class)->orderBy('sort_order')->orderBy('id');
    }

    public function nominations()
    {
        return $this->hasMany(AwardNomination::class);
    }

    public function recognitions()
    {
        return $this->hasMany(AwardRecognition::class);
    }

    public function campaigns()
    {
        return $this->hasMany(VoteCampaign::class);
    }

    public function scopePublic($q)
    {
        return $q->whereNotIn('status', ['draft']);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Owners may submit nominations only while nominations are open (and inside the window, if one is set). */
    public function acceptsNominations(): bool
    {
        return $this->status === 'nominations_open'
            && (! $this->nomination_starts_at || $this->nomination_starts_at->isPast())
            && (! $this->nomination_ends_at || $this->nomination_ends_at->isFuture());
    }

    public static function uniqueSlug(string $title, int $year, ?int $exceptId = null): string
    {
        $base = Str::slug(Str::limit($title, 180, '')) ?: 'award';
        if (! str_contains($base, (string) $year)) {
            $base .= '-'.$year;
        }
        $slug = $base;
        $n = 2;
        while (self::where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
