<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\StoreSetting;
use Illuminate\Http\Request;

/**
 * Mobile parity for the master AI switch + per-channel auto-reply toggles
 * — kept as its own controller rather than folded into the general
 * Api\Mobile\SettingController, whose own docblock explicitly defers
 * these fields to avoid mixing into the separate, in-progress WhatsApp/
 * AI-agent work elsewhere in this codebase. This controller only reuses
 * the already-stable, already-shipped `store_settings` read/write shape
 * from Tenant\SettingController::aiAgent() (three boolean keys) — it does
 * not touch, depend on, or duplicate any of that in-progress work.
 *
 * Fixes the mobile app's toggles previously being local-only (`setState`,
 * no persistence at all — see SettingsScreen's prior docblock).
 */
class AiAgentSettingController extends Controller
{
    public function index()
    {
        $values = StoreSetting::pluck('value', 'key');

        return response()->json([
            'ai_agent_enabled' => ($values['ai_agent_enabled'] ?? '0') === '1',
            'messenger_ai_auto_reply_enabled' => ($values['messenger_ai_auto_reply_enabled'] ?? '0') === '1',
            'whatsapp_ai_auto_reply_enabled' => ($values['whatsapp_ai_auto_reply_enabled'] ?? '0') === '1',
        ]);
    }

    /** Mirrors Tenant\SettingController::aiAgent() exactly — same three keys, same StoreSetting shape. */
    public function update(Request $request)
    {
        $request->validate([
            'ai_agent_enabled' => 'nullable|boolean',
            'messenger_ai_auto_reply_enabled' => 'nullable|boolean',
            'whatsapp_ai_auto_reply_enabled' => 'nullable|boolean',
        ]);

        StoreSetting::updateOrCreate(
            ['key' => 'ai_agent_enabled'],
            ['value' => $request->boolean('ai_agent_enabled') ? '1' : '0']
        );
        StoreSetting::updateOrCreate(
            ['key' => 'messenger_ai_auto_reply_enabled'],
            ['value' => $request->boolean('messenger_ai_auto_reply_enabled') ? '1' : '0']
        );
        StoreSetting::updateOrCreate(
            ['key' => 'whatsapp_ai_auto_reply_enabled'],
            ['value' => $request->boolean('whatsapp_ai_auto_reply_enabled') ? '1' : '0']
        );

        return response()->json(['message' => 'AI এজেন্ট সেটিংস সেভ হয়েছে।']);
    }
}
