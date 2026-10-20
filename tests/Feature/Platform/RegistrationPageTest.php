<?php

namespace Tests\Feature\Platform;

use App\Rules\FacebookPageUrl;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithPlatformSchema;
use Tests\TestCase;

/**
 * The compact "List Your Brand" page (Design 4): its copy, the log-in and
 * WhatsApp shortcuts, the optional details, the homepage-style footer, and the
 * Ministry of Commerce line + "Registered" label — shown only while the DBID
 * certificate backing them is published, and never with a government emblem.
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

        $page->assertSeeInOrder(['List Your Brand', 'Ministry of Commerce, Government of Bangladesh', 'Registered', 'Get approved. Share your profile. Collect votes.'])
            ->assertSeeInOrder(['Register in 2 minutes', 'Basic details now, more later from your dashboard.',
                'Get your brand link', 'Once approved, share your profile, get nominated and collect votes.'])
            ->assertDontSee('We review your brand')
            ->assertSee('title="MetaSoft BD is registered under the Digital Business Identity (DBID) Guidelines 2022 with RJSC, Ministry of Commerce. This is a registration, not a government endorsement."', false)
            ->assertSee('Already registered?')
            ->assertSee('href="'.route('owner.login').'" class="inline-flex h-7', false)
            ->assertSee('Add more details (optional)')
            ->assertDontSee('Skip for now')
            ->assertSee('I own or represent this brand, the details are accurate, and I accept MetaSoft BD’s rules: no fake brands, impersonation or vote manipulation.', false)
            ->assertSee('data-bd-network', false);

        // One WhatsApp Support button, from the configured support number.
        $digits = preg_replace('/\D/', '', (string) config('payment.support_whatsapp'));
        $this->assertNotSame('', $digits);
        $page->assertSee('href="https://wa.me/'.$digits.'?text=', false);
        config(['payment.support_whatsapp' => '']);
        $this->get(route('owner.register'))->assertOk()->assertDontSee('wa.me/'.$digits.'?text=', false);
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
            ->assertSee('<details class="group mt-2.5 rounded-lg border border-hair bg-white/70"  open >', false)
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
        $this->assertStringContainsString('text-center text-[13.5px] font-semibold text-gold">'."\n".'            <p>© '.date('Y').' MetaSoft BD · Dhaka, Bangladesh. All rights reserved.</p>', $html);
        // No government emblem / ministry logo image is published.
        $this->assertDoesNotMatchRegularExpression('~<img[^>]+(emblem|ministry|govt|government|bd-seal)~i', $html);

        // Without the published certificate the claim disappears.
        rename(public_path('images/home/certificate.png'), public_path('images/home/certificate.png.bak'));
        try {
            $this->get(route('owner.register'))->assertOk()->assertDontSee('Registered with the Ministry of Commerce')
                ->assertDontSee('Ministry of Commerce, Government of Bangladesh')->assertDontSee('not a government endorsement');
        } finally {
            rename(public_path('images/home/certificate.png.bak'), public_path('images/home/certificate.png'));
        }

        // The compact header is register-only. The directory shares the navy footer but keeps the full header;
        // other platform pages (e.g. owner login) keep their own footer.
        $this->assertStringContainsString('flex h-12 sm:h-14 items-center', $html);
        $this->get(route('brands.index'))->assertOk()->assertSee('<footer class="bg-night text-white">', false)
            ->assertSee('flex h-16 items-center', false)->assertDontSee('h-12 sm:h-14', false);
        $this->get(route('owner.login'))->assertOk()->assertDontSee('<footer class="bg-night text-white">', false);
    }

    public function test_fields_are_compact_with_small_placeholders_and_no_ios_zoom(): void
    {
        $page = $this->get(route('owner.register'))->assertOk();

        // Typed text is 16px on phones (text-base, no iOS zoom), 13px from sm; placeholders are smaller than typed text.
        $page->assertSee('py-[5px] text-base leading-5 placeholder:text-[13px]', false)
            ->assertSee('sm:py-1 sm:text-[13px] sm:leading-[18px] sm:placeholder:text-[12px]', false)
            ->assertDontSee('px-3 py-2 text-base', false)
            ->assertSee('placeholder="facebook.com/page"', false)
            ->assertSee('placeholder="Full name"', false)
            ->assertSee('placeholder="you@email.com"', false)
            ->assertSee('placeholder="01XXXXXXXXX"', false)
            ->assertSee('<button type="submit" class="mt-2.5 h-9 w-full', false);

        // Field names, the Facebook pattern and the required flags are unchanged.
        $page->assertSee('pattern="'.e(FacebookPageUrl::HTML_PATTERN).'"', false)
            ->assertSee('name="terms" value="1"  required', false);
        foreach (['brand_name', 'brand_category_id', 'division', 'district', 'facebook', 'founder_name', 'phone', 'email', 'password'] as $field) {
            $page->assertSee('name="'.$field.'"', false);
        }
    }

    public function test_the_header_shows_the_two_line_brand_name_at_every_width(): void
    {
        foreach ([route('owner.register'), route('brands.index')] as $url) {
            $this->get($url)->assertOk()
                ->assertSeeInOrder(['MetaSoft BD</span>', 'Brand & Entrepreneur Network</span>'], false)
                ->assertSee('<span class="mt-0.5 block whitespace-nowrap text-[7.5px]', false);
        }
    }

    public function test_footer_contact_strip_uses_only_configured_social_links(): void
    {
        $html = $this->get(route('owner.register'))->assertOk()->getContent();
        $strip = Str::after($html, 'data-footer-contact');
        $this->assertStringContainsString('Company credentials', $strip);
        $this->assertStringContainsString('mailto:support@metasoftbd.com', $strip);
        $this->assertStringContainsString('https://wa.me/', $strip);
        $this->assertStringNotContainsString('data-social=', $html);

        config(['platform.social' => ['facebook' => 'https://www.facebook.com/example-official', 'youtube' => 'https://www.youtube.com/@example', 'instagram' => 'http://insecure.example', 'tiktok' => null]]);
        $this->get(route('owner.register'))->assertOk()
            ->assertSeeInOrder(['data-social="left"', 'https://www.facebook.com/example-official', 'aria-label="Contact"', 'data-social="right"', 'https://www.youtube.com/@example'], false)
            ->assertDontSee('insecure.example', false);
    }
}
