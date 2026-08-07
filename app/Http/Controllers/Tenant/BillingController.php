<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Tenant;
use App\Services\Payment\BkashService;
use App\Services\Payment\SslCommerzService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function index()
    {
        $tenant = app('currentTenant');

        return view('tenant.billing', [
            'tenant'        => $tenant,
            'plans'         => Plan::where('is_active', 1)->orderBy('sort_order')->get(),
            'payments'      => SubscriptionPayment::where('tenant_id', $tenant->id)->latest()->limit(10)->get(),
            'hasBkash'      => $this->bkashConfigured(),
            'hasSsl'        => $this->sslConfigured(),
            'manualNote'    => config('payment.manual_note'),
            'supportPhone'  => config('payment.support_phone'),
            'supportWa'     => config('payment.support_whatsapp'),
        ]);
    }

    /** Guards against leftover placeholder text (e.g. "আপনার_app_key") in .env being mistaken for real credentials. */
    protected function bkashConfigured(): bool
    {
        if (! config('payment.online_enabled')) {
            return false;
        }

        foreach ([config('payment.bkash.username'), config('payment.bkash.password'), config('payment.bkash.app_key'), config('payment.bkash.app_secret')] as $v) {
            if (blank($v) || str_starts_with((string) $v, 'আপনার_')) {
                return false;
            }
        }

        return true;
    }

    protected function sslConfigured(): bool
    {
        if (! config('payment.online_enabled')) {
            return false;
        }

        foreach ([config('payment.sslcommerz.store_id'), config('payment.sslcommerz.store_password')] as $v) {
            if (blank($v) || str_starts_with((string) $v, 'আপনার_')) {
                return false;
            }
        }

        return true;
    }

    public function pay(Request $request, SslCommerzService $ssl, BkashService $bkash)
    {
        $data = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'cycle'   => 'required|in:monthly,yearly',
            'gateway' => 'required|in:sslcommerz,bkash',
        ]);

        // guard: never start a gateway that has no real credentials
        $configured = $data['gateway'] === 'bkash' ? $this->bkashConfigured() : $this->sslConfigured();

        if (! $configured) {
            return back()->with('error', 'এই পেমেন্ট পদ্ধতি এখনো চালু হয়নি। অন্য পদ্ধতি বেছে নিন বা অ্যাডমিনের সাথে যোগাযোগ করুন।');
        }

        $tenant = app('currentTenant');
        $plan   = Plan::findOrFail($data['plan_id']);
        $amount = $data['cycle'] === 'yearly' ? $plan->price_yearly : $plan->price_monthly;

        if ($amount <= 0) {
            return back()->with('error', 'এই প্ল্যানের দাম সেট করা নেই।');
        }

        $payment = SubscriptionPayment::create([
            'tenant_id' => $tenant->id,
            'gateway'   => $data['gateway'],
            'amount'    => $amount,
            'status'    => 'pending',
            'gateway_response' => ['plan_id' => $plan->id, 'cycle' => $data['cycle']],
        ]);

        $tranId = 'SUB-' . $tenant->id . '-' . $payment->id;
        $payment->update(['trx_id' => $tranId]);

        $callback = $tenant->url() . '/panel/billing/callback/' . $data['gateway'];

        return $data['gateway'] === 'bkash'
            ? $this->startBkash($bkash, $payment, $tenant, $amount, $tranId, $callback)
            : $this->startSsl($ssl, $payment, $tenant, $amount, $tranId, $callback);
    }

    protected function startBkash(BkashService $bkash, SubscriptionPayment $payment, Tenant $tenant, float $amount, string $tranId, string $callback)
    {
        $session = $bkash->createPayment($amount, $tranId, $tenant->owner_phone, $callback);

        if (! empty($session['bkashURL'])) {
            $payment->update([
                'gateway_response' => array_merge($payment->gateway_response, [
                    'paymentID' => $session['paymentID'] ?? null,
                ]),
            ]);

            return redirect()->away($session['bkashURL']);
        }

        $payment->update(['status' => 'failed', 'gateway_response' => $session]);

        return back()->with('error', 'bKash পেমেন্ট শুরু করা যায়নি: ' . ($session['statusMessage'] ?? 'গেটওয়ে সাড়া দেয়নি।'));
    }

    protected function startSsl(SslCommerzService $ssl, SubscriptionPayment $payment, Tenant $tenant, float $amount, string $tranId, string $callback)
    {
        $session = $ssl->createSession([
            'total_amount' => $amount,
            'tran_id'      => $tranId,
            'success_url'  => $callback,
            'fail_url'     => $callback,
            'cancel_url'   => $callback,
            'cus_name'     => $tenant->owner_name,
            'cus_email'    => $tenant->owner_email,
            'cus_phone'    => $tenant->owner_phone,
            'cus_add1'     => $tenant->store_name,
            'cus_city'     => 'Dhaka',
            'value_a'      => $tenant->id,
        ]);

        if (($session['status'] ?? '') === 'SUCCESS' && ! empty($session['GatewayPageURL'])) {
            return redirect()->away($session['GatewayPageURL']);
        }

        $payment->update(['status' => 'failed', 'gateway_response' => $session]);

        return back()->with('error', 'পেমেন্ট শুরু করা যায়নি: ' . ($session['failedreason'] ?? 'গেটওয়ে সাড়া দেয়নি।'));
    }

    /** Both gateways redirect the browser back here. */
    public function callback(Request $request, string $gateway, SslCommerzService $ssl, BkashService $bkash)
    {
        return $gateway === 'bkash'
            ? $this->bkashCallback($request, $bkash)
            : $this->sslCallback($request, $ssl);
    }

    protected function bkashCallback(Request $request, BkashService $bkash)
    {
        $paymentId = $request->input('paymentID');
        $status    = $request->input('status'); // success | failure | cancel

        $payment = $paymentId
            ? SubscriptionPayment::where('gateway_response->paymentID', $paymentId)->first()
            : null;

        if (! $payment) {
            return redirect()->route('tenant.billing')->with('error', 'পেমেন্ট রেকর্ড পাওয়া যায়নি।');
        }

        if ($payment->status === 'completed') {
            return redirect()->route('tenant.billing')->with('success', 'পেমেন্ট আগেই সম্পন্ন হয়েছে।');
        }

        if ($status !== 'success') {
            $payment->update(['status' => 'failed']);
            return redirect()->route('tenant.billing')->with('error', 'পেমেন্ট বাতিল হয়েছে।');
        }

        // execute = money actually moves; without this the payment never completes
        $result = $bkash->executePayment($paymentId);

        if (($result['transactionStatus'] ?? '') !== 'Completed') {
            $result = $bkash->queryPayment($paymentId); // fallback check
        }

        if (($result['transactionStatus'] ?? '') !== 'Completed') {
            $payment->update(['status' => 'failed', 'gateway_response' => array_merge($payment->gateway_response, ['result' => $result])]);
            return redirect()->route('tenant.billing')->with('error', 'পেমেন্ট সম্পন্ন হয়নি। টাকা কাটা গেলে অ্যাডমিনের সাথে যোগাযোগ করুন।');
        }

        $payment->update(['trx_id' => $result['trxID'] ?? $payment->trx_id]);
        $this->activateSubscription($payment, $result);

        return redirect()->route('tenant.billing')->with('success', '🎉 পেমেন্ট সফল! ট্রানজেকশন: ' . ($result['trxID'] ?? ''));
    }

    protected function sslCallback(Request $request, SslCommerzService $ssl)
    {
        $tranId = $request->input('tran_id');
        $status = $request->input('status');

        $payment = $tranId ? SubscriptionPayment::where('trx_id', $tranId)->first() : null;

        if (! $payment) {
            return redirect()->route('tenant.billing')->with('error', 'পেমেন্ট রেকর্ড পাওয়া যায়নি।');
        }

        if ($payment->status === 'completed') {
            return redirect()->route('tenant.billing')->with('success', 'পেমেন্ট আগেই সম্পন্ন হয়েছে।');
        }

        if ($status !== 'VALID') {
            $payment->update(['status' => 'failed']);
            return redirect()->route('tenant.billing')->with('error', 'পেমেন্ট সম্পন্ন হয়নি। আবার চেষ্টা করুন।');
        }

        $validation = $ssl->validate($request->input('val_id', ''));

        if (! in_array($validation['status'] ?? '', ['VALID', 'VALIDATED'])) {
            $payment->update(['status' => 'failed', 'gateway_response' => $validation]);
            return redirect()->route('tenant.billing')->with('error', 'পেমেন্ট ভেরিফাই করা যায়নি।');
        }

        $this->activateSubscription($payment, $validation);

        return redirect()->route('tenant.billing')->with('success', '🎉 পেমেন্ট সফল! সাবস্ক্রিপশন চালু হয়েছে।');
    }

    protected function activateSubscription(SubscriptionPayment $payment, array $result): void
    {
        $meta   = $payment->gateway_response ?? [];
        $planId = $meta['plan_id'] ?? 1;
        $cycle  = $meta['cycle'] ?? 'monthly';
        $days   = $cycle === 'yearly' ? 365 : 30;

        $tenant = Tenant::find($payment->tenant_id);

        $base = ($tenant->subscription_ends_at && $tenant->subscription_ends_at->isFuture())
            ? $tenant->subscription_ends_at
            : now();

        $endsAt = $base->copy()->addDays($days);

        $subscription = Subscription::create([
            'tenant_id'     => $tenant->id,
            'plan_id'       => $planId,
            'billing_cycle' => $cycle,
            'starts_at'     => now(),
            'ends_at'       => $endsAt,
            'status'        => 'active',
        ]);

        $payment->update([
            'status'           => 'completed',
            'subscription_id'  => $subscription->id,
            'paid_at'          => now(),
            'gateway_response' => array_merge($meta, ['result' => $result]),
        ]);

        $tenant->update([
            'status'               => 'active',
            'plan_id'              => $planId,
            'subscription_ends_at' => $endsAt,
        ]);

        $this->creditReferralCommission($tenant, $payment);
    }

    /** 20% one-time commission to the referring affiliate on the tenant's FIRST completed payment. */
    protected function creditReferralCommission(Tenant $tenant, SubscriptionPayment $payment): void
    {
        if (! $tenant->referred_by_affiliate_id) {
            return;
        }

        $alreadyCredited = AffiliateCommission::where('tenant_id', $tenant->id)
            ->where('type', 'saas')->exists();

        if ($alreadyCredited) {
            return; // only the FIRST payment earns the referral bonus
        }

        $affiliate = Affiliate::find($tenant->referred_by_affiliate_id);
        if (! $affiliate) {
            return;
        }

        AffiliateCommission::create([
            'affiliate_id' => $affiliate->id,
            'type'         => 'saas',
            'source_label' => $tenant->store_name,
            'tenant_id'    => $tenant->id,
            'amount'       => round($payment->amount * 0.20, 2),
            'is_recurring' => false,
            'status'       => 'pending',
            'note'         => 'প্রথম সাবস্ক্রিপশন পেমেন্টের ২০% — ওয়ান টাইম',
        ]);
    }
}
