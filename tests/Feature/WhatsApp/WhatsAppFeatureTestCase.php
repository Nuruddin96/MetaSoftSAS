<?php

namespace Tests\Feature\WhatsApp;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithWhatsAppSchema;
use Tests\TestCase;

abstract class WhatsAppFeatureTestCase extends TestCase
{
    use InteractsWithWhatsAppSchema;

    /** Override to false in a test class to simulate chunk26.sql not being imported at all. */
    protected bool $includeWhatsAppTables = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWhatsAppSchema($this->includeWhatsAppTables);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
