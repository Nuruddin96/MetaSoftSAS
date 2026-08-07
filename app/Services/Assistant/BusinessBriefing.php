<?php

namespace App\Services\Assistant;

use App\Models\AffiliateCommission;
use App\Models\Client;
use App\Models\Order;
use App\Models\ServiceLead;
use App\Models\SourceOrder;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;

/**
 * Pulls a live snapshot of the whole platform so the AI assistant
 * always answers with real, current numbers — not guesses.
 */
class BusinessBriefing
{
    public function generate(): string
    {
        $lines = [];

        // Tenants
        $lines[] = "=== টেনেন্ট (SaaS দোকান) ===";
        $lines[] = "মোট: " . Tenant::count();
        $lines[] = "অ্যাক্টিভ: " . Tenant::where('status', 'active')->count();
        $lines[] = "ট্রায়ালে: " . Tenant::where('status', 'trial')->count();
        $lines[] = "মেয়াদ শেষ/সাসপেন্ড: " . Tenant::whereIn('status', ['expired', 'suspended'])->count();
        $lines[] = "আজকে নতুন সাইনআপ: " . Tenant::whereDate('created_at', today())->count();

        $pendingDomains = Tenant::where('custom_domain_request_status', 'pending')->pluck('store_name');
        if ($pendingDomains->isNotEmpty()) {
            $lines[] = "কাস্টম ডোমেইন রিকোয়েস্ট পেন্ডিং: " . $pendingDomains->implode(', ');
        }

        // Revenue
        $todayRevenue = (float) SubscriptionPayment::where('status', 'completed')
            ->whereDate('paid_at', today())->sum('amount');
        $monthRevenue = (float) SubscriptionPayment::where('status', 'completed')
            ->where('paid_at', '>=', now()->startOfMonth())->sum('amount');
        $lines[] = "\n=== সাবস্ক্রিপশন আয় ===";
        $lines[] = "আজকে: " . number_format($todayRevenue) . '৳';
        $lines[] = "এ মাসে: " . number_format($monthRevenue) . '৳';

        // Agency clients
        $lines[] = "\n=== এজেন্সি ক্লায়েন্ট (সার্ভিস প্যাকেজ) ===";
        $lines[] = "মোট ক্লায়েন্ট: " . Client::count();
        $lines[] = "মোট বাকি: " . number_format(Client::sum('due_amount')) . '৳';
        $lines[] = "মোট অগ্রিম: " . number_format(Client::sum('advance_amount')) . '৳';

        // Affiliates
        $pendingCommission = (float) AffiliateCommission::where('status', 'pending')->sum('amount');
        $lines[] = "\n=== অ্যাফিলিয়েট ===";
        $lines[] = "অপেক্ষমান কমিশন (পরিশোধ বাকি): " . number_format($pendingCommission) . '৳';

        // New service leads
        $newLeads = ServiceLead::where('status', 'new')->count();
        if ($newLeads > 0) {
            $lines[] = "নতুন সার্ভিস লিড (এখনো কল করা হয়নি): $newLeads টি";
        }

        // Source orders
        $pendingSource = SourceOrder::where('status', 'pending')->count();
        if ($pendingSource > 0) {
            $lines[] = "\nপ্রোডাক্ট সোর্সিং অর্ডার পেন্ডিং: $pendingSource টি";
        }

        // Total marketplace orders across all tenants (today)
        $todayOrders = Order::withoutGlobalScopes()->whereDate('created_at', today())->count();
        $lines[] = "\n=== প্ল্যাটফর্মে মোট ===";
        $lines[] = "আজকে সব টেনেন্ট মিলিয়ে অর্ডার: $todayOrders টি";

        return implode("\n", $lines);
    }
}
