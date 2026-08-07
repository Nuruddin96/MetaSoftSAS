@extends('layouts.panel')

@section('title', 'ওয়েবসাইট সেটিংস')

@section('content')
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <h1 class="font-disp font-bold text-2xl">ওয়েবসাইট সেটিংস</h1>
        <p class="text-sm text-mute">আপনার দোকানের সাইট নিজের মতো সাজান</p>
    </div>
    <a href="{{ $tenant->url() }}" target="_blank" class="px-4 py-2.5 rounded-lg border border-ink/15 font-semibold text-sm hover:bg-white">👁 সাইট দেখুন ↗</a>
</div>

{{-- tabs --}}
<div class="flex flex-wrap gap-2 mb-6 border-b border-ink/10 pb-3" id="tabs">
    @foreach ([['brand','🎨 ব্র্যান্ড ও লোগো'],['home','🏠 হোম পেজ'],['pages','📄 পেজ'],['footer','📌 ফুটার ও যোগাযোগ']] as [$id, $label])
        <button data-tab="{{ $id }}"
                class="tab-btn px-4 py-2 rounded-lg text-sm font-semibold {{ $loop->first ? 'bg-ink text-white' : 'bg-white border border-ink/10 hover:border-leaf/40' }}">
            {{ $label }}
        </button>
    @endforeach
</div>

{{-- ============ BRAND ============ --}}
<section data-panel="brand" class="max-w-3xl">
    <form method="POST" action="{{ route('tenant.website.brand') }}" enctype="multipart/form-data"
          class="bg-white rounded-xl border border-ink/5 p-6 space-y-5">
        @csrf
        <div>
            <label class="text-sm font-medium">দোকানের নাম</label>
            <input name="store_name" value="{{ $tenant->store_name }}" required
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            <p class="text-xs text-mute mt-1">সাইটের উপরে ও ব্রাউজার ট্যাবে এই নামটাই দেখাবে</p>
        </div>

        <div>
            <label class="text-sm font-medium">লোগো</label>
            <div class="mt-2 flex items-center gap-4">
                <div class="w-20 h-20 rounded-xl border border-ink/10 bg-paper grid place-items-center overflow-hidden shrink-0">
                    @if ($tenant->logo_path)
                        <img src="{{ asset('storage/' . $tenant->logo_path) }}" class="w-full h-full object-contain">
                    @else
                        <span class="text-2xl">🏪</span>
                    @endif
                </div>
                <div class="flex-1">
                    <input type="file" name="logo" accept="image/*" class="w-full text-sm">
                    <p class="text-xs text-mute mt-1">PNG (স্বচ্ছ ব্যাকগ্রাউন্ড হলে সবচেয়ে ভালো), সর্বোচ্চ ২ MB</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">মূল রং</label>
                <div class="mt-1 flex gap-2">
                    <input type="color" name="primary_color" id="primaryPicker" value="{{ $tenant->primary_color ?? '#128155' }}"
                           class="h-11 w-14 rounded-lg border border-ink/15 cursor-pointer">
                    <input value="{{ $tenant->primary_color ?? '#128155' }}" readonly
                           class="flex-1 rounded-lg border border-ink/15 px-3 text-sm bg-paper" id="pcHex">
                </div>
                <p class="text-xs text-mute mt-1">বাটন, দাম ও লিংকের রং</p>
            </div>
            <div>
                <label class="text-sm font-medium">দ্বিতীয় রং</label>
                <div class="mt-1 flex gap-2">
                    <input type="color" name="secondary_color" id="secondaryPicker" value="{{ $tenant->secondary_color ?? '#f59e0b' }}"
                           class="h-11 w-14 rounded-lg border border-ink/15 cursor-pointer">
                    <input value="{{ $tenant->secondary_color ?? '#f59e0b' }}" readonly
                           class="flex-1 rounded-lg border border-ink/15 px-3 text-sm bg-paper" id="scHex">
                </div>
                <p class="text-xs text-mute mt-1">অফার ব্যাজ ও হাইলাইট</p>
            </div>
        </div>

        <div>
            <label class="text-sm font-medium">দ্রুত কালার প্যালেট</label>
            <p class="text-xs text-mute mt-1 mb-2">এক ক্লিকে দুটো রংই বসিয়ে দিন, পরে চাইলে উপরে থেকে নিজের মতো এডজাস্ট করুন</p>
            <div class="flex flex-wrap gap-2">
                @php
                    $presets = [
                        ['সবুজ', '#128155', '#F5B31A'],
                        ['নীল', '#1D4ED8', '#F59E0B'],
                        ['গোলাপি', '#DB2777', '#FBCFE8'],
                        ['বেগুনি', '#7C3AED', '#FDE68A'],
                        ['কমলা', '#EA580C', '#1F2937'],
                        ['কালো-সোনালি', '#111827', '#D4AF37'],
                        ['লাল', '#DC2626', '#111827'],
                        ['টিল', '#0F766E', '#FACC15'],
                    ];
                @endphp
                @foreach ($presets as [$label, $c1, $c2])
                    <button type="button" onclick="applyPreset('{{ $c1 }}', '{{ $c2 }}')"
                            title="{{ $label }}"
                            class="w-10 h-10 rounded-full border-2 border-white shadow ring-1 ring-ink/10 hover:ring-leaf hover:scale-110 transition overflow-hidden">
                        <span class="block w-full h-1/2" style="background:{{ $c1 }}"></span>
                        <span class="block w-full h-1/2" style="background:{{ $c2 }}"></span>
                    </button>
                @endforeach
            </div>
        </div>

        <div>
            <label class="text-sm font-medium">ঘোষণা বার (সাইটের একদম উপরে)</label>
            <input name="announcement" value="{{ $set['announcement'] ?? '' }}" maxlength="200"
                   placeholder="যেমন: ঈদ অফার! ১০০০৳ এর উপরে ফ্রি ডেলিভারি 🎉"
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
            <p class="text-xs text-mute mt-1">খালি রাখলে বারটা দেখাবে না</p>
        </div>

        <div class="flex gap-3">
            <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
            @if ($tenant->logo_path)
                <a href="{{ route('tenant.website.logo.remove') }}"
                   onclick="return confirm('লোগো সরাবেন?')"
                   class="px-4 py-2.5 rounded-lg border border-ink/15 text-sm hover:bg-paper">লোগো সরান</a>
            @endif
        </div>
    </form>
