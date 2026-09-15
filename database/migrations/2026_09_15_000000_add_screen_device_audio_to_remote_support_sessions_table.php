<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent Remote Support capabilities (see
 * WebRtcSessionController.dart's own class doc comment): Screen is no
 * longer implicit on every session — `include_screen` records whichever
 * capability a session was FIRST created with, exactly mirroring
 * `include_microphone`/`include_camera`'s existing pattern.
 * `include_device_audio` is the new fourth capability (playback/output
 * audio via AudioPlaybackCaptureConfiguration — see
 * DeviceAudioCaptureHelper.kt).
 *
 * Both are only the INITIAL set a session was created with — capabilities
 * toggled afterward travel over `capability-start`/`capability-stop`/
 * `capability-status` signals instead (see SignalController's extended
 * type whitelist), never a new migration/column per toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_support_sessions', function (Blueprint $table) {
            $table->boolean('include_screen')->default(false)->after('include_camera');
            $table->boolean('include_device_audio')->default(false)->after('include_screen');
        });
    }

    public function down(): void
    {
        Schema::table('remote_support_sessions', function (Blueprint $table) {
            $table->dropColumn(['include_screen', 'include_device_audio']);
        });
    }
};
