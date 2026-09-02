<?php

namespace Tests\Feature\WordPress;

use App\Models\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithWordPressSchema;
use Tests\TestCase;

abstract class WordPressFeatureTestCase extends TestCase
{
    use InteractsWithWordPressSchema;

    /** Override to false in a test class to simulate chunk59.sql not being imported at all. */
    protected bool $includeWordPressTables = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWordPressSchema($this->includeWordPressTables);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }
}
