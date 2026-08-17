<?php

namespace Tests\Feature\Tenant;

use App\Models\Page;
use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * "Page Header" (database/sql/chunk43.sql, Tenant\WebsiteController::
 * storePage()/updatePage()) — a distinct on-page <h1> heading, separate
 * from `title` (which stays the nav-link label). Blank/unset falls back
 * to `title` — see resources/views/storefront/page.blade.php.
 */
class PageHeaderTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }

    public function test_a_tenant_can_set_a_page_header_when_creating_a_page(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->post($this->panelUrl($tenant, 'website/page'), [
            'title' => 'যোগাযোগ',
            'page_header' => 'আমাদের সাথে যোগাযোগ করুন',
            'content' => 'ফোন করুন অথবা মেসেজ দিন।',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('pages', [
            'tenant_id' => $tenant->id,
            'title' => 'যোগাযোগ',
            'page_header' => 'আমাদের সাথে যোগাযোগ করুন',
        ]);
    }

    public function test_a_tenant_can_update_the_page_header(): void
    {
        $tenant = $this->makeTenant();
        $page = Page::create(['tenant_id' => $tenant->id, 'title' => 'নীতিমালা', 'slug' => 'nitimala', 'is_active' => 1]);
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->put($this->panelUrl($tenant, "website/page/{$page->id}"), [
            'title' => 'নীতিমালা',
            'page_header' => 'রিটার্ন ও রিফান্ড নীতিমালা',
            'content' => $page->content,
        ]);

        $response->assertRedirect();
        $this->assertSame('রিটার্ন ও রিফান্ড নীতিমালা', $page->fresh()->page_header);
    }

    public function test_storefront_renders_the_page_header_when_set(): void
    {
        $tenant = $this->makeTenant();
        Page::create([
            'tenant_id' => $tenant->id, 'title' => 'যোগাযোগ', 'slug' => 'jogajog',
            'page_header' => 'আমাদের সাথে যোগাযোগ করুন', 'content' => 'বিস্তারিত তথ্য এখানে।',
            'is_active' => 1, 'show_in_footer' => 1,
        ]);

        $response = $this->get('/shop/'.$tenant->subdomain.'/page/jogajog');

        $response->assertOk();
        $response->assertSee('আমাদের সাথে যোগাযোগ করুন');
    }

    /** Blank/unset page_header must not break existing pages — falls back to `title`. */
    public function test_storefront_falls_back_to_title_when_no_page_header_is_set(): void
    {
        $tenant = $this->makeTenant();
        Page::create([
            'tenant_id' => $tenant->id, 'title' => 'পুরাতন পেজ', 'slug' => 'purato-page',
            'page_header' => null, 'content' => 'কিছু লেখা।', 'is_active' => 1, 'show_in_footer' => 1,
        ]);

        $response = $this->get('/shop/'.$tenant->subdomain.'/page/purato-page');

        $response->assertOk();
        $response->assertSee('পুরাতন পেজ');
    }

    public function test_a_tenant_cannot_edit_another_tenants_page_header(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);
        $pageA = Page::create(['tenant_id' => $tenantA->id, 'title' => 'A Page', 'slug' => 'a-page', 'is_active' => 1]);

        $response = $this->actingAs($userB, 'tenant')->put($this->panelUrl($tenantB, "website/page/{$pageA->id}"), [
            'title' => 'Hijacked',
            'page_header' => 'Hijacked Header',
        ]);

        $response->assertNotFound();
        $this->assertNull($pageA->fresh()->page_header);
    }

    public function test_another_tenants_page_header_never_leaks_onto_this_tenants_storefront(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        Page::create([
            'tenant_id' => $tenantA->id, 'title' => 'Same Slug', 'slug' => 'same-slug',
            'page_header' => 'TENANT-A-SECRET-HEADER', 'is_active' => 1, 'show_in_footer' => 1,
        ]);
        Page::create([
            'tenant_id' => $tenantB->id, 'title' => 'Same Slug', 'slug' => 'same-slug',
            'page_header' => 'Tenant B Own Header', 'is_active' => 1, 'show_in_footer' => 1,
        ]);

        $response = $this->get('/shop/'.$tenantB->subdomain.'/page/same-slug');

        $response->assertOk();
        $response->assertSee('Tenant B Own Header');
        $response->assertDontSee('TENANT-A-SECRET-HEADER');
    }
}
