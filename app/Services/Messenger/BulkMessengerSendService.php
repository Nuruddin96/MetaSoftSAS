<?php

namespace App\Services\Messenger;

use App\Models\Customer;
use App\Models\FacebookPage;
use App\Models\MessengerMessage;
use App\Models\MessengerSetting;
use App\Models\Order;
use App\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;

/**
 * Bulk "send a Messenger message to N selected customers" — used by both
 * Tenant\CustomerController::bulkMessenger() (web) and
 * Api\Mobile\CustomerController::bulkMessenger() (mobile), same reasoning
 * as UnifiedInboxService being shared by both surfaces: one implementation,
 * not two.
 *
 * Reuses the existing official Messenger integration exactly —
 * MessengerApi::sendMessage()/sendAttachment(), the same Send API calls
 * MessengerInboxController/Api\Mobile\MessengerController already make for
 * a single conversation. No unofficial API, no new Meta integration.
 *
 * A `Customer` row has no direct PSID column (confirmed — see
 * Api\Mobile\CustomerController's own docblock on what IS/isn't modeled
 * here); the only place a customer↔PSID link exists is
 * `orders.messenger_psid` on that customer's own orders (set by
 * MessengerWebhookController::maybeCreatePendingOrder() / resolved during
 * order creation). So a customer who has never messaged the Page (no
 * Messenger-sourced order) genuinely has no PSID to send to — reported as
 * an honest per-recipient failure, never silently skipped or faked as
 * sent.
 *
 * `resolveReplyToken()` duplicates
 * Api\Mobile\MessengerController::resolveReplyToken()'s logic deliberately
 * (documented duplication over refactor risk — same convention that
 * controller's own docblock already uses relative to
 * MessengerInboxController) rather than extracting a shared trait for one
 * more caller.
 */
class BulkMessengerSendService
{
    public function __construct(protected MessengerApi $api)
    {
    }

    /**
     * @param  int[]  $customerIds
     * @return array<int, array{customer_id: int, customer_name: string, status: string, reason: ?string}>
     */
    public function sendToCustomers(int $tenantId, array $customerIds, ?string $message, ?UploadedFile $image): array
    {
        $customers = Customer::where('tenant_id', $tenantId)->whereIn('id', $customerIds)->get()->keyBy('id');

        // Rehost once, reused for every recipient — matches
        // MessengerController::reply()'s "Meta's Send API fetches a URL,
        // never a direct upload" convention.
        $imageUrl = null;
        if ($image) {
            $path = app(ImageOptimizer::class)->storeOptimized($image, 'public', 'messenger/'.$tenantId.'/bulk');
            $imageUrl = asset('storage/'.$path);
        }

        $results = [];

        foreach ($customerIds as $customerId) {
            $customer = $customers->get($customerId);

            if (! $customer) {
                $results[] = ['customer_id' => $customerId, 'customer_name' => null, 'status' => 'failed', 'reason' => 'গ্রাহক পাওয়া যায়নি।'];

                continue;
            }

            $psid = Order::where('tenant_id', $tenantId)
                ->where('customer_id', $customerId)
                ->whereNotNull('messenger_psid')
                ->latest()
                ->value('messenger_psid');

            if (! $psid) {
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->name,
                    'status' => 'failed',
                    'reason' => 'এই গ্রাহকের কোনো Messenger সংযোগ নেই — কখনো পেজে মেসেজ করেননি।',
                ];

                continue;
            }

            $token = $this->resolveReplyToken($tenantId, $psid);

            if ($token === false) {
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->name,
                    'status' => 'failed',
                    'reason' => 'এই কনভারসেশনের Facebook Page বর্তমানে ডিসকানেক্টেড।',
                ];

                continue;
            }

            if (! $token) {
                $results[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->name,
                    'status' => 'failed',
                    'reason' => 'মেসেঞ্জার পেজ কানেক্ট করা নেই।',
                ];

                continue;
            }

            $results[] = $this->sendOne($tenantId, $customer, $psid, $token, $message, $imageUrl);
        }

        return $results;
    }

    protected function sendOne(int $tenantId, Customer $customer, string $psid, string $token, ?string $message, ?string $imageUrl): array
    {
        try {
            if ($imageUrl) {
                $result = $this->api->sendAttachment($psid, $imageUrl, 'image', $token);
            } else {
                $result = $this->api->sendMessage($psid, (string) $message, $token);
            }
        } catch (\Throwable $e) {
            return ['customer_id' => $customer->id, 'customer_name' => $customer->name, 'status' => 'failed', 'reason' => $e->getMessage()];
        }

        // Never report success on Meta's own say-so alone — an error
        // payload (e.g. outside the 24h messaging window, or the recipient
        // blocked the Page) always means failed, regardless of HTTP status.
        if (isset($result['error'])) {
            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'status' => 'failed',
                'reason' => $result['error']['message'] ?? 'Facebook API error',
            ];
        }

        $attrs = [
            'sender_psid' => $psid,
            'mid' => $result['message_id'] ?? null,
            'direction' => 'out',
            'status' => 'contacted',
        ];
        if ($imageUrl) {
            $attrs['attachment_url'] = $imageUrl;
            if (MessengerMessage::attachmentColumnsReady()) {
                $attrs['attachment_type'] = 'image';
            }
        } else {
            $attrs['message_text'] = $message;
        }
        if (MessengerMessage::sentByColumnReady()) {
            $attrs['sent_by'] = 'human';
        }
        MessengerMessage::create($attrs);

        return ['customer_id' => $customer->id, 'customer_name' => $customer->name, 'status' => 'sent', 'reason' => null];
    }

    /** Identical to Api\Mobile\MessengerController::resolveReplyToken() — see this class's own docblock for why. */
    protected function resolveReplyToken(int $tenantId, string $psid): string|false|null
    {
        $facebookPageId = FacebookPage::tablesReady()
            ? MessengerMessage::where('sender_psid', $psid)
                ->whereNotNull('facebook_page_id')
                ->orderByDesc('id')
                ->value('facebook_page_id')
            : null;

        if ($facebookPageId) {
            $page = FacebookPage::where('id', $facebookPageId)
                ->where('is_active', 1)
                ->where('status', 'active')
                ->first();

            return $page ? $page->page_access_token : false;
        }

        return optional(MessengerSetting::where('tenant_id', $tenantId)->where('is_active', 1)->first())->page_access_token;
    }
}
