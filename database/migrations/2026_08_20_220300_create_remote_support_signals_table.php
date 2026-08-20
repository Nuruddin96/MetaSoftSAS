<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WebRTC signaling transport for Remote Support sessions — SDP offer/
 * answer and ICE candidates, relayed between exactly one Super Admin and
 * one device per session (SignalController never inspects/understands the
 * payload, purely relays it — see docs/webrtc-flow.md).
 *
 * This is deliberately an authenticated REST "post + poll since $id"
 * queue rather than a WebSocket server: MetaSoftSAS has no broadcasting
 * infrastructure installed today (no Reverb/Pusher, confirmed against
 * composer.json), and standing one up is a real infra decision
 * (webrtc-flow.md leaves it explicitly open) outside this task's scope.
 * Signaling messages are small and infrequent (a handful per session
 * negotiation), so short-interval polling (see SignalController::poll())
 * is a real-time-enough transport for negotiation — the actual media
 * (video/audio) never touches this table or Laravel at all; it flows
 * peer-to-peer/TURN-relayed once ICE completes, which is where "real
 * live streaming" actually happens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remote_support_signals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('remote_support_session_id');
            $table->string('sender', 10); // admin|device
            $table->string('type', 20); // offer|answer|ice-candidate|bye
            $table->longText('payload');
            $table->timestamp('created_at')->nullable();

            $table->index(['remote_support_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_support_signals');
    }
};
