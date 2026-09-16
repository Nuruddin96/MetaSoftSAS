<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AppUpdateConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Centralized Android APK update config — Super Admin only, a single
 * GLOBAL row (see AppUpdateConfig's docblock), same "always id=1"
 * singleton pattern as AnnouncementController. Read by the public
 * Api\Mobile\AppUpdateController for the Flutter app's update check.
 */
class AppUpdateController extends Controller
{
    public function index()
    {
        return view('super.app-update', [
            'config' => AppUpdateConfig::current(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'latest_version' => 'required|string|max:20',
            'latest_build' => 'required|integer|min:1',
            'minimum_supported_version' => 'required|string|max:20',
            'minimum_supported_build' => 'required|integer|min:1',
            'release_notes' => 'nullable|string|max:2000',
            // Real APK files run into the hundreds of MB — 300MB ceiling
            // matches what a production PHP upload_max_filesize/post_max_size
            // pair for this kind of release upload would realistically need
            // to be raised to; the `mimes:apk` check itself is what actually
            // restricts the accepted file type (Symfony's MimeTypes maps
            // the apk extension to application/vnd.android.package-archive).
            'apk' => 'nullable|file|mimes:apk|max:307200',
        ]);

        $config = AppUpdateConfig::current();

        if ($request->hasFile('apk')) {
            if ($config->apk_path) {
                Storage::disk('public')->delete($config->apk_path);
            }
            // Laravel's store() generates a random filename — the
            // tenant-facing download URL never reflects (and Android never
            // sees) whatever the uploader originally named the file, which
            // is also what keeps this immune to path traversal via a
            // crafted filename.
            $data['apk_path'] = $request->file('apk')->store('app-releases', 'public');
        }
        unset($data['apk']);

        $data['force_update'] = $request->boolean('force_update');

        $config->update($data);

        return back()->with('success', 'অ্যাপ আপডেট কনফিগারেশন সেভ হয়েছে।');
    }
}
