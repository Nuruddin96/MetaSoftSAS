<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression guard for a real production incident: deploy.sh only runs
 * `composer install` + rsync — it never runs `npm run build` (node/npm
 * aren't even installed on the shared-hosting production host), so the
 * compiled CSS in public/build/ only ever updates when a developer runs
 * the build locally and commits the result. Several Tailwind utility
 * classes added to Blade templates in an earlier deploy (inset-x-0 on the
 * mobile bottom nav, rounded-3xl on the dashboard tiles, etc.) were never
 * rebuilt into the committed CSS, so production silently served a stale
 * bundle missing those rules — e.g. the mobile nav's "inset-x-0" never took
 * effect, leaving it shrink-to-fit instead of full-width.
 *
 * This doesn't (and can't, from PHPUnit) run vite — it just asserts that
 * whatever CSS is currently committed in public/build/ actually contains
 * the utility classes real templates depend on, so a future "changed a
 * Tailwind class but forgot to rebuild" mistake fails CI instead of only
 * surfacing after a manual production review.
 */
class BuiltAssetsTest extends TestCase
{
    protected function builtCss(): string
    {
        $manifestPath = base_path('public/build/manifest.json');
        $this->assertFileExists($manifestPath, 'public/build/manifest.json is missing — run `npm run build`.');

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $cssFile = $manifest['resources/css/app.css']['file'] ?? null;
        $this->assertNotNull($cssFile, 'app.css entry missing from the Vite manifest.');

        $cssPath = base_path('public/build/'.$cssFile);
        $this->assertFileExists($cssPath, "Built CSS file referenced by the manifest doesn't exist: $cssFile");

        return file_get_contents($cssPath);
    }

    public function test_built_css_is_not_stale_relative_to_storefront_and_panel_templates(): void
    {
        $css = $this->builtCss();

        $mustContain = [
            // Mobile bottom nav full-width fix (storefront + panel).
            'inset-x-0',
            // Tenant dashboard KPI tile radius/shadow pass.
            'rounded-3xl',
            'shadow-sm',
            // Homepage offer/featured product grid.
            'grid-cols-2',
            'line-through',
        ];

        foreach ($mustContain as $class) {
            $this->assertStringContainsString(
                '.'.$class,
                $css,
                "Built CSS is missing `.$class` — the asset bundle looks stale. Run `npm run build` and commit public/build/."
            );
        }
    }
}
