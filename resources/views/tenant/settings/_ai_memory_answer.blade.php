{{--
    Reusable "answer" input for an AI মেমোরী Q&A — text OR a recorded/
    uploaded voice clip, shared between the "new memory" form and each
    saved memory's inline edit form (Tenant\AiMemoryController::store()/
    update()). $prefix must be unique per instance on the page (the "new"
    form uses 'new', each edit form uses "edit-{$memory->id}") since the
    JS below looks widgets up by that id, not by DOM nesting.

    Props: $prefix (string), $currentType ('text'|'audio'), $currentAnswer
    (string), $currentAudioUrl (string|null).
--}}
@php
    $prefix = $prefix ?? 'new';
    $currentType = $currentType ?? 'text';
    $currentAnswer = $currentAnswer ?? '';
    $currentAudioUrl = $currentAudioUrl ?? null;
@endphp
<div class="space-y-2" data-answer-widget="{{ $prefix }}">
    <div class="flex gap-4 text-xs">
        <label class="flex items-center gap-1.5 cursor-pointer">
            <input type="radio" name="answer_type" value="text" onchange="aiMemoryToggleType('{{ $prefix }}', 'text')" @checked($currentType === 'text')> টেক্সট
        </label>
        <label class="flex items-center gap-1.5 cursor-pointer">
            <input type="radio" name="answer_type" value="audio" onchange="aiMemoryToggleType('{{ $prefix }}', 'audio')" @checked($currentType === 'audio')> ভয়েস
        </label>
    </div>

    <textarea name="answer" rows="2" maxlength="2000"
              placeholder="উত্তর — যেমন: ঢাকার ভিতরে ডেলিভারি চার্জ ৬০ টাকা।"
              class="answerText w-full rounded-btn border border-ink/15 px-3 py-2.5 text-sm focus:ring-2 focus:ring-leaf outline-none {{ $currentType === 'audio' ? 'hidden' : '' }}">{{ $currentAnswer }}</textarea>

    <div class="answerAudioBox {{ $currentType === 'audio' ? '' : 'hidden' }} rounded-btn border border-ink/15 p-3 space-y-2 bg-paper/40">
        @if ($currentAudioUrl)
            <audio controls src="{{ $currentAudioUrl }}" class="w-full h-9"></audio>
            <p class="text-[11px] text-mute">নতুন রেকর্ড/আপলোড দিলে আগেরটা বদলে যাবে।</p>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="voiceRecordBtn text-xs font-semibold px-3 py-2 rounded-btn border border-ink/15 hover:bg-white bg-white" onclick="aiMemoryStartRecording('{{ $prefix }}')">🎙️ রেকর্ড করুন</button>
            <button type="button" class="voiceStopBtn hidden text-xs font-semibold px-3 py-2 rounded-btn bg-red-600 text-white" onclick="aiMemoryStopRecording('{{ $prefix }}')">⏹ থামান (<span class="voiceTimer">0:00</span>)</button>
            {{-- Bordered, clearly-clickable file-input container, same treatment as the rest of the panel's file inputs (Part 10). --}}
            <label class="text-xs font-semibold px-3 py-2 rounded-btn border border-dashed border-ink/25 hover:bg-white bg-white cursor-pointer">
                📁 ফাইল আপলোড
                <input type="file" name="answer_audio" accept="audio/*" class="hidden voiceFileInput" onchange="aiMemoryHandleFileChange('{{ $prefix }}', this)">
            </label>
        </div>
        <audio class="voicePreview hidden w-full h-9" controls></audio>
        <p class="voiceUnsupported hidden text-xs text-amber-700">এই ব্রাউজারে সরাসরি রেকর্ডিং সমর্থিত নয় — ফাইল আপলোড করুন।</p>
    </div>
</div>
