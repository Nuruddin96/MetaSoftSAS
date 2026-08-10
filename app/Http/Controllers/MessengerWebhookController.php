<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Models\Order;
use App\Services\Messenger\CustomerInfoExtractor;
use App\Services\Messenger\MessengerApi;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ONE webhook URL handles Messenger events for every tenant.
 * Meta sends the Facebook Page ID in each event; we look up which
 * tenant owns that page and file the message under them.
 */
class MessengerWebhookController extends Controller
{
    /** Placeholder customer name used until a real name is extracted from the conversation. */
    protected const DEFAULT_CUSTOMER_NAME = 'Messenger Customer';

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
        // Meta sets message.is_echo=true when a message was sent FROM the
        // Page — by us via the Send API (MessengerInboxController::reply())
        // or by a human agent in Meta Business Suite/Inbox. On an echo, Meta
        // reverses sender/recipient versus a normal inbound event: sender.id
        // becomes the Page itself and recipient.id is the customer. This
        // table's sender_psid column always means "the customer's PSID"
        // (every query elsewhere is keyed on it that way), so the PSID must
        // be read from the field that actually holds the customer on each
        // event shape rather than always trusting event.sender.id.
        $isEcho = (bool) ($event['message']['is_echo'] ?? false);

        $psid = $isEcho
            ? ($event['recipient']['id'] ?? null)
            : ($event['sender']['id'] ?? null);

        $mid = $event['message']['mid'] ?? null;
        $text = $event['message']['text'] ?? null;
        $attachments = $event['message']['attachments'] ?? [];

        if (! $psid || (! $text && empty($attachments))) {
            return; // skip read receipts, delivery confirmations, etc.
        }

        // Meta's webhook delivery is at-least-once — the same event can be
        // POSTed again after a timeout or non-2xx response. mid is Meta's own
        // unique ID for this message; if we've already recorded it, this is a
        // retry of something we already processed, not a new message. This
        // is also what stops an echo from duplicating a reply this app
        // already sent: MessengerInboxController::reply() records its own
        // row with Meta's returned message_id as mid at send time, so the
        // matching echo event that follows finds it already here.
        if ($mid && MessengerMessage::withoutGlobalScopes()->where('mid', $mid)->exists()) {
            return;
        }

        $name = null;

        if (! $isEcho) {
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
        }

        // Only the first attachment is used, matching the pre-existing
        // single-attachment-per-row design — Meta's rare multi-attachment
        // messages still only keep the first one.
        $attachmentType = $attachments[0]['type'] ?? null;
        $originalAttachmentUrl = $attachments[0]['payload']['url'] ?? null;
        $attachmentUrl = null;
        $attachmentName = null;