</section>

{{-- ============ HOMEPAGE ============ --}}
<section data-panel="home" class="hidden max-w-3xl space-y-6">
    <form method="POST" action="{{ route('tenant.website.homepage') }}" class="bg-white rounded-xl border border-ink/5 p-6 space-y-5">
        @csrf
        <p class="font-bold">হোম পেজের সাজসজ্জা</p>

        <div>
            <label class="text-sm font-medium">ব্যানার স্টাইল</label>
            <select name="hero_style" class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 bg-white">
                @foreach (['slider' => 'স্লাইডার (একাধিক ব্যানার ঘুরবে)', 'simple' => 'শুধু প্রথম ব্যানার', 'none' => 'ব্যানার দেখাবে না'] as $k => $v)
                    <option value="{{ $k }}" @selected(($set['hero_style'] ?? 'slider') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="show_categories" value="1" @checked(($set['show_categories'] ?? '1') === '1')>
            ক্যাটাগরি সেকশন দেখাও
        </label>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="show_featured" value="1" @checked(($set['show_featured'] ?? '1') === '1')>
            প্রোডাক্ট সেকশন দেখাও
        </label>

        <div>
            <label class="text-sm font-medium">প্রোডাক্ট সেকশনের শিরোনাম</label>
            <input name="featured_title" value="{{ $set['featured_title'] ?? 'আমাদের প্রোডাক্ট' }}"
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>

        <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
    </form>

    <div class="bg-white rounded-xl border border-ink/5 p-6">
        <p class="font-bold mb-1">ব্যানার ছবি</p>
        <p class="text-xs text-mute mb-4">প্রস্তাবিত সাইজ: ১৬০০ × ৬০০ পিক্সেল। মোবাইলেও ভালো দেখাবে।</p>

        <div class="space-y-3 mb-5">
            @forelse ($banners as $b)
                <div class="flex items-center gap-4 border border-ink/10 rounded-lg p-3">
                    <img src="{{ asset('storage/' . $b->image_path) }}" class="w-28 h-16 object-cover rounded shrink-0">
                    <div class="flex-1 min-w-0 text-sm">
                        <p class="font-medium truncate">{{ $b->title ?: '(শিরোনাম নেই)' }}</p>
                        <p class="text-xs text-mute truncate">{{ $b->subtitle }}</p>
                    </div>
                    <form method="POST" action="{{ route('tenant.website.banner.destroy', $b) }}" onsubmit="return confirm('মুছবেন?')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 text-xs hover:underline">মুছুন</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-mute text-center py-6 border border-dashed border-ink/15 rounded-lg">কোনো ব্যানার নেই</p>
            @endforelse
        </div>

        <form method="POST" action="{{ route('tenant.website.banner.store') }}" enctype="multipart/form-data" class="space-y-3 border-t border-ink/10 pt-5">
            @csrf
            <input type="file" name="image" accept="image/*" required class="w-full text-sm">
            <div class="grid md:grid-cols-2 gap-3">
                <input name="title" placeholder="শিরোনাম (ঐচ্ছিক)" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <input name="subtitle" placeholder="ছোট বর্ণনা (ঐচ্ছিক)" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <input name="button_text" placeholder="বাটনের লেখা (যেমন: এখনই কিনুন)" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <input name="button_link" placeholder="বাটনের লিংক (যেমন: /products)" class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
            <button class="px-5 py-2.5 rounded-lg bg-ink text-white font-semibold text-sm hover:bg-ink/90">+ ব্যানার যোগ করুন</button>
        </form>
    </div>
</section>

{{-- ============ PAGES ============ --}}
<section data-panel="pages" class="hidden max-w-3xl space-y-6">
    <div class="bg-white rounded-xl border border-ink/5">
        <div class="px-5 py-3 border-b border-ink/5 font-bold text-sm">আপনার পেজগুলো</div>
        @forelse ($pages as $p)
            <div class="flex items-center justify-between px-5 py-3 border-b border-ink/5 last:border-0">
                <div class="text-sm">
                    <p class="font-medium">{{ $p->title }}
                        @if (! $p->is_active)<span class="text-xs text-mute">(বন্ধ)</span>@endif
                    </p>
                    <p class="text-xs text-mute">/page/{{ $p->slug }}
                        @if ($p->show_in_header) · হেডারে @endif
                        @if ($p->show_in_footer) · ফুটারে @endif
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="{{ route('tenant.website.page.edit', $p) }}" class="text-leaf text-xs hover:underline">এডিট</a>
                    <form method="POST" action="{{ route('tenant.website.page.destroy', $p) }}" onsubmit="return confirm('মুছবেন?')">
                        @csrf @method('DELETE')
                        <button class="text-red-600 text-xs hover:underline">মুছুন</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-mute text-sm">কোনো পেজ নেই। নিচে থেকে তৈরি করুন — যেমন "আমাদের সম্পর্কে", "রিটার্ন পলিসি"।</p>
        @endforelse
    </div>

    <form method="POST" action="{{ route('tenant.website.page.store') }}" class="bg-white rounded-xl border border-ink/5 p-6 space-y-4">
        @csrf
        <p class="font-bold">নতুন পেজ তৈরি করুন</p>
        <input name="title" required placeholder="পেজের নাম (যেমন: আমাদের সম্পর্কে)"
               class="w-full rounded-lg border border-ink/15 px-3 py-2.5">
        <textarea name="content" rows="6" placeholder="পেজের লেখা এখানে লিখুন..."
                  class="w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm"></textarea>
        <div class="flex flex-wrap gap-5 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" name="show_in_footer" value="1" checked> ফুটারে দেখাও</label>
            <label class="flex items-center gap-2"><input type="checkbox" name="show_in_header" value="1"> মেনুতে দেখাও</label>
        </div>
        <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">পেজ তৈরি করুন</button>
    </form>

    <div class="bg-amber/10 border border-amber/30 rounded-xl p-5 text-sm">
        <p class="font-bold mb-1">💡 যেসব পেজ সাধারণত থাকে</p>
        <p class="text-mute">আমাদের সম্পর্কে · যোগাযোগ · ডেলিভারি তথ্য · রিটার্ন ও রিফান্ড পলিসি · প্রাইভেসি পলিসি — Meta Ads চালাতে চাইলে শেষ দুটো থাকা জরুরি।</p>
    </div>
</section>

{{-- ============ FOOTER ============ --}}
<section data-panel="footer" class="hidden max-w-3xl">
    <form method="POST" action="{{ route('tenant.website.footer') }}" class="bg-white rounded-xl border border-ink/5 p-6 space-y-5">
        @csrf
        <div>
            <label class="text-sm font-medium">দোকান সম্পর্কে (ফুটারে দেখাবে)</label>
            <textarea name="footer_about" rows="3" maxlength="500"
                      class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 text-sm">{{ $set['footer_about'] ?? '' }}</textarea>
        </div>

        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-medium">ফোন নাম্বার</label>
                <input name="footer_phone" value="{{ $set['footer_phone'] ?? $tenant->owner_phone }}"
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
            </div>
            <div>
                <label class="text-sm font-medium">ইমেইল</label>
                <input name="footer_email" value="{{ $set['footer_email'] ?? '' }}"
                       class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
            </div>
        </div>

        <div>
            <label class="text-sm font-medium">ঠিকানা</label>
            <input name="footer_address" value="{{ $set['footer_address'] ?? '' }}"
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>

        <div class="border-t border-ink/10 pt-5">
            <p class="font-bold text-sm mb-3">সোশ্যাল মিডিয়া লিংক</p>
            <div class="grid md:grid-cols-2 gap-3">
                <input name="social_facebook" value="{{ $set['social_facebook'] ?? '' }}" placeholder="https://facebook.com/yourpage"
                       class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <input name="social_instagram" value="{{ $set['social_instagram'] ?? '' }}" placeholder="https://instagram.com/yourpage"
                       class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <input name="social_youtube" value="{{ $set['social_youtube'] ?? '' }}" placeholder="https://youtube.com/@yourchannel"
                       class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <input name="social_tiktok" value="{{ $set['social_tiktok'] ?? '' }}" placeholder="https://tiktok.com/@yourpage"
                       class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
            </div>
        </div>

        <div class="border-t border-ink/10 pt-5">
            <p class="font-bold text-sm mb-3">হোয়াটসঅ্যাপ চ্যাট বাটন</p>
            <div class="grid md:grid-cols-2 gap-3 items-center">
                <input name="whatsapp_number" value="{{ $set['whatsapp_number'] ?? '' }}" placeholder="8801XXXXXXXXX"
                       class="rounded-lg border border-ink/15 px-3 py-2.5 text-sm">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="show_whatsapp_float" value="1" @checked(($set['show_whatsapp_float'] ?? '0') === '1')>
                    সাইটে ভাসমান বাটন দেখাও
                </label>
            </div>
            <p class="text-xs text-mute mt-2">দেশের কোড সহ লিখুন (৮৮ দিয়ে শুরু), যেমন: 8801712345678</p>
        </div>

        <div>
            <label class="text-sm font-medium">ফুটারের নিচের লেখা</label>
            <input name="footer_note" value="{{ $set['footer_note'] ?? '' }}" placeholder="যেমন: সকল পণ্যে ৭ দিনের রিটার্ন সুবিধা"
                   class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5">
        </div>

        <button class="px-6 py-2.5 rounded-lg bg-leaf text-white font-semibold text-sm hover:bg-leafdk">সেভ করুন</button>
    </form>
</section>

@push('scripts')
<script>
    document.querySelectorAll('.tab-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.tab-btn').forEach(function (b) {
                b.className = 'tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-white border border-ink/10 hover:border-leaf/40';
            });
            btn.className = 'tab-btn px-4 py-2 rounded-lg text-sm font-semibold bg-ink text-white';
            document.querySelectorAll('[data-panel]').forEach(function (p) {
                p.classList.toggle('hidden', p.dataset.panel !== btn.dataset.tab);
            });
        });
    });

    document.querySelector('[name=primary_color]')?.addEventListener('input', function (e) {
        document.getElementById('pcHex').value = e.target.value;
    });
    document.querySelector('[name=secondary_color]')?.addEventListener('input', function (e) {
        document.getElementById('scHex').value = e.target.value;
    });

    function applyPreset(primary, secondary) {
        document.getElementById('primaryPicker').value = primary;
        document.getElementById('secondaryPicker').value = secondary;
        document.getElementById('pcHex').value = primary;
        document.getElementById('scHex').value = secondary;
    }
</script>
@endpush
@endsection
