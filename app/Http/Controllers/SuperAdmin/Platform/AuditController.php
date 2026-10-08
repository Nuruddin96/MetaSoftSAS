<?php

namespace App\Http\Controllers\SuperAdmin\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformAuditLog;
use App\Models\PlatformNotification;
use Illuminate\Http\Request;

/** Read-only platform audit trail + the Super Admin side of platform notifications. */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        return view('super.platform.audit', [
            'logs' => PlatformAuditLog::query()
                ->when($request->query('subject'), fn ($q, $s) => $q->where('subject_type', $s))
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
                ->latest('id')->paginate(50)->withQueryString(),
            'subjects' => PlatformAuditLog::distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }

    public function notifications()
    {
        return view('super.platform.notifications', [
            'notifications' => PlatformNotification::forAdmins()->latest()->paginate(30),
        ]);
    }

    public function markRead()
    {
        PlatformNotification::forAdmins()->unread()->update(['read_at' => now()]);

        return back();
    }
}
