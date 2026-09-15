<?php

namespace App\Services\LandingPage;

use App\Models\LandingPage;
use App\Models\Tenant;

/**
 * Turns a landing page's `design` (global) and a section's `data.design`
 * (override) into a fixed set of Tailwind classes + a small handful of CSS
 * custom properties for the few values that genuinely need to be arbitrary
 * (hex colors, an uploaded background image). Every enum below maps to a
 * literal Tailwind class string so nothing here can inject arbitrary CSS —
 * a non-technical tenant can only ever pick from the options the UI shows.
 *
 * Global brand colors default to the tenant's own primary_color/
 * secondary_color (already wired into --color-brand/--color-accent by
 * layouts/store.blade.php) rather than duplicating a second color system —
 * a landing page only carries its own inline override when a tenant
 * explicitly sets one on the Design tab.
 */
class DesignResolver
{
    private const CONTAINER_WIDTH = [
        'narrow' => 'max-w-xl',
        'normal' => 'max-w-2xl',
        'wide' => 'max-w-4xl',
    ];

    private const SECTION_SPACING_DEFAULT = [
        'compact' => 'sm',
        'normal' => 'md',
        'spacious' => 'lg',
    ];

    private const PAD_Y = [
        'none' => 'py-0',
        'sm' => 'py-3',
        'md' => 'py-8',
        'lg' => 'py-14',
        'xl' => 'py-20',
    ];

    private const PAD_X = [
        'none' => 'px-0',
        'sm' => 'px-3',
        'md' => 'px-6',
    ];

    private const RADIUS = [
        'none' => 'rounded-none',
        'md' => 'rounded-card',
        'lg' => 'rounded-3xl',
        'sm' => 'rounded-lg',
        'full' => 'rounded-full',
    ];

    private const SHADOW = [
        'none' => 'shadow-none',
        'sm' => 'shadow-sm',
        'md' => 'shadow-md',
        'lg' => 'shadow-xl',
    ];

    private const BORDER_WIDTH = [
        '0' => 'border-0',
        '1' => 'border',
        '2' => 'border-2',
    ];

    private const HEADING_SIZE = [
        'sm' => 'text-xl md:text-2xl',
        'md' => 'text-2xl md:text-3xl',
        'lg' => 'text-3xl md:text-4xl',
        'xl' => 'text-4xl md:text-5xl',
    ];

    private const BODY_SIZE = [
        'sm' => 'text-sm',
        'md' => 'text-base',
        'lg' => 'text-lg',
    ];

    private const TEXT_ALIGN = [
        'left' => 'text-left',
        'center' => 'text-center',
        'right' => 'text-right',
    ];

    private const FONT_WEIGHT = [
        'normal' => 'font-normal',
        'semibold' => 'font-semibold',
        'bold' => 'font-bold',
    ];

    private const LINE_HEIGHT = [
        'tight' => 'leading-tight',
        'normal' => 'leading-normal',
        'relaxed' => 'leading-relaxed',
    ];

    private const BUTTON_RADIUS = [
        'none' => 'rounded-none',
        'md' => 'rounded-btn',
        'full' => 'rounded-full',
    ];

    private const BUTTON_SIZE = [
        'sm' => 'px-5 py-2 text-sm',
        'md' => 'px-8 py-3 text-base',
        'lg' => 'px-10 py-3.5 text-lg',
    ];

    /** Global design, merged over App\Models\LandingPage::defaultDesign(). Never null. */
    public function resolveGlobal(?array $design, Tenant $tenant): array
    {
        $d = array_replace_recursive(LandingPage::defaultDesign(), $design ?? []);

        $d['brand']['primary_color'] = $this->hex($d['brand']['primary_color']) ?? $tenant->primary_color;
        $d['brand']['secondary_color'] = $this->hex($d['brand']['secondary_color']) ?? $tenant->secondary_color;
        $d['brand']['background_color'] = $this->hex($d['brand']['background_color']);
        $d['brand']['text_color'] = $this->hex($d['brand']['text_color']);

        return $d;
    }

