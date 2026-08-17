<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Tenant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'custom_domain_dns_verified_at' => 'datetime',
        'ai_paused_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            $tenant->uuid = $tenant->uuid ?: (string) Str::uuid();
            $tenant->trial_ends_at = $tenant->trial_ends_at ?: now()->addDays(7);
        });

        // Auto-provision on signup: default warehouse + store settings.
        // This is what makes the "blank site ready instantly" flow work.
        static::created(function (Tenant $tenant) {
            $tenant->warehouses()->create([
                'name' => 'Main Warehouse',
                'is_default' => true,
            ]);

            $tenant->storeSettings()->createMany([
                ['key' => 'delivery_charge_inside_dhaka',  'value' => '60'],
                ['key' => 'delivery_charge_outside_dhaka', 'value' => '120'],
                ['key' => 'currency', 'value' => 'BDT'],
            ]);
        });
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }

    public function storeSettings()
    {
        return $this->hasMany(StoreSetting::class);
    }

    public function adBillingAccount()
    {
        return $this->hasOne(AdBillingAccount::class);
    }

    public function aiCreditAccount()
    {
        return $this->hasOne(AiCreditAccount::class);
    }

    /**
     * True once database/sql/chunk39.sql's ai_paused_at/
     * ai_paused_by_super_admin_id/ai_paused_reason columns exist — same
     * additive-column guard as MessengerMessage::sentByColumnReady().
     * Used to gate both reading isAiPaused() and writing a pause/resume
     * from App\Http\Controllers\SuperAdmin\AiCreditController.
     */
    public static function aiPauseColumnsReady(): bool
    {
        return Schema::hasColumn('tenants', 'ai_paused_at');
    }

    /**
     * Phase 14 — a super-admin-imposed pause, independent of (checked
     * ALONGSIDE, never instead of) the tenant's own ai_agent_enabled
     * toggle — see chunk39.sql's docblock for why this is a separate
     * column rather than reusing either that toggle or tenants.status.
     */
    public function isAiPaused(): bool
    {
        return self::aiPauseColumnsReady() && $this->ai_paused_at !== null;
    }

    /** Plan limit check, e.g. isWithinLimit('max_products', $count) */
    public function isWithinLimit(string $limit, int $currentCount): bool
    {
        $max = $this->plan->{$limit} ?? null;

        return $max === null || $currentCount < $max;
    }

    /**
     * Simple tenant/admin-facing custom-domain status — Pending / Approved
     * / Active / Rejected / None — derived from the existing
     * custom_domain_request_status ENUM plus the separate custom_domain/
     * custom_domain_verified columns, rather than a new status column.
     * 'active' is deliberately NOT a value custom_domain_request_status
     * itself ever takes (see SuperAdmin\TenantController::
     * activateDomain()/deactivateDomain()) — it's derived purely from
     * custom_domain_verified being true, so deactivating just flips that
     * one flag back to false (status reverts to 'approved' display, ready
     * to be re-activated) without losing the request/approval history.
     * The legacy 'dns_verified' value (from the original, now-optional
     * DNS-TXT-verification flow) is folded into 'pending' here — it was
     * never more than "still not yet approved" from the tenant's point of
     * view.
     */
    public function customDomainDisplayStatus(): string
    {
        if ($this->custom_domain_verified && $this->custom_domain) {
            return 'active';
        }

        return match ($this->custom_domain_request_status) {
            'pending', 'dns_verified' => 'pending',
            'approved' => 'approved',
            'rejected' => 'rejected',
            default => 'none',
        };
    }

    /** True once database/sql/chunk47.sql's Cloudflare connection-tracking columns exist. */
    public static function cloudflareDomainColumnsReady(): bool
    {
        return Schema::hasColumn('tenants', 'custom_domain_connect_status');
    }

    /**
     * Granular Cloudflare-aware status — the exact vocabulary the task
     * asks the Super Admin Custom Domain UI to show: Pending / Approved /
     * DNS Required / Connecting / Connected / Active / Rejected / Failed.
     * A strict superset of customDomainDisplayStatus() above (which stays
     * as the simple 5-state version the tenant-facing card + existing
     * tests already use) — this one additionally surfaces the
     * cloudflare-connection sub-state once a request has been approved.
     */
    public function customDomainConnectionStatus(): string
    {
        if ($this->custom_domain_verified && $this->custom_domain) {
            return 'active';
        }

        if (self::cloudflareDomainColumnsReady()
            && $this->custom_domain_connect_status
            && $this->custom_domain_connect_status !== 'not_connected') {
            return $this->custom_domain_connect_status; // dns_required|connecting|connected|failed
        }

        return match ($this->custom_domain_request_status) {
            'pending', 'dns_verified' => 'pending',
            'approved' => 'approved',
            'rejected' => 'rejected',
            default => 'none',
        };
    }

    /**
     * Auto-contrast text color for a background of primary_color — used
     * anywhere a tenant's own brand color becomes a UI background (e.g.
     * the mobile panel bottom nav) so icon/label legibility never depends
     * on which hex a tenant happened to pick. Perceived-brightness (YIQ)
     * formula, not full WCAG relative luminance — simpler, and more than
     * accurate enough for a binary light/dark text choice.
     */
    public function primaryColorContrastText(): string
    {
        $hex = ltrim($this->primary_color ?: '#128155', '#');

        if (! preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
            return '#ffffff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

        return $yiq >= 150 ? '#111111' : '#ffffff';
    }

    public function url(): string
    {
        if (config('app.tenancy_mode', 'subdomain') === 'path') {
            return 'https://'.config('app.central_domain').'/shop/'.$this->subdomain;
        }

        $domain = ($this->custom_domain_verified && $this->custom_domain)
            ? $this->custom_domain
            : $this->subdomain.'.'.config('app.central_domain');

        return 'https://'.$domain;
    }
}
