<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAiAgentMessage;
use App\Models\AiAgentMessageJob;
use App\Models\Customer;
use App\Models\FacebookPage;
use App\Models\MessengerCustomer;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Models\Order;
use App\Models\StoreSetting;
use App\Models\Tenant;
use App\Services\AI\AiCreditService;
use App\Services\AI\AiHandoffService;
use App\Services\Messenger\CustomerInfoExtractor;
use App\Services\Messenger\FacebookMessengerCustomerService;
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
    /**
     * Placeholder customer name used until a real name is extracted from the
     * conversation. Public (not protected) because the Inbox list-building
     * code (MessengerInboxController::applyResolvedIdentities(),
     * UnifiedInboxService::fetchMessengerCandidates()) needs to recognize
     * and exclude this exact literal when it appears as
     * messenger_messages.customer_name — that placeholder must never be
     * mistaken for a genuinely resolved identity when falling through to
     * the Order->Customer display-name lookup.
     */
    public const DEFAULT_CUSTOMER_NAME = 'Messenger Customer';

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
    public function receive(Request $request, MessengerApi $api, FacebookMessengerCustomerService $customerService)
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
                $this->handleEvent($event, $owner, $api, $customerService);
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

    protected function handleEvent(array $event, object $owner, MessengerApi $api, FacebookMessengerCustomerService $customerService): void
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
            if (MessengerCustomer::tablesReady()) {
                // Canonical identity path (database/sql/chunk28.sql) — one
                // Graph call fetches name AND profile photo together and
                // persists both to messenger_customers, gated by
                // needsRefresh() so ordinary message traffic doesn't call
                // Graph on every single message once an identity is known.
                // syncCustomerProfile() never throws and never erases a
                // previously-good identity on failure (see its docblock),
                // so a Graph outage here degrades to "keep whatever we
                // already had," never to a broken webhook.
                $identity = MessengerCustomer::withoutGlobalScopes()
                    ->where('tenant_id', $owner->tenant_id)->where('psid', $psid)->first();

                if ($customerService->needsRefresh($identity)) {
                    $identity = $customerService->syncCustomerProfile(
                        $owner->tenant_id, $owner->facebook_page_id, $psid, $owner->page_access_token
                    ) ?? $identity;
                }

                $name = $identity?->display_name ?: MessengerMessage::resolvedNameFor($owner->tenant_id, $psid);
            } else {
                // chunk28.sql not imported on this environment yet — same
                // inline getProfile()-based resolution this controller used
                // before the MessengerCustomer identity system existed, kept
                // so an un-migrated environment still resolves a name (just
                // without the richer, persisted identity record or photo).
                $name = MessengerMessage::resolvedNameFor($owner->tenant_id, $psid);

                if (! $name) {
                    try {
                        $response = $api->getProfile($psid, $owner->page_access_token);
                        $profile = $response->successful() ? $response->json() : null;
                        $name = $profile ? (trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? '')) ?: null) : null;

                        if ($profile && ! $name) {
                            Log::info('Messenger webhook: profile fetch returned no usable name.', [
                                'tenant_id' => $owner->tenant_id,
                                'psid' => $psid,
                                'response_had_first_name' => isset($profile['first_name']),
                                'response_had_last_name' => isset($profile['last_name']),
                            ]);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Messenger webhook: profile fetch failed while resolving customer name.', [
                            'tenant_id' => $owner->tenant_id,
                            'psid' => $psid,
                            'exception' => get_class($e),
                        ]);
                        $name = null;
                    }
                }
            }

            // Neutral, final fallback — messenger_messages.customer_name
            // should carry a real value rather than NULL whenever possible
            // (MessengerCustomer name -> first+last -> a prior resolved
            // name elsewhere in this conversation, all already handled
            // above), so a customer we genuinely can't identify at all
            // still reads as "Messenger Customer" rather than leaving the
            // "অজানা কাস্টমার" placeholder to the view layer alone.
            $name = $name ?: self::DEFAULT_CUSTOMER_NAME;
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

        // facebook_page_id and attachment_type/attachment_name are only
        // ever included in the INSERT when their columns are confirmed to
        // exist — an Eloquent create() builds its column list from this
        // array's keys regardless of whether the schema actually has that
        // column, so simply passing null here when a column is missing
        // would still throw a SQL "unknown column" error, not silently
        // no-op it. Without this guard, EVERY incoming message (not just
        // ones with attachments) would fail to save until
        // database/sql/chunk25.sql is imported.
        $attributes = [
            'tenant_id' => $owner->tenant_id,
            'sender_psid' => $psid,
            'mid' => $mid,
            'customer_name' => $name,
            'message_text' => $text,
            'attachment_url' => $attachmentUrl,
            'direction' => $isEcho ? 'out' : 'in',
            'status' => $isEcho ? 'contacted' : 'new',
        ];

        if (MessengerMessage::attachmentColumnsReady()) {
            $attributes['attachment_type'] = $attachmentType;
            $attributes['attachment_name'] = $attachmentName;
        }

        if (FacebookPage::tablesReady()) {
            $attributes['facebook_page_id'] = $owner->facebook_page_id;
        }

        $message = null;

        try {
            $message = MessengerMessage::withoutGlobalScopes()->create($attributes);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            // Race: a concurrent retry of this same mid won the insert first
            // (between the exists() check above and this create()) — the
            // message is already recorded, nothing else to do. $message
            // stays null: that concurrent request already owns any AI
            // Agent dispatch for it, not this one.
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

        // Phase 9 — an image with no caption is now dispatchable too (real
        // vision understanding, see AiAgentService/ProcessAiAgentMessage::
        // resolveImageUrl()), not just plain text. Phase 10 — same for a
        // voice message (ProcessAiAgentMessage::transcribeAndPersist()
        // converts it to text before anything else runs). Every other
        // attachment type (video/file) still needs real caption text to
        // dispatch at all — those stay text-only placeholders in history.
        $dispatchable = $text || ($attachmentUrl && in_array($attachmentType, ['image', 'audio'], true));

        if ($message && $dispatchable) {
            try {
                $this->maybeDispatchAiAgent($owner->tenant_id, $message);
            } catch (\Throwable $e) {
                // Same "additive convenience, must never take the webhook
                // down" posture as pending-order auto-creation above — the
                // message is already safely recorded regardless.
                Log::warning('Messenger webhook: AI agent dispatch failed.', [
                    'tenant_id' => $owner->tenant_id,
                    'psid' => $psid,
                    'exception' => get_class($e),
                ]);
            }
        }
    }

    /**
     * AI Customer Support Agent (Messenger channel) — purely additive on
     * top of the message-storage flow above, never replacing any of it.
     * Only ever reached for genuine inbound (non-echo) customer text
     * messages: $isEcho already took the early-return path above for
     * anything sent from the Page itself, and the caller only invokes this
     * when $text is non-empty. That is the entire loop-prevention
     * mechanism this needs — the AI's own outgoing send (see
     * ProcessAiAgentMessage) is always recorded with direction='out', and
     * the matching echo event Meta sends back for it always takes the
     * $isEcho path above, never this one.
     *
     * Gated by THREE independent checks, all of which must pass:
     *  - ai_agent_enabled: the tenant's master AI switch (future channels/
     *    tools beyond Messenger will share this same switch).
     *  - messenger_ai_auto_reply_enabled: Messenger-specific. A tenant can
     *    have the master switch on (e.g. for a future in-panel AI chat)
     *    while leaving Messenger auto-reply off — inbound messages still
     *    arrive and sit in the inbox exactly as before, just without an
     *    automatic AI reply.
     *  - AiCreditService::hasCredit(): checked here (not just inside the
     *    job) purely as an optimization — skips queuing a job, and
     *    writing the 'pending' tracking row for it, that would only
     *    immediately no-op. The job re-checks all three again itself
     *    (defense in depth against a race between this check and a worker
     *    picking the job up) — see ProcessAiAgentMessage::process().
     *
     * Everything here is synchronous but cheap: two settings lookups, one
     * balance read, one insert. All AI processing — building context,
     * calling OpenAI, sending the reply — happens inside the queued
     * ProcessAiAgentMessage job, never in this webhook request. The
     * 'pending' row this writes is what that job atomically claims before
     * doing anything else; see AiAgentMessageJob's docblock for the full
     * duplicate-reply guard this is part of.
     */
    protected function maybeDispatchAiAgent(int $tenantId, MessengerMessage $message): void
    {
        if (! AiAgentMessageJob::tablesReady()) {
            return; // database/sql/chunk30.sql not imported on this environment yet
        }

        if (! $this->isAiAgentEnabled($tenantId) || ! $this->isMessengerAutoReplyEnabled($tenantId)) {
            return;
        }

        // Phase 14 — purely an optimization, same reasoning as the credit
        // check below: skips queuing a job that would only immediately
        // no-op. The job re-checks this itself too — see
        // ProcessAiAgentMessage::process() and Tenant::isAiPaused()'s
        // docblock.
        if (Tenant::aiPauseColumnsReady() && Tenant::withoutGlobalScopes()->where('id', $tenantId)->value('ai_paused_at') !== null) {
            return;
        }

        if (! app(AiCreditService::class)->hasCredit($tenantId)) {
            return;
        }

        // Phase 13 — purely an optimization, same reasoning as the credit
        // check above: skips queuing a job (and writing its 'pending'
        // tracking row) that would only immediately no-op. The job
        // re-checks this itself too — see ProcessAiAgentMessage::process().
        if (app(AiHandoffService::class)->isActive($tenantId, 'messenger', $message->sender_psid)) {
            return;
        }

        // Same "purely an optimization, the job re-checks this itself
        // too" reasoning as the two checks above — a human/admin reply
        // within the last HUMAN_PAUSE_MINUTES minutes must not queue a
        // job that would only immediately no-op. See
        // MessengerMessage::isHumanPaused()'s docblock.
        if (MessengerMessage::isHumanPaused($tenantId, $message->sender_psid)) {
            return;
        }

        AiAgentMessageJob::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'messenger_message_id' => $message->id,
            'status' => 'pending',
        ]);

        ProcessAiAgentMessage::dispatch($tenantId, $message->id);
    }

    /**
     * The AI Agent master ON/OFF toggle reuses the existing generic
     * store_settings table (key='ai_agent_enabled') rather than a
     * dedicated settings table — see database/sql/chunk30.sql and
     * Tenant\SettingController::aiAgent() for the write side. No row for a
     * tenant means disabled; this is what keeps every existing tenant
     * silent by default after this feature deploys.
     */
    protected function isAiAgentEnabled(int $tenantId): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'ai_agent_enabled')
            ->value('value') === '1';
    }

    /**
     * Messenger-channel-specific toggle, independent of the master switch
     * above — see maybeDispatchAiAgent()'s docblock. Same store_settings/
     * "no row = disabled" shape as isAiAgentEnabled().
     */
    protected function isMessengerAutoReplyEnabled(int $tenantId): bool
    {
        return StoreSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', 'messenger_ai_auto_reply_enabled')
            ->value('value') === '1';
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

        // CustomerInfoExtractor only finds a name when the customer typed
        // one explicitly (e.g. "Name: Apo") — an explicit typed name is
        // treated as a deliberate correction and always wins when present
        // (see AutoPendingOrderTest::test_explicitly_typed_name_still_wins_
        // over_the_facebook_profile_name). Absent that, the customer's
        // Facebook identity is the default: prefer the canonical
        // messenger_customers record (name + photo, kept fresh by
        // handleEvent()'s syncCustomerProfile() call) and fall back to the
        // older per-message resolvedNameFor() cache only when that table
        // isn't imported yet or has no record for this psid.
        $identity = MessengerCustomer::tablesReady()
            ? MessengerCustomer::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('psid', $psid)->first()
            : null;

        $resolvedName = $info['name'] ?: ($identity?->display_name ?: MessengerMessage::resolvedNameFor($tenantId, $psid));

        DB::transaction(function () use ($tenantId, $psid, $info, $resolvedName) {
            $customer = Customer::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'phone' => $info['phone']],
                [
                    'name' => $resolvedName ?: self::DEFAULT_CUSTOMER_NAME,
                    'address' => $info['address'],
                    'division_id' => $info['division_id'],
                    'district_id' => $info['district_id'],
                    'upazila_id' => $info['upazila_id'],
                ]
            );

            if (! $customer->wasRecentlyCreated) {
                $fills = array_filter([
                    'name' => ($customer->name === self::DEFAULT_CUSTOMER_NAME && $resolvedName) ? $resolvedName : null,
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
                    'customer_name' => ($order->customer_name === self::DEFAULT_CUSTOMER_NAME && $resolvedName) ? $resolvedName : null,
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
