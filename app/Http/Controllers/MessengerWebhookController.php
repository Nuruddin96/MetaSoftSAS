<?php

namespace App\Http\Controllers;

use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Services\Messenger\MessengerApi;
use Illuminate\Database\QueryException;
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
            if (! $pageId) {
                continue;
            }

            $owner = $this->resolvePageOwner($pageId);

            if (! $owner) {
                continue;
            } // page not connected to any tenant — ignore

            foreach (($entry['messaging'] ?? []) as $event) {
                $this->handleEvent($event, $owner, $api);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Resolves which tenant owns an incoming page_id — checks OAuth-
     * connected Pages (facebook_pages) first, falls back to the legacy
     * manually-pasted messenger_settings row. Purely additive: neither
     * table's own data or the caller's signature/dedup logic changes,
     * this only decides which row supplies tenant_id/page_access_token.
     *
     * The facebook_pages query is skipped entirely (not try/caught) when
     * those tables don't exist yet — database/sql/chunk23.sql is additive
     * and may not be imported on every environment yet. Skipping via an
     * explicit existence check, rather than swallowing exceptions, means a
     * genuine, unrelated DB error still surfaces normally instead of being
     * silently absorbed here.
     */
    protected function resolvePageOwner(string $pageId): ?object
    {
        $page = FacebookPage::tablesReady()
            ? FacebookPage::withoutGlobalScopes()->where('page_id', $pageId)->where('is_active', 1)->first()
            : null;

        if ($page) {
            return (object) [
                'tenant_id' => $page->tenant_id,
                'page_access_token' => $page->page_access_token,
                'facebook_page_id' => $page->id,
            ];
        }

        $setting = MessengerSetting::withoutGlobalScopes()
            ->where('page_id', $pageId)->where('is_active', 1)->first();

        if ($setting) {
            return (object) [
                'tenant_id' => $setting->tenant_id,
                'page_access_token' => $setting->page_access_token,
                'facebook_page_id' => null,
            ];
        }

        return null;
    }

    protected function handleEvent(array $event, object $owner, MessengerApi $api): void
    {
        $psid = $event['sender']['id'] ?? null;
        $mid = $event['message']['mid'] ?? null;
        $text = $event['message']['text'] ?? null;
        $attachments = $event['message']['attachments'] ?? [];

        if (! $psid || (! $text && empty($attachments))) {
            return; // skip read receipts, delivery confirmations, etc.
        }

        // Meta's webhook delivery is at-least-once — the same event can be
        // POSTed again after a timeout or non-2xx response. mid is Meta's own
        // unique ID for this message; if we've already recorded it, this is a
        // retry of something we already processed, not a new message.
        if ($mid && MessengerMessage::withoutGlobalScopes()->where('mid', $mid)->exists()) {
            return;
        }

        // resolve customer's display name once, reuse for later messages
        $existing = MessengerMessage::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->where('sender_psid', $psid)
            ->whereNotNull('customer_name')
            ->first();

        $name = $existing?->customer_name;

        if (! $name) {
            try {
                $profile = $api->getProfile($psid, $owner->page_access_token);
                $name = trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? '')) ?: null;
            } catch (\Throwable $e) {
                $name = null;
            }
        }

        // facebook_page_id is only ever included in the INSERT when the
        // column is confirmed to exist (FacebookPage::tablesReady()) — an
        // Eloquent create() builds its column list from this array's keys
        // regardless of whether the schema actually has that column, so
        // simply passing null here when the column is missing would still
        // throw a SQL "unknown column" error, not silently no-op it.
        $attributes = [
            'tenant_id' => $owner->tenant_id,
            'sender_psid' => $psid,
            'mid' => $mid,
            'customer_name' => $name,
            'message_text' => $text,
            'attachment_url' => $attachments[0]['payload']['url'] ?? null,
            'direction' => 'in',
            'status' => 'new',
        ];

        if (FacebookPage::tablesReady()) {
            $attributes['facebook_page_id'] = $owner->facebook_page_id;
        }

        try {
            MessengerMessage::withoutGlobalScopes()->create($attributes);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Race: a concurrent retry of this same mid won the insert first
            // (between the exists() check above and this create()) — the
            // message is already recorded, nothing else to do.
        }
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

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
