@props(['global', 'design' => null, 'id' => null])
@php
    $resolver = app(\App\Services\LandingPage\DesignResolver::class);
    $resolved = $resolver->resolveSection($global, $design);
@endphp
<section
    @if($id) id="{{ $id }}" @endif
    {{ $attributes->merge(['class' => $resolver->sectionClasses($global, $resolved)]) }}
    @if($style = $resolver->sectionStyle($resolved)) style="{{ $style }}" @endif
>
    <div class="{{ $resolver->containerClasses($global, $resolved) }} {{ $resolver->bodyTextClasses($global) }}">
        {{ $slot }}
    </div>
</section>
