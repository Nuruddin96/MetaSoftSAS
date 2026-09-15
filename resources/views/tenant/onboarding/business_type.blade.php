@extends('layouts.onboarding')

@section('title', 'আপনার ব্যবসার ধরন')

@section('content')
<h1 class="font-disp font-bold text-xl sm:text-2xl mb-1.5">আপনার ব্যবসাটা কী ধরনের?</h1>
<p class="text-mute text-sm mb-6">এটা বাছাই করলে আমরা আপনার জন্য কিছু ক্যাটাগরি আগে থেকে তৈরি করে দেব — পরে চাইলে বদলাতে পারবেন।</p>

<form method="POST" action="{{ route('tenant.onboarding.business_type.store') }}" id="businessTypeForm">
    @csrf
    <input type="hidden" name="business_type_id" id="business_type_id" value="{{ old('business_type_id') }}">

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2.5 mb-4">
        @foreach ($businessTypes as $type)
            <button type="button"
                    data-id="{{ $type->id }}"
                    data-slug="{{ $type->slug }}"
                    class="biz-type-card text-left rounded-xl border border-ink/10 p-3 hover:border-leaf transition-colors">
                <span class="text-2xl block mb-1">{{ $type->icon }}</span>
                <span class="text-xs font-semibold leading-snug block">{{ $type->name_bn }}</span>
            </button>
        @endforeach
    </div>

    <div id="otherLabelWrap" class="hidden mb-4">
        <label class="text-sm font-medium">আপনার ব্যবসার নাম লিখুন</label>
        <input type="text" name="business_type_other" maxlength="100"
               class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none"
               placeholder="যেমন: হ্যান্ডক্রাফট গহনা">
    </div>

    @error('business_type_id')
        <p class="mb-4 text-red-600 text-sm">{{ $message }}</p>
    @enderror

    <button type="submit" id="continueBtn" disabled
            class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk disabled:opacity-40 disabled:cursor-not-allowed">
        চালিয়ে যান
    </button>
</form>

<script>
(function () {
    const cards = document.querySelectorAll('.biz-type-card');
    const idInput = document.getElementById('business_type_id');
    const otherWrap = document.getElementById('otherLabelWrap');
    const continueBtn = document.getElementById('continueBtn');

    cards.forEach(card => {
        card.addEventListener('click', () => {
            cards.forEach(c => c.classList.remove('border-leaf', 'bg-leaf/5', 'ring-1', 'ring-leaf'));
            card.classList.add('border-leaf', 'bg-leaf/5', 'ring-1', 'ring-leaf');
            idInput.value = card.dataset.id;
            otherWrap.classList.toggle('hidden', card.dataset.slug !== 'other');
            continueBtn.disabled = false;
        });
    });
})();
</script>
@endsection
