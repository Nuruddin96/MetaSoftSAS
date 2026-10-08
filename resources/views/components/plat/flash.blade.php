{{-- Session flash + first validation error for platform pages (public, brand owner). --}}
@if(session('success') || session('voted'))
    <div role="status" {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-2xl border border-leaf/25 bg-mint px-4 py-3 text-sm font-semibold text-leafdk']) }}>
        <x-plat.icon name="check" class="mt-0.5 w-4 h-4 shrink-0" stroke="2.6" /> <span>{{ session('success') ?? session('voted') }}</span>
    </div>
@endif
@if(session('error'))
    <div role="alert" {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700']) }}>
        <x-plat.icon name="info" class="mt-0.5 w-4 h-4 shrink-0" /> <span>{{ session('error') }}</span>
    </div>
@endif
