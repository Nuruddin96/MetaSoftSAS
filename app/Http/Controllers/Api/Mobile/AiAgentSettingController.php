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
 * from Tenant\SettingController::aiAgent() (three boolean keys, plus the
 * free-text ai_custom_instructions field added below) — it does not
 * touch, depend on, or duplicate any of that in-progress work.
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
            // Same tenant-level value the web "AI-কে আপনার ব্যবসার বিষয়ে
            // কী কী জানা দরকার?" textarea reads/writes (Tenant\
            // SettingController::aiAgent(), store_settings key
            // ai_custom_instructions) — read here verbatim, not a
            // mobile-only copy. Consumed by AiAgentService::systemPrompt()
            // exactly as before; this controller only adds mobile
            // read/write access to the same value.
            'ai_custom_instructions' => $values['ai_custom_instructions'] ?? '',
        ]);
    }

    /** Mirrors Tenant\SettingController::aiAgent() exactly — same keys, same StoreSetting shape, same 5000-char cap. */
    public function update(Request $request)
    {
        $data = $request->validate([
            'ai_agent_enabled' => 'nullable|boolean',
            'messenger_ai_auto_reply_enabled' => 'nullable|boolean',
            'whatsapp_ai_auto_reply_enabled' => 'nullable|boolean',
            'ai_custom_instructions' => 'nullable|string|max:5000',
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
        StoreSetting::updateOrCreate(
            ['key' => 'ai_custom_instructions'],
            ['value' => trim((string) ($data['ai_custom_instructions'] ?? ''))]
        );

        return response()->json(['message' => 'AI এজেন্ট সেটিংস সেভ হয়েছে।']);
    }
}
