<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * bKash PGW — Tokenized Checkout (platform-level, for tenant subscriptions).
 *
 * Flow: grant token -> create payment -> user pays on bKash page
 *       -> bKash redirects back -> execute payment -> verify
 *
 * .env:
 *   BKASH_USERNAME=
 *   BKASH_PASSWORD=
 *   BKASH_APP_KEY=
 *   BKASH_APP_SECRET=
 *   BKASH_SANDBOX=true
 */
class BkashService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('payment.bkash.sandbox', true)
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout';
    }

    public function isConfigured(): bool
    {
        return (bool) config('payment.bkash.app_key') && (bool) config('payment.bkash.app_secret');
    }

    /** Grant token is valid ~1 hour; cache it slightly shorter. */
    protected function token(): ?string
    {
        return Cache::remember('bkash_token', 3000, function () {
            $response = Http::withHeaders([
                'username' => config('payment.bkash.username'),
                'password' => config('payment.bkash.password'),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl.'/token/grant', [
                'app_key' => config('payment.bkash.app_key'),
                'app_secret' => config('payment.bkash.app_secret'),
            ])->json();

            return $response['id_token'] ?? null;
        });
    }

    protected function headers(): array
    {
        return [
            'Authorization' => $this->token(),
            'X-App-Key' => config('payment.bkash.app_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /** Returns ['paymentID' => ..., 'bkashURL' => ...] to redirect the user to. */
    public function createPayment(float $amount, string $invoice, string $payerPhone, string $callbackUrl): array
    {
        return Http::withHeaders($this->headers())
            ->post($this->baseUrl.'/create', [
                'mode' => '0011',
                'payerReference' => $payerPhone,
                'callbackURL' => $callbackUrl,
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => 'BDT',
                'intent' => 'sale',
                'merchantInvoiceNumber' => $invoice,
            ])->json() ?? [];
    }

    /** Called after bKash redirects back with status=success. Money moves here. */
    public function executePayment(string $paymentId): array
    {
        return Http::withHeaders($this->headers())
            ->post($this->baseUrl.'/execute', ['paymentID' => $paymentId])
            ->json() ?? [];
    }

    /** Fallback verification if execute times out. */
    public function queryPayment(string $paymentId): array
    {
        return Http::withHeaders($this->headers())
            ->post($this->baseUrl.'/payment/status', ['paymentID' => $paymentId])
            ->json() ?? [];
    }
}
