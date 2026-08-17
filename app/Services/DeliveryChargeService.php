<?php

namespace App\Services;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for "inside vs outside Dhaka" delivery pricing —
 * extracted from CheckoutController::place(), which already had this exact
 * logic (division-based, not district-based: "inside Dhaka" means
 * division_id equals the Dhaka DIVISION, covering all 13 districts under
 * it — Dhaka, Gazipur, Narayanganj, Tangail, etc. — not just Dhaka city).
 * That was the only correct, already-shipped definition in this codebase;
 * this service reuses it verbatim rather than inventing a district-level
 * rule that would have disagreed with the live storefront checkout.
 *
 * store_settings.delivery_charge_inside_dhaka/outside_dhaka already exist
 * and are already seeded per-tenant at signup (Tenant::booted()) — no new
 * settings table or column was needed for this.
 */
class DeliveryChargeService
{
    public function isInsideDhaka(?int $divisionId): bool
    {
        return $divisionId !== null && $divisionId === $this->dhakaDivisionId();
    }

    public function calculate(?int $divisionId): float
    {
        $charges = $this->chargesForView();

        return $this->isInsideDhaka($divisionId) ? $charges['chargeInside'] : $charges['chargeOutside'];
    }

    /**
     * Both configured amounts + the Dhaka division id, shaped for embedding
     * directly into a Blade view's client-side JS (json_encode-able) — lets
     * a division <select> change recalculate the displayed delivery charge
     * instantly with no extra request, the same way the existing product/
     * variant picker already works entirely client-side once its price data
     * is embedded.
     *
     * @return array{dhakaDivisionId: int, chargeInside: float, chargeOutside: float}
     */
    public function chargesForView(): array
    {
        $charges = StoreSetting::whereIn('key', ['delivery_charge_inside_dhaka', 'delivery_charge_outside_dhaka'])
            ->pluck('value', 'key');

        return [
            'dhakaDivisionId' => $this->dhakaDivisionId(),
            'chargeInside' => (float) ($charges['delivery_charge_inside_dhaka'] ?? 60),
            'chargeOutside' => (float) ($charges['delivery_charge_outside_dhaka'] ?? 120),
        ];
    }

    /**
     * Deliberately NOT statically cached (unlike CustomerInfoExtractor's
     * district/upazila lists): this class is cheap to query and is
     * typically instantiated fresh per call site within a request anyway,
     * so a static cache bought little in production while creating a real
     * cross-test-class staleness bug under PHPUnit's single long-running
     * process (each test class gets its own fresh in-memory database, but
     * a static property survives across all of them) — found via an actual
     * full-suite run, not theoretical. One indexed lookup per call is a
     * fine trade for never returning a division id from a different
     * database than the one currently connected.
     */
    protected function dhakaDivisionId(): int
    {
        return (int) DB::table('bd_divisions')->where('name', 'Dhaka')->value('id');
    }
}
