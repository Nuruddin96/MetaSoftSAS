@extends('layouts.panel')

@section('title', 'পেজ এডিট')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-disp font-bold text-2xl">পেজ এডিট</h1>
    <a href="{{ route('tenant.website') }}" class="text-sm text-mute hover:text-ink rounded focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf focus-visible:ring-offset-2">← ফিরে যান</a>
</div>

<x-ui.card class="max-w-3xl space-y-4">
    <form method="POST" action="{{ route('tenant.website.page.update', $page) }}" class="space-y-4">
        @csrf @method('PUT')

        <div>
            <label class="text-sm font-medium">পেজের নাম</label>
            <input name="title" value="{{ $page->title }}" required
                   class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            <p class="text-xs text-mute mt-1">ঠিকানা: /page/{{ $page->slug }}</p>
        </div>

        <div>
            <label class="text-sm font-medium">পেজ হেডার</label>
            <input name="page_header" value="{{ $page->page_header }}" maxlength="200" placeholder="খালি রাখলে পেজের নাম-ই হেডার হিসেবে দেখাবে"
                   class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            <p class="text-xs text-mute mt-1">পেজের ভেতরে বড়, বোল্ড হেডিং হিসেবে দেখায় — মেনুর নাম থেকে আলাদা রাখতে পারেন।</p>
        </div>

        <div>
            <label class="text-sm font-medium">পেজের লেখা</label>
            <textarea name="content" rows="16"
                      class="mt-1 w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm font-mono focus:ring-2 focus:ring-leaf outline-none">{{ $page->content }}</textarea>
            <p class="text-xs text-mute mt-1">সাধারণ লেখা লিখুন — লাইন ব্রেক ঠিক থাকবে। চাইলে HTML ট্যাগও ব্যবহার করতে পারেন।</p>
        </div>

        <div class="flex flex-wrap gap-5 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" name="show_in_footer" value="1" @checked($page->show_in_footer)> ফুটারে দেখাও</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_in_header" value="1" @checked($page->show_in_header)> মেনুতে দেখাও</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($page->is_active)> চালু</label>
        </div>

        <x-ui.button type="submit" variant="accent" size="sm">আপডেট করুন</x-ui.button>
    </form>
</x-ui.card>
@endsection
