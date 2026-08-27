<section class="max-w-2xl mx-auto text-center bg-brand/5 rounded-card p-8">
    @if ($data['heading'] ?? null)
        <h2 class="font-disp font-bold text-2xl">{{ $data['heading'] }}</h2>
    @endif
    <a href="#checkout-section" class="inline-block mt-4 px-10 py-3.5 rounded-btn bg-brand text-white font-bold hover:opacity-90">
        🛒 {{ $data['button_text'] ?? 'এখনই অর্ডার করুন' }}
    </a>
</section>
