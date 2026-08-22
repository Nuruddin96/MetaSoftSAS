<?php

namespace App\Services\Tenant;

use App\Models\BusinessType;
use App\Models\Category;
use App\Models\ProductAttribute;
use App\Models\StoreSetting;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Owns the tenant onboarding wizard's state and side effects — step
 * resume/advance, saving each step's data into EXISTING fields
 * (store_settings, categories, tenants.business_type_id) rather than a
 * parallel "draft" store, and the one-time default-category seed.
 *
 * Every write here is idempotent/retry-safe by construction: advancing to
 * a step just overwrites tenants.onboarding_step, saving a step's fields
 * uses updateOrCreate on store_settings' existing (tenant_id, key) unique
 * key, and seedDefaultCategories() only ever runs for a tenant that
 * currently has zero categories of its own (see its docblock) — so a
 * tenant that abandons and resumes onboarding, or double-submits a step,
 * never ends up with duplicated or overwritten data.
 */
class TenantOnboardingService
{
    /** Wizard order — also literally the route/view segment for each step (tenant.onboarding.show / resources/views/tenant/onboarding/{step}.blade.php). */
    public const STEPS = [
        'business_type',
        'business_info',
        'categories',
        'store_settings',
        'first_product',
        'complete',
    ];

    public function isComplete(Tenant $tenant): bool
    {
        return ! $tenant->needsOnboarding();
    }

    public function currentStep(Tenant $tenant): string
    {
        $step = $tenant->onboarding_step;

        return in_array($step, self::STEPS, true) ? $step : self::STEPS[0];
    }

    public function advance(Tenant $tenant, string $step): void
    {
        if (! in_array($step, self::STEPS, true)) {
            return;
        }

        $tenant->update(['onboarding_step' => $step]);
    }

    /**
     * Step 1. $otherLabel is only ever persisted when the tenant picked the
     * 'other' business type — see the 'ব্যবসার ধরন সরাসরি লেখা নেই' free-text
     * field in that step's view, matching the requirement that "Other"
     * must never block signup for an unusual business.
     */
    public function saveBusinessType(Tenant $tenant, ?int $businessTypeId, ?string $otherLabel): void
    {
        $businessType = $businessTypeId ? BusinessType::find($businessTypeId) : null;

        $tenant->update(['business_type_id' => $businessType?->id]);

        if ($businessType) {
            $this->putSetting(
                'business_type_other_label',
                $businessType->slug === 'other' ? trim((string) $otherLabel) ?: null : null
            );
            $this->seedDefaultCategories($tenant, $businessType);
            $this->seedDefaultAttributes($tenant, $businessType);
            $this->maybeApplyTheme($tenant, $businessType);
        }

        $this->advance($tenant, 'business_info');
    }

