<?php

namespace App\Support\Platform;

use App\Models\Brand;
use App\Models\BrandCategory;
use App\Models\BrandDirectoryListing;
use App\Models\PlatformAuditLog;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rules for the discovery directory (BrandDirectoryListing, chunk70.sql):
 *
 *  - import(): loads database/data/brand-directory.json. Upsert by slug, so
 *    re-running changes nothing; admin decisions (archive, claim,
 *    replacement) are never overwritten and archived rows are never revived —
 *    except rows archived only because their re-check date passed, which a
 *    newer check in the data file renews.
 *    A record whose normalized name or official domain already belongs to a
 *    registered brand is skipped — no duplicate entry.
 *  - recordClaim(): a new registration matching a listing (normalized name
 *    or official domain) only marks it claim_pending and tells Super Admin.
 *    Nothing is hidden, linked or replaced automatically.
 *  - replace(): Super Admin links the listing to the registered brand only
 *    once that brand is approved and carries the Verified badge with
 *    identity evidence; the listing is archived (never deleted) and the old
 *    /directory link redirects to the brand profile. restore() undoes it.
 *  - archiveExpired() / rebalance(): listings past their re-check date, or
 *    beyond a category's capacity once genuine verified brands fill it, are
 *    archived — never because some unrelated brand registered.
 * Every change writes a PlatformAuditLog row with the previous values.
 */
class DirectoryListings
{
    public const DATA_FILE = 'database/data/brand-directory.json';

    /** Verified-badge evidence (Brand::VERIFICATION_EVIDENCE keys) that ties a registered brand to the real business. */
    public const REPLACEMENT_EVIDENCE = ['trade_licence', 'tin_bin', 'owner_id', 'online_presence'];

    /** Columns refreshed from the data file on re-import (never status, claim or replacement columns). */
    private const DATA_COLUMNS = ['name', 'brand_category_id', 'description', 'website', 'facebook', 'ownership', 'ownership_note',
        'verification_status', 'source_url', 'sources', 'source_checked_at', 'expires_at', 'replacement_priority', 'homepage_order', 'review_note'];

    public function load(?string $path = null): array
    {
        $path ??= base_path(self::DATA_FILE);
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! is_array($data['listings'] ?? null)) {
            throw new DomainException("Directory data file is missing or not valid JSON: {$path}");
        }

