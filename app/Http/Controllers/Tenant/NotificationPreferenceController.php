<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Mobile-friendly on/off list for each push category (mobile audit,
 * Phase 15/Part F) — deliberately does not expose Web Push/VAPID
 * terminology anywhere in the view; a tenant only ever sees plain
 * business categories with a checkbox each. 'security' is never listed —
 * NotificationPreferenceService keeps it always-on regardless of any
 * stored preference row.
 */
class NotificationPreferenceController extends Controller
{
    public function __construct(private NotificationPreferenceService $preferences) {}

    public function edit()
    {
        $user = Auth::guard('tenant')->user();

        return view('tenant.notifications.preferences', [
            'categories' => $this->preferences->forUser($user),
        ]);
    }

    public function update(Request $request)
    {
        $user = Auth::guard('tenant')->user();
        $enabled = $request->input('categories', []);

        foreach (NotificationPreferenceService::CATEGORIES as $category => $meta) {
            $this->preferences->setEnabled($user, $category, in_array($category, $enabled, true));
        }

        return back()->with('success', 'নোটিফিকেশন সেটিংস সেভ হয়েছে।');
    }
}
