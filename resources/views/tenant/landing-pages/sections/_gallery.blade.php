@php $images = $data['images'] ?? []; @endphp

@if (count($images))
    <div>
        <label class="text-sm font-medium">বর্তমান ছবি — মুছে ফেলতে টিক দিন</label>
        <div class="mt-2 grid grid-cols-4 gap-2">
            @foreach ($images as $i => $path)
                <label class="relative cursor-pointer">
                    <img src="{{ asset('storage/' . $path) }}" class="w-full aspect-square rounded-btn object-cover border border-ink/10">
                    <input type="checkbox" name="data[remove_image_{{ $i }}]" value="1" class="absolute top-1 right-1 w-5 h-5">
                </label>
            @endforeach
        </div>
    </div>
@endif

<div>
    <label class="text-sm font-medium">নতুন ছবি যোগ করুন (একসাথে একাধিক)</label>
    <input type="file" name="gallery_images[]" accept="image/*" multiple class="mt-1 w-full text-sm">
    <p class="text-xs text-mute mt-1">সর্বোচ্চ ৮টি ছবি রাখা যাবে</p>
</div>

@include('tenant.landing-pages.partials._design-fields', ['data' => $data])