    /**
     * A single section's resolved tokens: global spacing/radius/shadow act
     * as the section's default, a section-level `data.design` overrides
     * only the keys it actually sets (partial override, not replace).
     */
    public function resolveSection(array $global, ?array $sectionDesign): array
    {
        $sd = $sectionDesign ?? [];

        return [
            'layout' => array_merge(['width' => 'boxed', 'align' => 'center', 'stack_mobile' => true], $sd['layout'] ?? []),
            'spacing' => array_merge(['pt' => null, 'pb' => null, 'px' => 'md'], $sd['spacing'] ?? []),
            'typography' => array_merge([
                'heading_size' => 'md', 'body_size' => 'md', 'align' => null,
            ], $sd['typography'] ?? []),
            'colors' => array_merge([
                'bg' => null, 'heading_color' => null, 'text_color' => null,
                'button_color' => null, 'button_text_color' => null,
            ], array_map(fn ($v) => $this->hex($v), $sd['colors'] ?? [])),
            'background' => array_merge(['type' => 'none', 'image_path' => null, 'overlay' => 0, 'gradient_to' => null], $sd['background'] ?? []),
            'border' => array_merge(['width' => '0', 'radius' => $global['global']['border_radius'], 'style' => 'solid'], $sd['border'] ?? []),
            'shadow' => $sd['shadow'] ?? $global['global']['shadow'],
        ];
    }

    /** Outer <section> wrapper classes for x-landing.section. */
    public function sectionClasses(array $global, array $section): string
    {
        $default = self::SECTION_SPACING_DEFAULT[$global['global']['section_spacing']] ?? 'md';
        $pt = $section['spacing']['pt'] ?? $default;
        $pb = $section['spacing']['pb'] ?? $default;

        $classes = [
            str_replace('py-', 'pt-', self::PAD_Y[$pt] ?? self::PAD_Y['md']),
            str_replace('py-', 'pb-', self::PAD_Y[$pb] ?? self::PAD_Y['md']),
        ];

        $classes[] = self::PAD_X[$section['spacing']['px']] ?? self::PAD_X['md'];
        $classes[] = self::SHADOW[$section['shadow']] ?? self::SHADOW['none'];
        $classes[] = self::BORDER_WIDTH[$section['border']['width']] ?? self::BORDER_WIDTH['0'];

        if ($section['border']['width'] !== '0') {
            $classes[] = self::RADIUS[$section['border']['radius']] ?? self::RADIUS['md'];
            $classes[] = $section['border']['style'] === 'dashed' ? 'border-dashed border-ink/20' : 'border-solid border-ink/10';
        }

        return implode(' ', array_filter($classes));
    }

    /** Inner content-width wrapper classes (the max-w-* every section partial used to hardcode). */
    public function containerClasses(array $global, array $section): string
    {
        $width = $section['layout']['width'] === 'full' ? 'max-w-none' : (self::CONTAINER_WIDTH[$global['global']['container_width']] ?? self::CONTAINER_WIDTH['normal']);
        $align = self::TEXT_ALIGN[$section['typography']['align'] ?? $section['layout']['align']] ?? 'text-center';

        return trim("$width mx-auto $align");
    }

