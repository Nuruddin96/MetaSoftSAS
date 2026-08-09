<?php

namespace Tests\Feature\Facebook;

use App\Models\Tenant;

/**
 * Regression test for a live-production bug found while testing the
 * Facebook "Connect Facebook" flow end-to-end: layouts/panel.blade.php's
 * flash-message script called showToast() directly from a synchronous
 * inline <script>, but showToast() is defined inside the Vite-bundled
 * resources/js/app.js module — type="module" scripts always execute AFTER
 * any synchronous inline script positioned earlier in the document (HTML
 * spec, not timing-dependent), so the call threw "ReferenceError: showToast
 * is not defined" on every single panel page load carrying a flash message,
 * silently swallowing it.
 *
 * Fixed by queuing the flash data via a plain assignment
 * (window.__flashMessages) instead of calling the function directly, then
 * draining that queue from inside app.js itself, right after showToast is
 * defined — deterministically correct regardless of module-load timing.
 */
class PanelFlashMessageTest extends FacebookFeatureTestCase
{
    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_error_flash_is_queued_for_the_bundled_module_not_called_directly(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->withSession(['error' => 'Test error message'])
            ->get($this->panelUrl($tenant, 'settings'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('window.__flashMessages', $html);
        $this->assertStringContainsString('Test error message', $html);
        $this->assertStringContainsString('error', $html);

        // The old bug: showToast() called directly from the layout's own
        // inline script, racing the module that defines it. Must never
        // regress to calling it inline again from the layout itself —
        // only app.js (the bundled module) may call it.
        $this->assertStringNotContainsString('showToast(', $html);
        $this->assertMatchesRegularExpression('/<script>\s*lucide\.createIcons\(\);\s*window\.__flashMessages/', $html);
    }

    public function test_success_flash_is_queued_correctly(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')
            ->withSession(['success' => 'Test success message'])
            ->get($this->panelUrl($tenant, 'settings'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('window.__flashMessages', $html);
        $this->assertStringContainsString('Test success message', $html);
    }

    public function test_no_flash_messages_produces_an_empty_queue_not_an_error(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get($this->panelUrl($tenant, 'settings'));

        $response->assertOk();
        $this->assertStringContainsString('window.__flashMessages = []', $response->getContent());
    }
}
