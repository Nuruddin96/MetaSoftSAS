<?php

namespace App\Http\Controllers\BrandOwner;

use App\Http\Controllers\Controller;
use App\Models\PlatformNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $owner = $request->user('brand_owner');

        return view('brand-owner.notifications', [
            'notifications' => PlatformNotification::forOwner($owner->id)->latest()->paginate(20),
        ]);
    }

    public function markAllRead(Request $request)
    {
        PlatformNotification::forOwner($request->user('brand_owner')->id)->unread()->update(['read_at' => now()]);

        return back();
    }
}
