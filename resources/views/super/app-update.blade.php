@extends('layouts.super')

@section('title', 'অ্যাপ আপডেট')

@section('content')
<h1 class="font-disp font-bold text-2xl mb-2">📲 অ্যাপ আপডেট</h1>
<p class="text-sm text-mute mb-6">Android Business App-এর সর্বশেষ ভার্সন/APK এখানে সেট করুন। মোবাইল অ্যাপ <code>GET /api/mobile/v1/app-version</code> কল করে এই তথ্য পাবে।</p>

<x-ui.card class="max-w-2xl">
    <form method="POST" action="{{ route('super.app-update.update') }}" enctype="multipart/form-data" class="space-y-4">
        @csrf

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium mb-1">Latest Version</label>
                <input type="text" name="latest_version" value="{{ old('latest_version', $config->latest_version) }}" required
                       placeholder="1.1.0" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
                @error('latest_version') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Latest Build</label>
                <input type="number" name="latest_build" value="{{ old('latest_build', $config->latest_build) }}" required min="1"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
                @error('latest_build') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Minimum Supported Version</label>
                <input type="text" name="minimum_supported_version" value="{{ old('minimum_supported_version', $config->minimum_supported_version) }}" required
                       placeholder="1.0.5" class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
                @error('minimum_supported_version') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Minimum Supported Build</label>
                <input type="number" name="minimum_supported_build" value="{{ old('minimum_supported_build', $config->minimum_supported_build) }}" required min="1"
                       class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">
                @error('minimum_supported_build') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">Release Notes</label>
            <textarea name="release_notes" rows="3" maxlength="2000" placeholder="Bug fixes and performance improvements."
                      class="w-full rounded-lg border border-ink/15 px-3 py-2 text-sm focus:ring-2 focus:ring-leaf outline-none">{{ old('release_notes', $config->release_notes) }}</textarea>
            @error('release_notes') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium mb-1">APK ফাইল</label>
            <input type="file" name="apk" accept=".apk" class="w-full text-sm">
            @error('apk') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            @if ($config->apk_path)
                <p class="text-xs text-mute mt-1">বর্তমান: <a href="{{ $config->apkUrl() }}" target="_blank" class="text-leaf underline">{{ $config->apkUrl() }}</a> — নতুন ফাইল না দিলে এটাই থাকবে।</p>
            @else
                <p class="text-xs text-red-600 mt-1">এখনো কোনো APK আপলোড করা হয়নি — download_url খালি থাকবে।</p>
            @endif
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="force_update" value="1" @checked($config->force_update)>
            জোর করে আপডেট করাবে (Force Update) — চালু থাকলে Flutter অ্যাপ সব টেনেন্টকে বাধ্যতামূলক আপডেট দেখাবে, build number নির্বিশেষে।
        </label>

        <button class="px-5 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
    </form>
</x-ui.card>
@endsection
