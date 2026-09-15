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
        $design = $this->parseDesign($in['design'] ?? [], $current['design'] ?? [], $request, $files, $folder);

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
                'layout' => $this->enum($in['layout'] ?? null, ['centered', 'split', 'full_bg'], 'centered'),
                'design' => $design,
            ],
            'media' => [
                'image_path' => $image('image', $current['image_path'] ?? null),
                'video_url' => (string) ($in['video_url'] ?? ''),
                'design' => $design,
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
                'design' => $design,
            ],
            'image_text' => [
                'image_path' => $image('image', $current['image_path'] ?? null),
                'heading' => (string) ($in['heading'] ?? ''),
                'description' => (string) ($in['description'] ?? ''),
                'layout' => in_array($in['layout'] ?? '', ['image-left', 'image-right']) ? $in['layout'] : 'image-left',
                'design' => $design,
            ],
            'features' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'description' => (string) ($in['description'] ?? ''),
                'design' => $design,
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
                'design' => $design,
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
                'design' => $design,
            ],
            'video_reviews' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['video_url'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'customer_name' => (string) ($i['customer_name'] ?? ''),
                        'video_url' => (string) $i['video_url'],
                    ])->values()->all(),
                'design' => $design,
            ],
            'cta' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'button_text' => (string) ($in['button_text'] ?? 'এখনই অর্ডার করুন'),
                'design' => $design,
            ],
            'faq' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['question'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'question' => (string) $i['question'],
                        'answer' => (string) ($i['answer'] ?? ''),
                    ])->values()->all(),
                'design' => $design,
            ],
            'checkout' => [
                'heading' => (string) ($in['heading'] ?? 'অর্ডার করুন'),
                'design' => $design,
            ],
            'trust_badges' => [
                'items' => collect($in['items'] ?? [])
                    ->filter(fn ($i) => trim($i['label'] ?? '') !== '')
                    ->map(fn ($i) => [
                        'icon' => (string) ($i['icon'] ?? '✅'),
                        'label' => (string) $i['label'],
                    ])->take(6)->values()->all(),
                'design' => $design,
            ],
            'countdown' => [
                'heading' => (string) ($in['heading'] ?? 'অফারটি শেষ হচ্ছে'),
                'end_at' => (string) ($in['end_at'] ?? ''),
                'expired_text' => (string) ($in['expired_text'] ?? 'অফারটি শেষ হয়ে গেছে'),
                'design' => $design,
            ],
            'announcement_bar' => [
                'text' => (string) ($in['text'] ?? ''),
                'link_url' => (string) ($in['link_url'] ?? ''),
                'dismissible' => $request->boolean('data.dismissible', true),
                'design' => $design,
            ],
            'delivery_info' => [
                'heading' => (string) ($in['heading'] ?? 'ডেলিভারি তথ্য'),
                'note' => (string) ($in['note'] ?? ''),
                'eta_text' => (string) ($in['eta_text'] ?? ''),
                'design' => $design,
            ],
            'rich_text' => [
                'heading' => (string) ($in['heading'] ?? ''),
                'body' => (string) ($in['body'] ?? ''),
                'design' => $design,
            ],
            default => array_merge($current, ['design' => $design]),
        };
    }

    /**
     * Every section type funnels its optional `data[design][...]` through
     * here so Phase 2's design controls are defined once, not per type.
     * Every value is an enum checked against a fixed allow-list (never raw
     * CSS) except hex colors (regex-validated) and an optional background
     * image upload, reusing the same folder/current-path pattern as the
     * content image() helper above.
     */
    public function parseDesign(array $in, array $current, Request $request, array $files, string $folder): array
    {
        $hex = fn ($v) => (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', trim($v))) ? trim($v) : null;

        $bgType = $this->enum($in['background']['type'] ?? null, ['none', 'color', 'image', 'gradient'], 'none');
        $currentBgImage = $current['background']['image_path'] ?? null;
        $bgFile = $files['design']['background']['image'] ?? null;

        if ($bgFile && $bgFile->isValid()) {
            if ($currentBgImage) {
                Storage::disk('public')->delete($currentBgImage);
            }
            $bgImagePath = $bgFile->store($folder, 'public');
        } elseif ($request->boolean('data.design.background.remove_image')) {
            if ($currentBgImage) {
                Storage::disk('public')->delete($currentBgImage);
            }
            $bgImagePath = null;
        } else {
            $bgImagePath = $currentBgImage;
        }

        return [
            'layout' => [
                'width' => $this->enum($in['layout']['width'] ?? null, ['boxed', 'full'], 'boxed'),
                'align' => $this->enum($in['layout']['align'] ?? null, ['left', 'center', 'right'], 'center'),
                'stack_mobile' => $request->boolean('data.design.layout.stack_mobile', true),
            ],
            'spacing' => [
                'pt' => $this->enum($in['spacing']['pt'] ?? null, ['none', 'sm', 'md', 'lg', 'xl'], null, allowNull: true),
                'pb' => $this->enum($in['spacing']['pb'] ?? null, ['none', 'sm', 'md', 'lg', 'xl'], null, allowNull: true),
                'px' => $this->enum($in['spacing']['px'] ?? null, ['none', 'sm', 'md'], 'md'),
            ],
            'typography' => [
                'heading_size' => $this->enum($in['typography']['heading_size'] ?? null, ['sm', 'md', 'lg', 'xl'], 'md'),
                'body_size' => $this->enum($in['typography']['body_size'] ?? null, ['sm', 'md', 'lg'], 'md'),
                'align' => $this->enum($in['typography']['align'] ?? null, ['left', 'center', 'right'], null, allowNull: true),
            ],
            // heading_color/text_color/button_color/button_text_color each need an
            // explicit "use custom color" checkbox — unlike the <select>-driven
            // background type, a plain <input type=color> can never itself express
            // "no override", so without a paired _enabled flag a tenant could turn
            // a color on but never truly turn it back off.
            'colors' => [
                'bg' => $hex($in['colors']['bg'] ?? null),
                'heading_color' => $request->boolean('data.design.colors.heading_color_enabled') ? $hex($in['colors']['heading_color'] ?? null) : null,
                'text_color' => $request->boolean('data.design.colors.text_color_enabled') ? $hex($in['colors']['text_color'] ?? null) : null,
                'button_color' => $request->boolean('data.design.colors.button_color_enabled') ? $hex($in['colors']['button_color'] ?? null) : null,
                'button_text_color' => $request->boolean('data.design.colors.button_text_color_enabled') ? $hex($in['colors']['button_text_color'] ?? null) : null,
            ],
            'background' => [
                'type' => $bgType,
                'image_path' => $bgImagePath,
                'overlay' => max(0, min(80, (int) ($in['background']['overlay'] ?? 0))),
                'gradient_to' => $hex($in['background']['gradient_to'] ?? null),
            ],
            'border' => [
                'width' => $this->enum($in['border']['width'] ?? null, ['0', '1', '2'], '0'),
                'radius' => $this->enum($in['border']['radius'] ?? null, ['none', 'sm', 'md', 'lg', 'full'], 'md'),
                'style' => $this->enum($in['border']['style'] ?? null, ['solid', 'dashed'], 'solid'),
            ],
            'shadow' => $this->enum($in['shadow'] ?? null, ['none', 'sm', 'md', 'lg'], null, allowNull: true),
        ];
    }

    private function enum(mixed $value, array $allowed, ?string $default, bool $allowNull = false): ?string
    {
        if ($value === null || $value === '') {
            return $allowNull ? null : $default;
        }

        return in_array($value, $allowed, true) ? $value : $default;
    }

    /** Every stored image path in a section's data — used to clean up files on delete. */
    public function imagePathsIn(array $data): array
    {
        $paths = array_filter([$data['image_path'] ?? null, $data['design']['background']['image_path'] ?? null]);

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
