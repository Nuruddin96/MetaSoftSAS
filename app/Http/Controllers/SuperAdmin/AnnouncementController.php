<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAnnouncement;
use Illuminate\Http\Request;

/**
 * "Tenant Announcement" — a single, GLOBAL message shown on every
 * tenant's Dashboard (see App\Models\PlatformAnnouncement's docblock).
 * Tenants can never edit it. Always operates on the one row (id=1) —
 * there is only ever one current announcement, not a history of them.
 */
class AnnouncementController extends Controller
{
    public function index()
    {
        return view('super.announcement', [
            'announcement' => PlatformAnnouncement::tablesReady() ? PlatformAnnouncement::first() : null,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string|max:500',
        ]);

        PlatformAnnouncement::query()->updateOrCreate(['id' => 1], ['message' => $data['message']]);

        return back()->with('success', 'ঘোষণা সেভ হয়েছে।');
    }

    public function destroy()
    {
        PlatformAnnouncement::query()->delete();

        return back()->with('success', 'ঘোষণা মুছে ফেলা হয়েছে।');
    }
}
