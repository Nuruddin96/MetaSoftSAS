<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\ProductVariant;
use App\Services\DeliveryChargeService;
use App\Services\Storefront\OrderPlacementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class LandingPageController extends Controller
{
    /**
     * A draft is visible only to a logged-in tenant panel user of THIS
     * tenant (so the owner can preview before publishing) — everyone else
     * gets a 404, same as any other not-yet-public content. Auth::guard
     * ('tenant') is the tenant panel's own session guard (see CLAUDE.md's
     * "Auth guards" section), so this only ever grants access to someone
     * already logged into this exact tenant's /panel.
     */
    protected function findVisible(string $slug): LandingPage
    {
        // Scoped the same way Storefront\ProductController::show() already
        // scopes its own `variants` eager load — an inactive variant must
        // never reach the checkout widget as a selectable option.
        $landingPage = LandingPage::with(['product.variants' => fn ($q) => $q->where('is_active', 1), 'product.images'])
            ->where('slug', $slug)->firstOrFail();

        if (! $landingPage->isPublished() && ! Auth::guard('tenant')->check()) {
            abort(404);
        }

        return $landingPage;
    }

    public function show(string $slug, DeliveryChargeService $deliveryCharge)
    {
        $landingPage = $this->findVisible($slug);

        return view('storefront.landing', array_merge([
            'tenant' => app('currentTenant'),
            'landingPage' => $landingPage,
            'product' => $landingPage->product,
            'divisions' => DB::table('bd_divisions')->orderBy('id')->get(),
            'districts' => DB::table('bd_districts')->orderBy('name')->get(),
        ], $deliveryCharge->chargesForView()));
    }

    public function order(Request $request, string $slug, OrderPlacementService $placement)
    {
        $landingPage = $this->findVisible($slug);

        $data = $request->validate([
            'variant_id' => 'required|integer',
            'qty' => 'nullable|integer|min:1|max:100',
            'customer_name' => 'required|string|max:150',
            'customer_phone' => 'required|regex:/^01[3-9][0-9]{8}$/',
            'customer_address' => 'required|string|max:1000',
            'division_id' => 'required|integer|exists:bd_divisions,id',
            'district_id' => 'required|integer|exists:bd_districts,id',
        ], [
            'customer_phone.regex' => 'সঠিক মোবাইল নাম্বার দিন (01XXXXXXXXX)।',
        ]);

        // Tenant-scoped via ProductVariant's BelongsToTenant global scope,
        // and must belong to THIS landing page's bound product — the page
        // must only ever be able to sell the one product it was built for.
        $variant = ProductVariant::where('product_id', $landingPage->product_id)->find($data['variant_id']);

        if (! $variant) {
            return back()->withInput()->with('error', 'এই ভ্যারিয়েন্টটি এই প্রোডাক্টের জন্য পাওয়া যায়নি।');
        }

        $lines = collect([['variant' => $variant, 'qty' => $data['qty'] ?? 1]]);

        try {
            $order = $placement->place($lines, $data, 'web');
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'insufficient_stock:')) {
                return back()->withInput()->with('error', 'দুঃখিত, এই প্রোডাক্টটি এখন পর্যাপ্ত স্টকে নেই।');
            }

            if (str_starts_with($e->getMessage(), 'inactive_variant:')) {
                return back()->withInput()->with('error', 'দুঃখিত, এই ভ্যারিয়েন্টটি এখন পাওয়া যাচ্ছে না।');
            }

            throw $e;
        }

        $placement->sendCapiPurchase($order, $request);

        return redirect()->route('storefront.order.success', $order->order_number);
    }
}