    /** CSS custom properties for the handful of values that must stay arbitrary (hex colors, bg image, overlay). */
    public function sectionStyle(array $section): string
    {
        $props = [];

        // Real CSS properties (not custom properties) here so an inline
        // `style` always wins over any hardcoded default utility class a
        // section type carries (e.g. the CTA section's `bg-brand/5` tint)
        // regardless of Tailwind's generated stylesheet order — a class
        // vs. class fight over `bg-*` is order-dependent and unreliable,
        // inline style vs. class never is.
        if ($section['background']['type'] === 'image' && $section['background']['image_path']) {
            $overlay = max(0, min(80, (int) $section['background']['overlay'])) / 100;
            $url = "url('".e(asset('storage/'.$section['background']['image_path']))."')";
            $props['background'] = $overlay > 0
                ? "linear-gradient(rgba(0,0,0,{$overlay}),rgba(0,0,0,{$overlay})), {$url} center/cover no-repeat"
                : "{$url} center/cover no-repeat";
        } elseif ($section['background']['type'] === 'gradient' && $section['colors']['bg']) {
            $to = $section['background']['gradient_to'];
            $props['background'] = $to ? "linear-gradient(135deg, {$section['colors']['bg']}, {$to})" : $section['colors']['bg'];
        } elseif ($section['background']['type'] === 'color' && $section['colors']['bg']) {
            $props['background-color'] = $section['colors']['bg'];
        }

        if ($section['colors']['text_color']) {
            $props['color'] = $section['colors']['text_color'];
        }

        // Custom properties: consumed by headingClasses()/buttonClasses()
        // via Tailwind arbitrary-value classes on elements that carry no
        // other bg/text color class, so there's no cascade-order fight.
        if ($section['colors']['heading_color']) {
            $props['--sec-heading'] = $section['colors']['heading_color'];
        }
        if ($section['colors']['button_color']) {
            $props['--sec-btn-bg'] = $section['colors']['button_color'];
        }
        if ($section['colors']['button_text_color']) {
            $props['--sec-btn-text'] = $section['colors']['button_text_color'];
        }

        return collect($props)->map(fn ($v, $k) => "$k: $v")->implode('; ');
    }

    /**
     * 'display' keeps the existing Bengali serif (--font-disp); 'modern'
     * and 'body' both use the existing sans body face (--font-body) — a
     * third remote Google Font was deliberately not introduced here to
     * avoid a new production font-loading dependency for one enum value.
     */
    public function headingFontClass(array $global): string
    {
        return $global['typography']['heading_font'] === 'display' ? 'font-disp' : 'font-body';
    }

    public function headingClasses(array $section): string
    {
        return trim(implode(' ', [
            self::HEADING_SIZE[$section['typography']['heading_size']] ?? self::HEADING_SIZE['md'],
            $section['colors']['heading_color'] ? 'text-[var(--sec-heading)]' : '',
        ]));
    }

    public function bodyClasses(array $section): string
    {
        return self::BODY_SIZE[$section['typography']['body_size']] ?? self::BODY_SIZE['md'];
    }

    /** Shared CTA/buy-button classes, driven by the global button design tokens. */
    public function buttonClasses(array $global, ?array $sectionColors = null): string
    {
        $style = $global['buttons']['style'];
        $base = match ($style) {
            'outline' => 'border-2 border-[var(--sec-btn-bg,var(--color-brand))] text-[var(--sec-btn-bg,var(--color-brand))] bg-transparent',
            'ghost' => 'bg-transparent text-[var(--sec-btn-bg,var(--color-brand))] hover:bg-[var(--sec-btn-bg,var(--color-brand))]/10',
            default => 'bg-[var(--sec-btn-bg,var(--color-brand))] text-[var(--sec-btn-text,white)]',
        };

        return trim(implode(' ', [
            'inline-block font-bold hover:opacity-90 transition',
            self::BUTTON_RADIUS[$global['buttons']['radius']] ?? self::BUTTON_RADIUS['md'],
            self::BUTTON_SIZE[$global['buttons']['size']] ?? self::BUTTON_SIZE['md'],
            $base,
        ]));
    }

    public function bodyTextClasses(array $global): string
    {
        return trim(implode(' ', [
            self::FONT_WEIGHT[$global['typography']['font_weight']] ?? '',
            self::LINE_HEIGHT[$global['typography']['line_height']] ?? '',
        ]));
    }

    private function hex(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : null;

        return ($v && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) ? $v : null;
    }
}
