{{--
    "✓ Verified" pill for dark (navy/cover) backgrounds, styled after the
    bangladeshibrands.com profile badge: rounded-full, 1px blue border, deep
    blue tint, light-blue text. Render it only when Super Admin has verified
    the brand (brands.is_verified); it is never earned by awards, votes,
    featuring or sponsorship.
--}}
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1 rounded-full border border-[#1E40AF]/60 bg-[#1E3A8A]/45 px-2 py-0.5 text-xs font-semibold leading-4 text-[#7DB3FF]']) }}
      title="MetaSoft BD Verified — checked by our team">
    <x-plat.icon name="check" class="w-3 h-3" :stroke="3" /> Verified<span class="sr-only"> by MetaSoft BD</span>
</span>