    /**
     * "We've prepared some categories for your business" — only ever runs
     * for a tenant with ZERO categories of its own (never re-seeds on top
     * of manually created or previously seeded ones, and never runs twice
     * for the same tenant since this condition becomes false after the
     * first successful run — same idempotent-by-construction shape as
     * Tenant::booted()'s warehouse/store_settings auto-provisioning).
     *
     * Top-level rows (business_type_categories.parent_name IS NULL) are
     * created first, then subcategory rows (parent_name set) resolve their
     * parent from that same batch by name and are created with parent_id
     * set — now safe to do since CategoryController::store()/update()
     * accept parent_id (Catalog Architecture project). A subcategory row
     * whose named parent doesn't exist in this business type's own
     * top-level set (a seed-data typo) is skipped rather than created as
     * an orphaned top-level category — same "never guess" principle as
     * the rest of this class.
     */
    public function seedDefaultCategories(Tenant $tenant, BusinessType $businessType): int
    {
        if (Category::count() > 0) {
            return 0;
        }

        $created = 0;
        $idsByName = [];

        foreach ($businessType->categories as $defaultCategory) {
            if ($defaultCategory->parent_name !== null) {
                continue;
            }

            $category = Category::create([
                'tenant_id' => $tenant->id,
                'name' => $defaultCategory->name,
                'slug' => Str::slug($defaultCategory->name).'-'.Str::lower(Str::random(3)),
            ]);
            $idsByName[$defaultCategory->name] = $category->id;
            $created++;
        }

        foreach ($businessType->categories as $defaultCategory) {
            if ($defaultCategory->parent_name === null || ! isset($idsByName[$defaultCategory->parent_name])) {
                continue;
            }

            Category::create([
                'tenant_id' => $tenant->id,
                'name' => $defaultCategory->name,
                'slug' => Str::slug($defaultCategory->name).'-'.Str::lower(Str::random(3)),
                'parent_id' => $idsByName[$defaultCategory->parent_name],
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Seeds the tenant's reusable attribute vocabulary (Catalog
     * Architecture project) from business_types.default_attributes — the
     * same list Step 5's first-product screen already shows as plain
     * suggestion text (e.g. "Size, ML"). Purely additive convenience: no
     * values are pre-filled (the tenant types their own, e.g. "Red"/"M"),
     * and — same idempotent-by-condition guard as seedDefaultCategories()
     * — only ever runs for a tenant with ZERO attributes of its own.
     */
    public function seedDefaultAttributes(Tenant $tenant, BusinessType $businessType): int
    {
        if (ProductAttribute::count() > 0) {
            return 0;
        }

        $created = 0;

        foreach ($businessType->default_attributes ?? [] as $i => $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            ProductAttribute::create(['tenant_id' => $tenant->id, 'name' => $name, 'sort_order' => $i]);
            $created++;
        }

        return $created;
    }

    /**
     * Nice-to-have: config/themes.php's niche keys (skincare, fashion,
     * gadgets, organic_food, jewelry) happen to overlap with several
     * business type slugs — when they match, apply that storefront theme
     * automatically instead of leaving a skincare shop on the generic
     * 'default' look. Never overwrites a theme the tenant (or a previous
     * onboarding run) already changed away from 'default'.
     */
    protected function maybeApplyTheme(Tenant $tenant, BusinessType $businessType): void
    {
        if ($tenant->theme && $tenant->theme !== 'default') {
            return;
        }

        if (array_key_exists($businessType->slug, config('themes', []))) {
            $tenant->update(['theme' => $businessType->slug]);
        }
    }

    /**
     * Step 2. Every field is optional ("minimum information now, everything
     * later") — business address reuses the EXISTING footer_address
     * store_settings key (already shown/editable later in Website
     * settings, see WebsiteController::footer()) rather than a new column,
     * and division/district are stored purely for later display (invoice/
     * footer) since no pricing or shipping logic depends on the tenant's
     * own address — see DeliveryChargeService, which only ever looks at
     * the CUSTOMER's division.
     */
    public function saveBusinessInfo(Tenant $tenant, array $data): void
    {
        foreach (['footer_address', 'business_division_id', 'business_district_id', 'social_facebook'] as $key) {
            if (! empty($data[$key])) {
                $this->putSetting($key, (string) $data[$key]);
            }
        }

        $this->advance($tenant, 'categories');
    }

    /** Step 4. Both fields are already defaulted at signup (Tenant::booted()) — this only overrides them if the tenant chose to. */
    public function saveStoreSettings(Tenant $tenant, array $data): void
    {
        foreach (['delivery_charge_inside_dhaka', 'delivery_charge_outside_dhaka'] as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $this->putSetting($key, (string) $data[$key]);
            }
        }

        $this->advance($tenant, 'first_product');
    }

    public function complete(Tenant $tenant): void
    {
        $tenant->update([
            'onboarding_step' => 'complete',
            'onboarding_completed_at' => now(),
        ]);
    }

    protected function putSetting(string $key, ?string $value): void
    {
        StoreSetting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