        return $data;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}> [result, slug, detail] per record —
     *                                                            result is created|updated|renewed|unchanged|skipped|warning
     */
    public function import(array $data, bool $apply): array
    {
        $records = $this->validated($data);
        $checked = Carbon::parse($data['checked_at']);
        $expires = $checked->copy()->addMonths((int) ($data['expires_after_months'] ?? 6));
        $categories = BrandCategory::pluck('id', 'slug');
        [$brandKeys, $brandDomains] = $this->registeredIdentities();

        $report = [];
        foreach ($records as $r) {
            $existing = BrandDirectoryListing::where('slug', $r['slug'])->first();
            $key = Brand::nameKey($r['name']);
            $domain = BrandDirectoryListing::domainOf($r['website']);

            if (! $existing && (isset($brandKeys[$key]) || ($domain && isset($brandDomains[$domain])))) {
                $report[] = ['skipped', $r['slug'], 'a registered brand already uses this name or domain'];

                continue;
            }
            // Archived only because its re-check date passed, this data file is a newer check, and no
            // registered brand has taken the name/domain meanwhile: renew it.
            $renew = $existing && $existing->status === 'archived' && $existing->archive_reason === 'expired'
                && ! $expires->copy()->endOfDay()->isPast()
                && (! $existing->source_checked_at || $checked->gt($existing->source_checked_at))
                && ! isset($brandKeys[$key]) && ! ($domain && isset($brandDomains[$domain]));
            if ($existing && $existing->status !== 'active' && ! $renew) {
                $report[] = ['unchanged', $r['slug'], "{$existing->status} — left as Super Admin set it"];

                continue;
            }

            $categoryId = $categories[$r['category']] ?? null;
            if (! $categoryId) {
                $report[] = ['warning', $r['slug'], "category \"{$r['category']}\" not found — listed without a category"];
            }

            $values = [
                'name' => $r['name'],
                'brand_category_id' => $categoryId,
                'description' => $r['description'] ?? null,
                'website' => $r['website'] ?? null,
                'facebook' => $r['facebook'] ?? null,
                'ownership' => $r['ownership'] ?? 'unconfirmed',
                'ownership_note' => $r['ownership_note'] ?? null,
                'verification_status' => $r['verification_status'],
                'source_url' => $r['source_url'] ?? null,
                'sources' => array_values($r['sources'] ?? []),
                'source_checked_at' => $checked->toDateString(),
                'expires_at' => $expires->toDateString(),
                'replacement_priority' => (int) ($r['replacement_priority'] ?? 5),
                'homepage_order' => $r['homepage_order'] ?? null,
                'review_note' => $r['review_note'] ?? null,
            ];

            $listing = $existing ?? new BrandDirectoryListing(['slug' => $r['slug'], 'source_type' => 'public_research', 'membership_status' => 'not_member', 'status' => 'active']);
            $listing->fill($values);
            $dirty = array_intersect_key($listing->getDirty(), array_flip(self::DATA_COLUMNS));
            if ($renew) {
                $listing->fill(['status' => 'active', 'archived_at' => null, 'archive_reason' => null]);
            }
            if ($existing && ! $dirty && ! $renew) {
                $report[] = ['unchanged', $r['slug'], ''];

                continue;
            }

            $result = $renew ? 'renewed' : ($existing ? 'updated' : 'created');
            $report[] = [$result, $r['slug'], $existing ? implode(', ', array_keys($dirty)) : $r['verification_status']];
            if ($apply) {
                $before = $existing ? array_intersect_key($existing->getOriginal(), $dirty + ($renew ? ['status' => 1, 'archive_reason' => 1] : [])) : null;
                DB::transaction(function () use ($listing, $result, $dirty, $before) {
                    $listing->save();
                    PlatformAuditLog::record('directory.'.($result === 'created' ? 'imported' : $result), $listing,
                        ['changed' => array_keys($dirty), 'before' => $before], 'Imported from '.self::DATA_FILE);
                });
            }
        }

        return $report;
    }

    /** Active listings this brand matches by normalized name or official website domain. */
    public function matches(Brand $brand): Collection
    {
        if (! PlatformSchema::directoryReady()) {
            return collect();
        }
        $domain = BrandDirectoryListing::isSiteRoot($brand->website) ? BrandDirectoryListing::domainOf($brand->website) : null;

        return BrandDirectoryListing::where('status', 'active')
            ->where(fn ($q) => $q->where('name_key', Brand::nameKey($brand->name))->when($domain, fn ($w) => $w->orWhere('official_domain', $domain)))
            ->get();
    }

    /** A registration matched one or more listings: mark them claim_pending and tell Super Admin. Nothing is hidden or replaced. */
    public function recordClaim(Brand $brand): void
    {
        foreach ($this->matches($brand) as $listing) {
            if ($listing->claimed_by_brand_id && $listing->claimed_by_brand_id !== $brand->id) {
                PlatformAuditLog::record('directory.claim_conflict', $listing, ['brand_id' => $brand->id, 'existing_claim' => $listing->claimed_by_brand_id]);
            } else {
                $listing->update(['claimed_by_brand_id' => $brand->id, 'claimed_at' => now(), 'membership_status' => 'claim_pending']);
                PlatformAuditLog::record('directory.claim_matched', $listing, ['brand_id' => $brand->id, 'membership_status' => ['not_member', 'claim_pending']]);
            }
            PlatformNotifier::admins('directory_claim', 'Registration matches directory listing: '.$listing->name,
                $brand->name.' (#'.$brand->id.') matches the public listing by name or website. Check ownership before replacing it.',
                route('super.directory.index', ['q' => $listing->name]));
        }
    }

    /** The claiming brand was rejected or deleted: the listing goes back to not_member. */
    public function releaseClaims(Brand $brand, string $why): void
    {
        if (! PlatformSchema::directoryReady()) {
            return;
        }
        foreach (BrandDirectoryListing::where('claimed_by_brand_id', $brand->id)->whereNull('replaced_by_brand_id')->get() as $listing) {
            $listing->update(['claimed_by_brand_id' => null, 'claimed_at' => null, 'membership_status' => 'not_member']);
            PlatformAuditLog::record('directory.claim_released', $listing, ['brand_id' => $brand->id], $why);
        }
    }

    /** Why this brand may not replace this listing yet, or null when it may. */
    public function replacementBlocker(BrandDirectoryListing $listing, Brand $brand): ?string
    {
        if ($listing->status !== 'active' || $listing->replaced_by_brand_id) {
            return 'This listing is already archived or replaced.';
        }
        if (! $brand->isPublished()) {
            return 'The registered brand must be approved first.';
        }
        if (! $brand->is_verified) {
            return 'Verify the registered brand’s identity and ownership (Verified badge) first.';
        }
        if (PlatformSchema::reviewReady() && ! array_intersect((array) $brand->verification_basis, self::REPLACEMENT_EVIDENCE)) {
            return 'The Verified badge must record trade licence, TIN/BIN, owner NID or official website/social evidence.';
        }
        if ($listing->claimed_by_brand_id !== $brand->id && ! $this->matches($brand)->contains('id', $listing->id)) {
            return 'This brand does not match the listing by name or official website.';
        }

        return null;
    }

    public function replace(BrandDirectoryListing $listing, Brand $brand, ?string $reason = null): void
    {
        if ($blocker = $this->replacementBlocker($listing, $brand)) {
            throw new DomainException($blocker);
        }

        DB::transaction(function () use ($listing, $brand, $reason) {
            $before = $listing->only(['status', 'membership_status', 'replaced_by_brand_id', 'claimed_by_brand_id', 'archive_reason']);
            $listing->update([
                'replaced_by_brand_id' => $brand->id,
                'claimed_by_brand_id' => $brand->id,
                'membership_status' => 'member',
                'status' => 'archived',
                'archived_at' => now(),
                'archive_reason' => 'replaced',
            ]);
            PlatformAuditLog::record('directory.replaced', $listing, ['brand_id' => $brand->id, 'before' => $before], $reason);
        });
    }

    public function archive(BrandDirectoryListing $listing, string $why, ?string $note = null): void
    {
        if ($listing->status === 'archived') {
            return;
        }
        $before = $listing->only(['status', 'membership_status']);
        $listing->update(['status' => 'archived', 'archived_at' => now(), 'archive_reason' => $why]);
        PlatformAuditLog::record('directory.archived', $listing, ['archive_reason' => $why, 'before' => $before], $note);
    }

    /** Rollback of archive() or replace(): the listing is public again (if still within its re-check date). */
    public function restore(BrandDirectoryListing $listing, ?string $note = null): void
    {
        if ($listing->status !== 'archived') {
            return;
        }
        $before = $listing->only(['status', 'archive_reason', 'membership_status', 'replaced_by_brand_id']);
        $listing->update([
            'status' => 'active',
            'archived_at' => null,
            'archive_reason' => null,
            'replaced_by_brand_id' => null,
            'membership_status' => $listing->claimed_by_brand_id ? 'claim_pending' : 'not_member',
        ]);
        PlatformAuditLog::record('directory.restored', $listing, ['before' => $before], $note);
    }

    /** Active listings past their re-check date (already hidden publicly by scopeListed). */
    public function archiveExpired(bool $apply): Collection
    {
        $expired = BrandDirectoryListing::where('status', 'active')->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->toDateString())->orderBy('id')->get();
        if ($apply) {
            $expired->each(fn ($l) => $this->archive($l, 'expired', 'Source re-check date '.$l->expires_at->toDateString().' passed.'));
        }

        return $expired;
    }

    /**
     * Category capacity: a category with N genuine Verified registered brands
     * keeps at most max(0, $cap - N) listings; the rest are archived, highest
     * replacement_priority first. Unrelated categories are never touched.
     */
    public function rebalance(int $cap, bool $apply): Collection
    {
        $out = collect();
        $active = BrandDirectoryListing::where('status', 'active')->whereNotNull('brand_category_id')->get()->groupBy('brand_category_id');
        foreach ($active as $categoryId => $listings) {
            $genuine = Brand::published()->where('is_verified', true)->where('brand_category_id', $categoryId)->count();
            $over = $listings->count() - max(0, $cap - $genuine);
            if ($genuine === 0 || $over <= 0) {
                continue;
            }
            $drop = $listings->sortBy([['replacement_priority', 'desc'], ['id', 'desc']])->take($over);
            if ($apply) {
                $drop->each(fn ($l) => $this->archive($l, 'category_full', "{$genuine} verified registered brand(s) in this category; capacity {$cap}."));
            }
            $out = $out->concat($drop);
        }

        return $out;
    }

    private function validated(array $data): array
    {
        if (empty($data['checked_at']) || ! strtotime((string) $data['checked_at'])) {
            throw new DomainException('Directory data needs a valid "checked_at" date.');
        }

        $seen = [];
        foreach ($data['listings'] as $i => $r) {
            $where = 'listing #'.($i + 1).' ('.($r['slug'] ?? '?').')';
            if (! preg_match('/^[a-z0-9-]{2,160}$/', (string) ($r['slug'] ?? '')) || blank($r['name'] ?? null)) {
                throw new DomainException("{$where}: needs a lowercase slug and a name.");
            }
            if (! in_array($r['verification_status'] ?? null, ['verified', 'needs_review'], true)) {
                throw new DomainException("{$where}: verification_status must be verified or needs_review.");
            }
            if (! array_key_exists($r['ownership'] ?? 'unconfirmed', BrandDirectoryListing::OWNERSHIP)) {
                throw new DomainException("{$where}: unknown ownership value.");
            }
            foreach (['website', 'facebook', 'source_url'] as $f) {
                if (! empty($r[$f]) && ! preg_match('#^https://[^\s]+$#', $r[$f])) {
                    throw new DomainException("{$where}: {$f} must be an https URL.");
                }
            }
            if ($r['verification_status'] === 'verified' && (empty($r['website']) || empty($r['source_url']))) {
                throw new DomainException("{$where}: a verified listing needs its official website and source_url.");
            }

            $key = Brand::nameKey($r['name']);
            $domain = BrandDirectoryListing::domainOf($r['website'] ?? null);
            foreach (['slug' => $r['slug'], 'name' => $key, 'website domain' => $domain] as $label => $value) {
                if ($value === null) {
                    continue;
                }
                if (isset($seen[$label][$value])) {
                    throw new DomainException("{$where}: duplicate {$label} (also used by {$seen[$label][$value]}).");
                }
                $seen[$label][$value] = $r['slug'];
            }
        }

        return $data['listings'];
    }

    /** Normalized names and website domains of every registered (not rejected) brand. */
    private function registeredIdentities(): array
    {
        $brands = Brand::withTrashed()->where('status', '!=', 'rejected')->get(['name_key', 'website']);

        return [
            $brands->pluck('name_key')->filter()->flip()->all(),
            $brands->map(fn ($b) => BrandDirectoryListing::isSiteRoot($b->website) ? BrandDirectoryListing::domainOf($b->website) : null)->filter()->flip()->all(),
        ];
    }
}
