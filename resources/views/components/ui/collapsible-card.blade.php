{{--
    Collapsible variant of x-ui.card — native <details>/<summary>, no JS
    state management needed (survives a page reload with $open as the
    server-decided default, works identically on mobile, fully accessible
    for free). Use in place of <x-ui.card> when a section's content is
    secondary/occasional (integration settings, advanced options) rather
    than something the tenant needs to see at a glance on every page load.

    Props:
      title:   string                                   required
      open:    bool                                      (default: false)
      padding: 'sm' | 'default'                           (default: 'default')

    Slots:
      status: optional — rendered next to the title, INSIDE the always-
              visible <summary> row, so a connected/disconnected badge (or
              similar at-a-glance state) stays visible even while collapsed.
      (default slot): the collapsible body — same content that used to sit
              directly inside <x-ui.card>.
--}}
@props(['title' => '', 'open' => false, 'padding' => 'default'])

<details {{ $attributes->merge(['class' => 'group rounded-card border border-ink/5 bg-white overflow-hidden']) }} @if($open) open @endif>
    <summary class="flex items-center justify-between gap-3 px-6 py-4 cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden hover:bg-paper/40 transition">
        <span class="flex items-center gap-2 flex-wrap min-w-0">
            <span class="font-bold text-sm">{{ $title }}</span>
            @isset($status){{ $status }}@endisset
        </span>
        <i data-lucide="chevron-down" class="w-4 h-4 text-mute shrink-0 transition-transform duration-200 group-open:rotate-180"></i>
    </summary>
    <div class="{{ $padding === 'sm' ? 'px-4 pb-4' : 'px-6 pb-6' }} pt-1 space-y-4">
        {{ $slot }}
    </div>
</details>
