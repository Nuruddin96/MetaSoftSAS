<?php

namespace Tests\Feature\Inbox;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithInboxSchema;
use Tests\TestCase;

abstract class InboxFeatureTestCase extends TestCase
{
    use InteractsWithInboxSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInboxSchema();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    protected function panelUrl(\App\Models\Tenant $tenant, string $path): string
    {
        return '/shop/'.$tenant->subdomain.'/panel/'.$path;
    }
}
