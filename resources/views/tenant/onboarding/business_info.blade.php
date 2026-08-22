@extends('layouts.onboarding')

@section('title', 'ব্যবসার তথ্য')

@section('content')
<h1 class="font-disp font-bold text-xl sm:text-2xl mb-1.5">ব্যবসার ঠিকানা</h1>
<p class="text-mute text-sm mb-6">সবগুলোই ঐচ্ছিক — এখন না দিলেও পরে Settings থেকে যোগ করতে পারবেন।</p>

<form method="POST" action="{{ route('tenant.onboarding.business_info.store') }}" class="space-y-4">
    @csrf

    <div>
        <label class="text-sm font-medium">ঠিকানা</label>
        <input type="text" name="footer_address" value="{{ old('footer_address') }}" maxlength="255"
               class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none"
               placeholder="যেমন: বাড়ি ১২, রোড ৫, ধানমন্ডি, ঢাকা">
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="text-sm font-medium">বিভাগ</label>
            <select name="business_division_id" id="divisionSelect"
                    class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <option value="">নির্বাচন করুন</option>
                @foreach ($divisions as $division)
                    <option value="{{ $division->id }}" @selected(old('business_division_id') == $division->id)>{{ $division->bn_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-sm font-medium">জেলা</label>
            <select name="business_district_id" id="districtSelect"
                    class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none">
                <option value="">নির্বাচন করুন</option>
            </select>
        </div>
    </div>

    <div>
        <label class="text-sm font-medium">ফেসবুক পেজ লিংক (ঐচ্ছিক)</label>
        <input type="url" name="social_facebook" value="{{ old('social_facebook') }}" maxlength="255"
               class="mt-1 w-full rounded-lg border border-ink/15 px-3 py-2.5 focus:ring-2 focus:ring-leaf outline-none"
               placeholder="https://facebook.com/yourpage">
    </div>

    <button type="submit" class="w-full py-3.5 rounded-xl bg-leaf text-white font-bold hover:bg-leafdk">
        চালিয়ে যান
    </button>
</form>

<script>
(function () {
    // Same client-side division->district filter already used by the
    // storefront checkout page — all districts are embedded up front and
    // filtered by division_id, no extra request needed.
    const allDistricts = @json($districts);
    const divisionSelect = document.getElementById('divisionSelect');
    const districtSelect = document.getElementById('districtSelect');
    const selectedDistrict = '{{ old('business_district_id') }}';

    function renderDistricts() {
        const divisionId = divisionSelect.value;
        districtSelect.innerHTML = '<option value="">নির্বাচন করুন</option>';
        allDistricts
            .filter(d => String(d.division_id) === String(divisionId))
            .forEach(d => {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.bn_name;
                if (String(d.id) === selectedDistrict) opt.selected = true;
                districtSelect.appendChild(opt);
            });
    }

    divisionSelect.addEventListener('change', renderDistricts);
    if (divisionSelect.value) renderDistricts();
})();
</script>
@endsection
