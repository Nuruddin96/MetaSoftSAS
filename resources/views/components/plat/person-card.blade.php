@props(['person'])
<article class="flex h-full flex-col rounded-[20px] border border-hair bg-white p-5 transition hover:shadow-[0_12px_32px_-14px_rgba(10,20,40,0.2)]">
    <div class="flex items-center gap-3.5">
        <x-plat.logo :initials="$person['initials']" :from="$person['from']" :to="$person['to']" :size="56" round />
        <div class="min-w-0">
            <h3 class="flex items-center gap-1.5 font-plat text-base font-extrabold text-night"><span class="truncate">{{ $person['name'] }}</span><x-plat.verified size="w-3.5 h-3.5" /></h3>
            <p class="truncate text-[13px] text-slate2">{{ $person['role'] }}, {{ $person['brand'] }}</p>
        </div>
    </div>
    <p class="mt-4 line-clamp-3 text-sm leading-relaxed text-slate2">{{ $person['story'] }}</p>
    <div class="mt-auto flex items-center justify-between gap-2 pt-4">
        <x-plat.badge :type="$person['recognition']['type']" :label="$person['recognition']['label']" size="xs" class="min-w-0 truncate" />
        <button type="button" data-profile="person:{{ $person['slug'] }}" class="shrink-0 rounded text-[13px] font-bold text-leaf hover:underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-leaf">Profile →</button>
    </div>
</article>
