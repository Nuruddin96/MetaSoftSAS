{{--
    Brand profile card. `tag` decides the corner label: 'editor' = Editor's
    pick (earned/editorial), 'sponsored' = paid placement (always labelled,
    grey, never an award badge). The verified seal appears only when
    `verified` is true. Sample (showcase) brands open the quick-view drawer
    (home.js, data-profile); real brands (`live`) link to their profile page.
--}}
@props(['brand'])
@php $live = $brand['live'] ?? false; @endphp
<article class="group relative flex h-full flex-col overflow-hidden rounded-[20px] border border-hair bg-white transition duration-200 hover:-translate-y-0.5 hover:shadow-[0_12px_32px_-12px_rgba(10,20,40,0.18)]">
    <div class="relative h-28 shrink-0 overflow-hidden sm:h-32" style="background:linear-gradient(135deg,{{ $brand['from'] }},{{ $brand['to'] }})">
        @if(! empty($brand['cover']))
            {{-- Real brands: their own first gallery photo. Sample brands: generated demo cover. --}}
            <img src="{{ $brand['cover'] }}" alt="" loading="lazy" class="absolute inset-0 h-full w-full object-cover transition duration-500 group-hover:scale-[1.03]">
            <span class="absolute inset-0 bg-gradient-to-t from-black/25 to-transparent"></span>
        @else
            <span class="absolute -right-6 -top-10 h-40 w-40 rounded-full bg-white/10"></span>
            <span class="absolute left-10 top-12 h-32 w-32 rounded-full bg-white/[0.07]"></span>
        @endif
        @if(($brand['sample'] ?? false) && ! config('platform.showcase_preview'))
            {{-- Sample fill-in (no real brand for this slot yet); the global "Preview" notice covers it while preview mode is on. --}}
            <span class="absolute left-3 top-3 rounded-full bg-white/90 px-2 py-0.5 text-[10.5px] font-semibold text-slate2">Sample</span>
        @endif
        <div class="absolute right-3 top-3">
            @if($brand['tag'] === 'sponsored')
                <x-plat.badge type="sponsored" label="Sponsored" size="xs" />
            @elseif($brand['tag'] === 'editor')
                <x-plat.badge type="editor" label="Editor’s pick" size="xs" />
            @endif
        </div>
    </div>
    <div class="relative -mt-7 flex flex-1 flex-col px-5 pb-5">
        <x-plat.logo :initials="$brand['initials']" :from="$brand['from']" :to="$brand['to']" :size="56" ring :src="$brand['logo'] ?? null" :alt="$brand['name'].' logo'" />
        <h3 class="mt-3 flex items-center gap-1.5 font-plat text-[17px] font-extrabold text-night">
            <span class="truncate">{{ $brand['name'] }}</span> @if($brand['verified'])<x-plat.verified />@endif
        </h3>
        <p class="mt-0.5 flex items-center gap-1 text-[13px] text-slate2">
            {{ $brand['category'] }} <span aria-hidden="true">·</span>
            <x-plat.icon name="map-pin" class="w-3.5 h-3.5" /> {{ $brand['district'] }}
        </p>
        <p class="mt-3 line-clamp-2 text-sm leading-relaxed text-slate2">{{ $brand['description'] }}</p>
        <div class="mt-3 flex flex-wrap gap-1.5">
            @forelse($brand['badges'] as $b)
                <x-plat.badge :type="$b['type']" :label="$b['label']" size="xs" />
            @empty
                @if($brand['verified'])<x-plat.badge type="verified" label="Verified business" size="xs" />@endif
            @endforelse
        </div>
        <div class="mt-auto flex items-center justify-between pt-4">
            <span class="text-[13px] font-semibold text-slate2">
                @if(! empty($brand['followers'])){{ $brand['followers'] }} followers @elseif($brand['founded'])Since {{ $brand['founded'] }} @else {{ $brand['division'] }} @endif
            </span>
            @if($live)
                <a href="{{ $brand['url'] }}"
                   class="rounded-[10px] border border-hair px-3.5 py-2 text-[13px] font-bold text-night transition after:absolute after:inset-0 hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
                    View profile<span class="sr-only">: {{ $brand['name'] }}</span>
                </a>
            @else
                <button type="button" data-profile="brand:{{ $brand['slug'] }}"
                        class="rounded-[10px] border border-hair px-3.5 py-2 text-[13px] font-bold text-night transition hover:border-leaf hover:text-leaf focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">
                    View profile
                </button>
            @endif
        </div>
    </div>
</article>
