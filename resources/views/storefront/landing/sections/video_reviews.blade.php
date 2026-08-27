@if (!empty($data['items']))
    <section class="max-w-2xl mx-auto">
        @if ($data['heading'] ?? null)
            <h2 class="font-disp font-bold text-2xl text-center mb-6">{{ $data['heading'] }}</h2>
        @endif
        <div class="grid sm:grid-cols-2 gap-4">
            @foreach ($data['items'] as $item)
                @php $embed = \App\Support\VideoEmbed::url($item['video_url']); @endphp
                <div class="rounded-card overflow-hidden bg-white border border-ink/5">
                    <div class="aspect-video">
                        @if ($embed)
                            <iframe src="{{ $embed }}" class="w-full h-full" allowfullscreen loading="lazy"></iframe>
                        @else
                            <a href="{{ $item['video_url'] }}" target="_blank" class="w-full h-full grid place-items-center bg-paper text-brand font-semibold text-sm">▶ ভিডিও দেখুন</a>
                        @endif
                    </div>
                    @if ($item['customer_name'] ?? null)
                        <p class="text-sm font-semibold p-3">{{ $item['customer_name'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endif
