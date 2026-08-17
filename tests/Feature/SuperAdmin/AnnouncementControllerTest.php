<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\PlatformAnnouncement;
use App\Models\SuperAdmin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithCommerceSchema;
use Tests\TestCase;

/**
 * "Tenant Announcement" — a single GLOBAL message (App\Models\
 * PlatformAnnouncement, database/sql/chunk46.sql), Super-Admin-only,
 * shown on every Tenant Dashboard. Confirms it is genuinely global (not
 * duplicated per tenant) and that tenants can never edit it.
 * InteractsWithCommerceSchema (not the lighter AI-agent trait) is needed
 * here specifically to render the real Dashboard page, which queries
 * customers/expenses/courier_settings/bd_districts.
 */
class AnnouncementControllerTest extends TestCase
{
    use InteractsWithCommerceSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCommerceSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);

        if (! Schema::hasTable('super_admins')) {
            Schema::create('super_admins', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('email', 150)->unique();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('platform_announcements')) {
            Schema::create('platform_announcements', function (Blueprint $table) {
                $table->id();
                $table->text('message');
                $table->timestamps();
            });
        }
    }

    protected function makeSuperAdmin(): SuperAdmin
    {
        return SuperAdmin::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
    }

    public function test_current_is_empty_when_nothing_is_set(): void
    {
        $this->assertSame('', PlatformAnnouncement::current());
    }

    public function test_super_admin_can_save_an_announcement(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin, 'super_admin')->post(route('super.announcement.update'), [
            'message' => 'শুক্রবার রাতে মেইনটেন্যান্স হবে।',
        ]);

        $response->assertRedirect();
        $this->assertSame('শুক্রবার রাতে মেইনটেন্যান্স হবে।', PlatformAnnouncement::current());
    }

    public function test_saving_again_replaces_the_single_announcement_not_creates_a_second_one(): void
    {
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')->post(route('super.announcement.update'), ['message' => 'First'])->assertRedirect();
        $this->actingAs($admin, 'super_admin')->post(route('super.announcement.update'), ['message' => 'Second'])->assertRedirect();

        $this->assertSame(1, PlatformAnnouncement::count());
        $this->assertSame('Second', PlatformAnnouncement::current());
    }

    public function test_super_admin_can_delete_the_announcement(): void
    {
        PlatformAnnouncement::create(['message' => 'Existing']);
        $admin = $this->makeSuperAdmin();

        $this->actingAs($admin, 'super_admin')->delete(route('super.announcement.destroy'))->assertRedirect();

        $this->assertSame('', PlatformAnnouncement::current());
    }

    public function test_guest_cannot_save_an_announcement(): void
    {
        $this->post(route('super.announcement.update'), ['message' => 'Hacked'])->assertRedirect();

        $this->assertSame('', PlatformAnnouncement::current());
    }

    public function test_it_is_genuinely_global_the_same_message_appears_for_every_tenant(): void
    {
        PlatformAnnouncement::create(['message' => 'সবার জন্য একই ঘোষণা']);
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $userB = $this->makeUser($tenantB->id);

        $responseA = $this->actingAs($userA, 'tenant')->get('/shop/'.$tenantA->subdomain.'/panel');
        $responseB = $this->actingAs($userB, 'tenant')->get('/shop/'.$tenantB->subdomain.'/panel');

        $responseA->assertOk()->assertSee('সবার জন্য একই ঘোষণা');
        $responseB->assertOk()->assertSee('সবার জন্য একই ঘোষণা');
    }

    public function test_dashboard_renders_nothing_when_announcement_is_empty(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);

        $response = $this->actingAs($user, 'tenant')->get('/shop/'.$tenant->subdomain.'/panel');

        $response->assertOk();
        $response->assertDontSee('megaphone');
    }
}
