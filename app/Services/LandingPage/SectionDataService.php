<?php

namespace App\Services\LandingPage;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Parses/validates one landing-page section's `data` payload for every
 * section type (Phase 2's web builder — Tenant\LandingPageController), and
 * cleans up its stored image paths on delete. Extracted so the mobile app's
 * landing-page API (Api\Mobile\LandingPageController) can manage sections
 * through the exact same rules instead of a second copy that could drift —
 * both send the same `data[...]`/`gallery_images[]` multipart field shape,
 * so this works unchanged for either caller.
 */
class SectionDataService
{
    public function parse(Request $request, string $type, array $current): array
    {
        $folder = 'landing-pages/'.app('currentTenant')->id;
        $in = $request->input('data', []);
        $files = $request->file('data', []);

        // A new upload always wins. Otherwise, an explicit `remove_image`
        // flag (checkbox on web, a "ছবি সরান" button on mobile) clears the
        // field — previously there was no way to express "explicitly
        // cleared" at all, so an existing image (e.g. the product
        // thumbnail auto-copied into a fresh hero section) could never be
        // removed, only ever replaced by a new upload.
        $image = function (string $field, ?string $existing) use ($files, $folder, $request) {
            if (isset($files[$field]) && $files[$field]->isValid()) {
                if ($existing) {
                    Storage::disk('public')->delete($existing);
                }

                return $files[$field]->store($folder, 'public');
            }

            if ($existing && $request->boolean('data.remove_image')) {
                Storage::disk('public')->delete($existing);

                return null;
            }

            return $existing;
        };

        return match ($type) {
            'hero' => [
                'headline' => (string) ($in['headline'] ?? ''),
                'subheadline' => (string) ($in['subheadline'] ?? ''),
                'image_path' => $image('image', $current['image_path'] ?? null),
                'video_url' => (string) ($in['video_url'] ?? ''),
                'cta_text' => (string) ($in['cta_text'] ?? 'এখনই অর্ডার করুন'),
            ],
            'media' => [
                'image_path' => $image('image', $current['image_path'] ?? null),
                'video_url' => (string) ($in['video_url'] ?? ''),
            ],
            'benefits' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['title'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'icon' => (string) ($i['icon'] ?? '✅'),
                        'title' => (string) $i['title'],
                        'description' => (string) ($i['description'] ?? ''),
                    ])->values()->all(),
            ],
            'image_text' => [
                'image_path' => $image('image', $current['image_path'] ?? null),
                'heading' => (string) ($in['heading'] ?? ''),
                'description' => (string) ($in['description'] ?? ''),
                'layout' => in_array($in['layout'] ?? '', ['image-left', 'image-right']) ? $in['layout'] : 'image-left',
            ],
            'features' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'description' => (string) ($in['description'] ?? ''),
            ],
            'gallery' => [
                'images' => collect($current['images'] ?? [])
                    ->reject(fn ($path, $i) => $request->boolean("data.remove_image_$i"))
                    ->values()
                    ->concat(
                        collect($request->file('gallery_images', []))
                            ->filter(fn ($f) => $f && $f->isValid())
                            ->map(fn ($f) => $f->store($folder, 'public'))
                    )
                    ->take(8)
                    ->values()
                    ->all(),
            ],
            'reviews' => [
                'heading' => (string) ($in['heading'] ?? ''),
                // filter() keeps original keys — map() runs BEFORE values(),
                // so $idx below still lines up with the request's original
                // item index (files/current are keyed the same way), unlike
                // a post-filter reindex.
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['customer_name'] ?? '') !== '')
                    ->map(function ($i, $idx) use ($files, $folder, $current) {
                        $existingPhoto = $current['items'][$idx]['photo_path'] ?? null;
                        $file = $files['items'][$idx]['photo'] ?? null;

                        return [
                            'customer_name' => (string) $i['customer_name'],
                            'review_text' => (string) ($i['review_text'] ?? ''),
                            'rating' => max(1, min(5, (int) ($i['rating'] ?? 5))),
                            'photo_path' => ($file && $file->isValid()) ? $file->store($folder, 'public') : $existingPhoto,
                        ];
                    })->values()->all(),
            ],
            'video_reviews' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['video_url'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'customer_name' => (string) ($i['customer_name'] ?? ''),
                        'video_url' => (string) $i['video_url'],
                    ])->values()->all(),
            ],
            'cta' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'button_text' => (string) ($in['button_text'] ?? 'এখনই অর্ডার করুন'),
            ],
            'faq' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['question'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'question' => (string) $i['question'],
                        'answer' => (string) ($i['answer'] ?? ''),
                    ])->values()->all(),
            ],
            'checkout' => [
                'heading' => (string) ($in['heading'] ?? 'অর্ডার করুন'),
            ],
            default => $current,
        };
    }

    /** Every stored image path in a section's data — used to clean up files on delete. */
    public function imagePathsIn(array $data): array
    {
        $paths = array_filter([$data['image_path'] ?? null]);

        foreach ($data['images'] ?? [] as $p) {
            $paths[] = $p;
        }

        foreach ($data['items'] ?? [] as $item) {
            if (! empty($item['photo_path'])) {
                $paths[] = $item['photo_path'];
            }
        }

        return $paths;
    }
}
