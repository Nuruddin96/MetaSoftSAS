{{--
    Shared "Design" sub-panel — included by every section editor partial
    (tenant/landing-pages/sections/_*.blade.php) instead of duplicating
    these fields 16 times. Field names all live under data[design][...],
    parsed uniformly by SectionDataService::parseDesign() for every
    section type. $data is the section's current `data` array (design
    sub-key optional — a section saved before this feature existed just
    gets every field below its own default).
--}}
@php
    $dd = $data['design'] ?? [];
    $sel = 'mt-1 w-full rounded-btn border border-ink/15 px-3 py-2 text-sm bg-white';
    $lbl = 'text-xs font-medium text-mute';
@endphp
<details class="mt-2 border-t border-ink/10 pt-3">
    <summary class="cursor-pointer text-sm font-semibold text-mute list-none flex items-center gap-1.5">
        🎨 ডিজাইন কাস্টমাইজ করুন <span class="text-xs font-normal">(ঐচ্ছিক)</span>
    </summary>

    <div class="mt-3 grid sm:grid-cols-2 gap-3">
        <div>
            <label class="{{ $lbl }}">সেকশনের প্রস্থ</label>
            <select name="data[design][layout][width]" class="{{ $sel }}">
                <option value="boxed" @selected(($dd['layout']['width'] ?? 'boxed') === 'boxed')>স্বাভাবিক</option>
                <option value="full" @selected(($dd['layout']['width'] ?? '') === 'full')>ফুল উইথ</option>
            </select>
        </div>
        <div>
            <label class="{{ $lbl }}">লেখা এলাইনমেন্ট</label>
            <select name="data[design][typography][align]" class="{{ $sel }}">
                <option value="">— ডিফল্ট —</option>
                <option value="left" @selected(($dd['typography']['align'] ?? '') === 'left')>বামে</option>
                <option value="center" @selected(($dd['typography']['align'] ?? '') === 'center')>মাঝে</option>
                <option value="right" @selected(($dd['typography']['align'] ?? '') === 'right')>ডানে</option>
            </select>
        </div>

        <div>
            <label class="{{ $lbl }}">উপরে স্পেস (Padding Top)</label>
            <select name="data[design][spacing][pt]" class="{{ $sel }}">
                <option value="">— ডিফল্ট —</option>
                @foreach (['none' => 'নেই', 'sm' => 'ছোট', 'md' => 'মাঝারি', 'lg' => 'বড়', 'xl' => 'অনেক বড়'] as $k => $v)
                    <option value="{{ $k }}" @selected(($dd['spacing']['pt'] ?? '') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $lbl }}">নিচে স্পেস (Padding Bottom)</label>
            <select name="data[design][spacing][pb]" class="{{ $sel }}">
                <option value="">— ডিফল্ট —</option>
                @foreach (['none' => 'নেই', 'sm' => 'ছোট', 'md' => 'মাঝারি', 'lg' => 'বড়', 'xl' => 'অনেক বড়'] as $k => $v)
                    <option value="{{ $k }}" @selected(($dd['spacing']['pb'] ?? '') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="{{ $lbl }}">হেডিং সাইজ</label>
            <select name="data[design][typography][heading_size]" class="{{ $sel }}">
                @foreach (['sm' => 'ছোট', 'md' => 'মাঝারি', 'lg' => 'বড়', 'xl' => 'অনেক বড়'] as $k => $v)
                    <option value="{{ $k }}" @selected(($dd['typography']['heading_size'] ?? 'md') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $lbl }}">লেখার সাইজ</label>
            <select name="data[design][typography][body_size]" class="{{ $sel }}">
                @foreach (['sm' => 'ছোট', 'md' => 'মাঝারি', 'lg' => 'বড়'] as $k => $v)
                    <option value="{{ $k }}" @selected(($dd['typography']['body_size'] ?? 'md') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Colors: each needs an explicit "custom color" checkbox — see SectionDataService::parseDesign(). --}}
    <div class="mt-4 grid sm:grid-cols-2 gap-3">
        @foreach ([
            'heading_color' => 'হেডিং কালার',
            'text_color' => 'লেখার কালার',
            'button_color' => 'বাটনের কালার',
            'button_text_color' => 'বাটনের লেখার কালার',
        ] as $key => $label)
            <div class="flex items-center gap-2">
                <input type="checkbox" name="data[design][colors][{{ $key }}_enabled]" value="1" id="dc_{{ $key }}_{{ $section['id'] ?? '' }}"
                       @checked(!empty($dd['colors'][$key])) class="shrink-0">
                <label for="dc_{{ $key }}_{{ $section['id'] ?? '' }}" class="text-sm flex-1">{{ $label }}</label>
                <input type="color" name="data[design][colors][{{ $key }}]" value="{{ $dd['colors'][$key] ?? '#128155' }}" class="w-10 h-8 rounded border border-ink/15">
            </div>
        @endforeach
    </div>

    {{-- Background --}}
    <div class="mt-4">
        <label class="{{ $lbl }}">সেকশনের ব্যাকগ্রাউন্ড</label>
        <select name="data[design][background][type]" class="{{ $sel }}">
            @foreach (['none' => 'ডিফল্ট (কোনো ব্যাকগ্রাউন্ড না)', 'color' => 'সলিড কালার', 'gradient' => 'গ্রেডিয়েন্ট', 'image' => 'ছবি'] as $k => $v)
                <option value="{{ $k }}" @selected(($dd['background']['type'] ?? 'none') === $k)>{{ $v }}</option>
            @endforeach
        </select>
        <div class="mt-2 grid sm:grid-cols-3 gap-3">
            <div>
                <label class="{{ $lbl }}">ব্যাকগ্রাউন্ড কালার (সলিড/গ্রেডিয়েন্ট শুরু)</label>
                <input type="color" name="data[design][colors][bg]" value="{{ $dd['colors']['bg'] ?? '#128155' }}" class="mt-1 w-full h-9 rounded border border-ink/15">
            </div>
            <div>
                <label class="{{ $lbl }}">গ্রেডিয়েন্ট শেষ কালার</label>
                <input type="color" name="data[design][background][gradient_to]" value="{{ $dd['background']['gradient_to'] ?? '#F5B31A' }}" class="mt-1 w-full h-9 rounded border border-ink/15">
            </div>
            <div>
                <label class="{{ $lbl }}">ছবি</label>
                <input type="file" name="data[design][background][image]" accept="image/*" class="mt-1 w-full text-xs">
                @if (!empty($dd['background']['image_path']))
                    <label class="mt-1 flex items-center gap-1.5 text-xs text-mute">
                        <input type="checkbox" name="data[design][background][remove_image]" value="1"> ছবি সরান
                    </label>
                @endif
            </div>
        </div>
        <div class="mt-2">
            <label class="{{ $lbl }}">ছবির উপর অন্ধকার ওভারলে (০–৮০%)</label>
            <input type="range" name="data[design][background][overlay]" min="0" max="80" step="10" value="{{ $dd['background']['overlay'] ?? 0 }}" class="mt-1 w-full">
        </div>
    </div>

    {{-- Border & shadow --}}
    <div class="mt-4 grid sm:grid-cols-3 gap-3">
        <div>
            <label class="{{ $lbl }}">বর্ডার</label>
            <select name="data[design][border][width]" class="{{ $sel }}">
                <option value="0" @selected(($dd['border']['width'] ?? '0') === '0')>নেই</option>
                <option value="1" @selected(($dd['border']['width'] ?? '') === '1')>পাতলা</option>
                <option value="2" @selected(($dd['border']['width'] ?? '') === '2')>মোটা</option>
            </select>
        </div>
        <div>
            <label class="{{ $lbl }}">বর্ডার রেডিয়াস</label>
            <select name="data[design][border][radius]" class="{{ $sel }}">
                @foreach (['none' => 'নেই', 'sm' => 'ছোট', 'md' => 'মাঝারি', 'lg' => 'বড়', 'full' => 'পূর্ণ'] as $k => $v)
                    <option value="{{ $k }}" @selected(($dd['border']['radius'] ?? 'md') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $lbl }}">শ্যাডো</label>
            <select name="data[design][shadow]" class="{{ $sel }}">
                <option value="">— ডিফল্ট —</option>
                @foreach (['none' => 'নেই', 'sm' => 'ছোট', 'md' => 'মাঝারি', 'lg' => 'বড়'] as $k => $v)
                    <option value="{{ $k }}" @selected(($dd['shadow'] ?? '') === $k)>{{ $v }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <label class="mt-3 flex items-center gap-1.5 text-sm">
        <input type="checkbox" name="data[design][layout][stack_mobile]" value="1" @checked($dd['layout']['stack_mobile'] ?? true)>
        মোবাইলে ছবি ও লেখা আলাদা সারিতে দেখান (কলাম লেআউটের জন্য)
    </label>
</details>