        if ($originalAttachmentUrl) {
            // Meta's attachment URL is time-limited/signed — re-host it to
            // our own public storage now so it's still viewable whenever a
            // staff member opens this conversation later, not just while
            // Meta's link happens to still be valid. rehostAttachment()
            // never throws; on any failure it returns null and we fall
            // back to Meta's original URL (works until it expires, better
            // than losing the attachment entirely).
            $attachmentUrl = $this->rehostAttachment($originalAttachmentUrl, $owner->tenant_id, $mid) ?? $originalAttachmentUrl;

            if ($attachmentType === 'file') {
                $attachmentName = basename((string) parse_url($originalAttachmentUrl, PHP_URL_PATH)) ?: null;
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
            'attachment_url' => $attachmentUrl,
            'attachment_type' => $attachmentType,
            'attachment_name' => $attachmentName,
            'direction' => $isEcho ? 'out' : 'in',
            'status' => $isEcho ? 'contacted' : 'new',
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

        if ($isEcho) {
            // Same "conversation now contacted" transition
            // MessengerInboxController::reply() already applies for panel
            // replies — a Page-side reply sent from Meta Business Suite
            // should clear the unread/"new" badge for this conversation too.
            MessengerMessage::withoutGlobalScopes()
                ->where('tenant_id', $owner->tenant_id)
                ->where('sender_psid', $psid)
                ->where('status', 'new')
                ->update(['status' => 'contacted']);

            return;
        }

        try {
            $this->maybeCreatePendingOrder($owner->tenant_id, $psid);
        } catch (\Throwable $e) {
            // Order auto-creation is a convenience on top of message storage
            // — it must never take the webhook down. The message above is
            // already safely recorded regardless of what happens here.
            Log::warning('Messenger webhook: pending-order auto-creation failed.', [
                'tenant_id' => $owner->tenant_id,
                'psid' => $psid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Turns a Messenger conversation into a Pending Order the moment a
     * valid BD phone number is found in it — reusing the EXISTING Order
     * model/table (no separate "pending order" module). The phone number
     * is the trigger: pure purchase-intent text with no phone never creates
     * an order.
     *
     * Runs entirely with withoutGlobalScopes() + explicit tenant_id, same
     * as the rest of this controller — app('currentTenant') is never bound
     * on this central, non-tenant-prefixed webhook route, so the normal
     * BelongsToTenant auto-scoping/auto-fill is a no-op here and would
     * otherwise search/create across every tenant.
     *
     * Dedup key is (tenant_id, messenger_psid, source='messenger',
     * status='pending') — NOT phone alone, so a repeat customer placing a
     * genuinely new order later (after the first one left 'pending') gets
     * a fresh Order rather than reusing an old one. lockForUpdate() inside
     * a transaction guards the check-then-create race for near-simultaneous
     * webhook deliveries, the same "race-safe enough for shared hosting"
     * posture Order::booted() already uses for order numbers.
     */
    protected function maybeCreatePendingOrder(int $tenantId, string $psid): void
    {
        $extractor = app(CustomerInfoExtractor::class);

        $messages = MessengerMessage::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sender_psid', $psid)
            ->where('direction', 'in')
            ->orderBy('id')
            ->get();

        $info = $extractor->fromConversation($messages);

        if (! $info['phone']) {
            return;
        }

        DB::transaction(function () use ($tenantId, $psid, $info) {
            $customer = Customer::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'phone' => $info['phone']],
                [
                    'name' => $info['name'] ?: self::DEFAULT_CUSTOMER_NAME,
                    'address' => $info['address'],
                    'division_id' => $info['division_id'],
                    'district_id' => $info['district_id'],
                    'upazila_id' => $info['upazila_id'],
                ]
            );

            if (! $customer->wasRecentlyCreated) {
                $fills = array_filter([
                    'name' => ($customer->name === self::DEFAULT_CUSTOMER_NAME && $info['name']) ? $info['name'] : null,
                    'address' => ! $customer->address ? $info['address'] : null,
                    'division_id' => ! $customer->district_id ? $info['division_id'] : null,
                    'district_id' => ! $customer->district_id ? $info['district_id'] : null,
                    'upazila_id' => ! $customer->district_id ? $info['upazila_id'] : null,
                ]);

                if ($fills) {
                    $customer->update($fills);
                }
            }

            $order = Order::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('messenger_psid', $psid)
                ->where('source', 'messenger')
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if ($order) {
                $fills = array_filter([
                    'customer_name' => ($order->customer_name === self::DEFAULT_CUSTOMER_NAME && $info['name']) ? $info['name'] : null,
                    'customer_address' => ! $order->customer_address ? $info['address'] : null,
                    'division_id' => ! $order->district_id ? $info['division_id'] : null,
                    'district_id' => ! $order->district_id ? $info['district_id'] : null,
                    'upazila_id' => ! $order->district_id ? $info['upazila_id'] : null,
                ]);

                if ($fills) {
                    $order->update($fills);
                }

                return;
            }

            Order::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'source' => 'messenger',
                'channel' => 'facebook',
                'messenger_psid' => $psid,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_address' => $customer->address,
                'division_id' => $customer->division_id,
                'district_id' => $customer->district_id,
                'upazila_id' => $customer->upazila_id,
                'subtotal' => 0,
                'total' => 0,
                'status' => 'pending',
                'fb_event_id' => (string) Str::uuid(),
            ]);
        });
    }

    /**
     * Downloads a Meta CDN attachment URL and stores it on the public disk
     * under messenger/{tenant_id}/, same disk/convention already used for
     * tenant logos and product images (WebsiteController::brand(),
     * ProductController). Returns the durable public URL, or null on any
     * failure (network error, non-2xx, oversized body, storage error) —
     * callers fall back to Meta's original URL rather than treat this as
     * fatal, since a webhook event must never be lost over a re-hosting
     * hiccup.
     */
    protected function rehostAttachment(string $metaUrl, int $tenantId, ?string $mid): ?string
    {
        try {
            $response = Http::timeout(15)->get($metaUrl);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        // Sanity ceiling — normal Messenger attachments are well under
        // this; matches the upload_max_filesize/post_max_size headroom
        // already configured for the app (public/.user.ini).
        if (strlen($body) > 20 * 1024 * 1024) {
            return null;
        }

        $ext = $this->guessExtension($response->header('Content-Type', ''));
        $filename = ($mid ? preg_replace('/[^A-Za-z0-9._-]/', '', $mid) : (string) Str::uuid()).'.'.$ext;
        $path = "messenger/{$tenantId}/{$filename}";

        try {
            Storage::disk('public')->put($path, $body);
        } catch (\Throwable $e) {
            return null;
        }

        return asset('storage/'.$path);
    }

    protected function guessExtension(string $contentType): string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        $map = [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/ogg' => 'ogg',
            'audio/x-caf' => 'caf', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav',
            'video/mp4' => 'mp4', 'video/quicktime' => 'mov',
            'application/pdf' => 'pdf',
        ];

        if (isset($map[$contentType])) {
            return $map[$contentType];
        }

        $sub = preg_replace('/[^a-z0-9]/', '', explode('/', $contentType)[1] ?? '');

        return $sub ?: 'bin';
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
