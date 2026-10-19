<?php

namespace Tests\Feature\Platform;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * The compact "List your brand" page: its copy, the log-in shortcut, the
 * optional details, the homepage-style footer, and the Ministry of Commerce
 * line — shown only while the DBID certificate backing it is published, and
 * never with a government emblem.
 */
class RegistrationPageTest extends TestCase
{
    use InteractsWithPlatformSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatformSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->withoutVite();
        $this->makePlatformCategory();
    }

    public function test_the_page_shows_the_new_copy_steps_and_log_in_button(): void
    {
        $page = $this->get(route('owner.register'))->assertOk();

        $page->assertSeeInOrder(['Free for every Bangladeshi brand', 'List your brand on MetaSoft BD', 'আপনার ব্র্যান্ডের স্থায়ী ডিজিটাল পরিচয় তৈরি করুন।'])
            ->assertSeeInOrder(['Register in 2 minutes', 'Basic details now. Add more later.',
                'We review your brand', 'Usually within 24 hours. Track status from your dashboard.',
                'Get your brand link', 'Share your profile, get nominated, and collect votes.'])
            ->assertSee('Already registered?')
            ->assertSee('href="'.route('owner.login').'" class="inline-flex h-9', false)
            ->assertSee('Add more details (optional)')
            ->assertSee('Skip for now. Add details later from your dashboard.')
            ->assertSee("I confirm these brand details are accurate and agree to MetaSoft BD's rules. No fake brands, impersonation, or vote manipulation.", false)
            ->assertDontSee('I own or represent this brand');
        $this->assertSame('/brand-owner/login', parse_url(route('owner.login'), PHP_URL_PATH));

        // Every field is still there, optional ones included, and the agreement is still required.
        foreach (['logo', 'brand_name', 'brand_category_id', 'division', 'district', 'facebook', 'founder_name', 'phone', 'email', 'password', 'password_confirmation', 'sub_category', 'website', 'instagram', 'description'] as $field) {
            $page->assertSee('name="'.$field.'"', false);
        }
        $page->assertSee('name="terms" value="1"  required', false);
    }

    public function test_entered_optional_details_reopen_after_a_validation_error(): void
    {
        $this->from(route('owner.register'))->post(route('owner.register.store'), ['website' => 'https://example.com', 'description' => 'Handloom sarees'])
            ->assertRedirect(route('owner.register'))->assertSessionHasErrors('terms');

        $this->get(route('owner.register'))->assertOk()
            ->assertSee('<details class="group mt-5 rounded-xl border border-hair"  open >', false)
            ->assertSee('value="https://example.com"', false)
            ->assertSee('Handloom sarees');
    }

    public function test_footer_matches_the_homepage_and_the_ministry_line_needs_the_certificate(): void
    {
        $html = $this->get(route('owner.register'))->assertOk()->getContent();
        $this->assertStringContainsString('<footer class="bg-night text-white">', $html);
        $this->assertStringContainsString('Brand & Entrepreneur Network', $html);
        $this->assertStringContainsString('Registered with the Ministry of Commerce, Bangladesh', $html);
        $this->assertStringContainsString('href="'.url('/').'#credentials"', $html);
        // No government emblem / ministry logo image is published.
        $this->assertDoesNotMatchRegularExpression('~<img[^>]+(emblem|ministry|govt|government|bd-seal)~i', $html);

        // Without the published certificate the claim disappears.
        rename(public_path('images/home/certificate.png'), public_path('images/home/certificate.png.bak'));
        try {
            $this->get(route('owner.register'))->assertOk()->assertDontSee('Registered with the Ministry of Commerce');
        } finally {
            rename(public_path('images/home/certificate.png.bak'), public_path('images/home/certificate.png'));
        }

        // Other platform pages keep their own footer.
        $this->get(route('brands.index'))->assertOk()->assertDontSee('<footer class="bg-night text-white">', false);
    }
}
