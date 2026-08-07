@extends('layouts.central')

@section('title', 'রেজিস্ট্রেশন — MetaSoft BD')

@section('content')
<div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-lg">
        <a href="/" class="flex items-center gap-2 justify-center mb-8">
            <span class="w-9 h-9 rounded bg-leaf grid place-items-center text-white font-bold text-lg">M</span>
            <span class="font-disp font-bold text-xl">MetaSoft BD</span>
        </a>

        <div class="bg-white rounded-2xl shadow-sm border border-ink/5 p-8">
            <h1 class="font-disp font-bold text-2xl text-center">আপনার দোকান খুলুন</h1>
            <p class="text-mute text-sm text-center mt-2">৭ দিন ফ্রি — কোনো কার্ড লাগবে না</p>

            @if ($errors->any())
                <div class="mt-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg p-3">
                    <ul class="list-disc ml-4 space-y-0.5">
                        @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('register.store') }}" class="mt-6 space-y-4">
                @csrf
                @if ($ref ?? request('ref'))
                    <input type="hidden" name="ref" value="{{ $ref ?? request('ref') }}">
                @endif
                <div>
                    <label class="text-sm font-medium">বিজনেসের নাম <span class="text-mute font-normal">(ইংরেজিতে)</span></label>
                    <input name="store_name" id="store_name" value="{{ old('store_name') }}" required
                           class="mt-1 w-full rounded-lg border-ink/15 border px-3 py-2.5 focus:ring-2 focus:ring-leaf focus:border-leaf outline-none"
                           placeholder="যেমন: Rahim Fashion House">
                    <p class="mt-2 text-sm text-mute">
                        আপনার ওয়েবসাইট হবে:
                        <span id="subdomain_preview" class="font-semibold text-leafdk">___.metasoftbd.com</span>
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium">আপনার নাম</label>
                        <input name="owner_name" value="{{ old('owner_name') }}" required
                               class="mt-1 w-full rounded-lg border-ink/15 border px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                    </div>
                    <div>
                        <label class="text-sm font-medium">মোবাইল নাম্বার</label>
                        <input name="owner_phone" value="{{ old('owner_phone') }}" required
                               class="mt-1 w-full rounded-lg border-ink/15 border px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none"
                               placeholder="01XXXXXXXXX">
                    </div>
                </div>
                <div>
                    <label class="text-sm font-medium">ইমেইল</label>
                    <input type="email" name="owner_email" value="{{ old('owner_email') }}" required
                           class="mt-1 w-full rounded-lg border-ink/15 border px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-medium">পাসওয়ার্ড</label>
                        <input type="password" name="password" required minlength="6"
                               class="mt-1 w-full rounded-lg border-ink/15 border px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                    </div>
                    <div>
                        <label class="text-sm font-medium">আবার পাসওয়ার্ড</label>
                        <input type="password" name="password_confirmation" required
                               class="mt-1 w-full rounded-lg border-ink/15 border px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                    </div>
                </div>
                <button class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">
                    দোকান তৈরি করুন →
                </button>
            </form>
        </div>
        <p class="text-center text-sm text-mute mt-5">
            আগে থেকেই একাউন্ট আছে? <a href="{{ route('central.login') }}" class="text-leaf font-semibold hover:underline">লগইন করুন</a>
        </p>
    </div>
</div>

<script>
    // Live preview: first word of business name -> subdomain
    const nameInput = document.getElementById('store_name');
    const preview   = document.getElementById('subdomain_preview');

    function updatePreview() {
        const firstWord = (nameInput.value.trim().split(/\s+/)[0] || '');
        const slug = firstWord.toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 30);

        if (slug.length >= 2) {
            preview.textContent = slug + '.metasoftbd.com';
            preview.classList.remove('text-red-600');
            preview.classList.add('text-leafdk');
        } else if (firstWord.length > 0) {
            preview.textContent = 'নামটি ইংরেজি অক্ষরে লিখুন';
            preview.classList.remove('text-leafdk');
            preview.classList.add('text-red-600');
        } else {
            preview.textContent = '___.metasoftbd.com';
            preview.classList.remove('text-red-600');
            preview.classList.add('text-leafdk');
        }
    }

    nameInput.addEventListener('input', updatePreview);
    updatePreview();
</script>
@endsection
