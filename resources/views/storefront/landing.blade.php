@extends('layouts.store')

@section('title', $landingPage->title . ' — ' . $tenant->store_name)
@section('meta_description', \Illuminate\Support\Str::limit(strip_tags((string) $product->description), 155) ?: $landingPage->title)

@php
    $globalDesign = app(\App\Services\LandingPage\DesignResolver::class)->resolveGlobal($landingPage->design, $tenant);
@endphp

@section('content')
<div class="pb-24 md:pb-10">
    @foreach ($landingPage->sections ?? [] as $section)
        @continue($section['hidden'] ?? false)
        @includeIf('storefront.landing.sections.' . $section['type'], [
            'data' => $section['data'] ?? [],
            'product' => $product,
            'landingPage' => $landingPage,
            'global' => $globalDesign,
            'divisions' => $divisions,
            'districts' => $districts,
            'chargeInside' => $chargeInside,
            'chargeOutside' => $chargeOutside,
            'dhakaDivisionId' => $dhakaDivisionId,
        ])
    @endforeach
</div>

{{-- Mobile sticky CTA — always available while scrolling, most traffic here is from Facebook/Messenger on mobile --}}
<div class="md:hidden fixed bottom-0 inset-x-0 z-40 p-3 bg-white/95 backdrop-blur border-t border-ink/10">
    <a href="#checkout-section" class="block w-full text-center px-6 py-3.5 rounded-btn bg-brand text-white font-bold">
        🛒 এখনই অর্ডার করুন
    </a>
</div>

@push('scripts')
<script>
    if (typeof fbq === 'function') {
        fbq('track', 'ViewContent', {
            content_name: @json($product->name),
            content_type: 'product',
            currency: 'BDT',
            value: {{ (float) ($product->variants->first()->selling_price ?? 0) }},
        });
    }

    let __initiateCheckoutSent = false;
    document.getElementById('landingCheckoutForm')?.addEventListener('focusin', () => {
        if (__initiateCheckoutSent) return;
        __initiateCheckoutSent = true;
        if (typeof fbq === 'function') {
            fbq('track', 'InitiateCheckout', { currency: 'BDT', value: {{ (float) ($product->variants->first()->selling_price ?? 0) }} });
        }
    });
</script>
@endpush
@endsection
