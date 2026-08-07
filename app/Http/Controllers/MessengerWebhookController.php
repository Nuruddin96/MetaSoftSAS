<?php

namespace App\Http\Controllers;

use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Services\Messenger\MessengerApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ONE webhook URL handles Messenger events for every tenant.
 * Meta sends the Facebook Page ID in each event; we look up which
 * tenant owns that page and file the message under them.
 */
class MessengerWebhookController extends Controller
{
    /** Meta calls this once (GET) to verify the webhook URL. */
    public function verify(Request $request)
    {
        if ($request->query('hub_mode') === 'subscribe'
            && $request->query('hub_verify_token') === config('messenger.verify_token')) {
            return response($request->query('hub_challenge'), 200);
        }

        abort(403);
    }

    /** Meta calls this (POST) every time a message/event happens. */
    public function receive(Request $request, MessengerApi $api)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Messenger webhook: rejected request with invalid or missing X-Hub-Signature-256.', [
                'ip' => $request->ip(),
            ]);

            abort(403);
        }

        $entries = $request->input('entry', []);

        foreach ($entries as $entry) {
            $pageId = $entry['id'] ?? null;
            if (! $pageId) continue;

            $setting = MessengerSetting::withoutGlobalScopes()
                ->where('page_id', $pageId)->where('is_active', 1)->first();

            if (! $setting) continue; // page not connected to any tenant — ignore

            foreach (($entry['messaging'] ?? []) as $event) {
                $this->handleEvent($event, $setting, $api);
            }
        }

        return response()->json(['ok' => true]);
    }

    protected function handleEvent(array $event, MessengerSetting $setting, MessengerApi $api): void
    {
        $psid = $event['sender']['id'] ?? null;
        $text = $event['message']['text'] ?? null;
        $attachments = $event['message']['attachments'] ?? [];

        if (! $psid || (! $text && empty($attachments))) {
            return; // skip read receipts, delivery confirmations, etc.
        }

        // resolve customer's display name once, reuse for later messages
        $existing = MessengerMessage::withoutGlobalScopes()
            ->where('tenant_id', $setting->tenant_id)
            ->where('sender_psid', $psid)
            ->whereNotNull('customer_name')
            ->first();

        $name = $existing?->customer_name;

        if (! $name) {
            try {
                $profile = $api->getProfile($psid, $setting->page_access_token);
                $name = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? '')) ?: null;
            } catch (\Throwable $e) {
                $name = null;
            }
        }

        MessengerMessage::withoutGlobalScopes()->create([
            'tenant_id'      => $setting->tenant_id,
            'sender_psid'    => $psid,
            'customer_name'  => $name,
            'message_text'   => $text,
            'attachment_url' => $attachments[0]['payload']['url'] ?? null,
            'direction'      => 'in',
            'status'         => 'new',
        ]);
    }

    /**
     * Verifies Meta's HMAC-SHA256 signature over the raw request body, using
     * the platform's single Facebook App secret (one app/webhook serves every
     * tenant, per the class docblock above). Fails closed — an unconfigured
     * app_secret rejects every request rather than silently accepting
     * unverifiable ones.
     */
    protected function hasValidSignature(Request $request): bool
    {
        $secret = config('messenger.app_secret');

        if (! $secret) {
            return false;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
