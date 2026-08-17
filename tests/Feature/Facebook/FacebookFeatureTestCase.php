<?php

namespace Tests\Feature\Facebook;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\InteractsWithFacebookSchema;
use Tests\TestCase;

abstract class FacebookFeatureTestCase extends TestCase
{
    use InteractsWithFacebookSchema;

    /** Override to false in a test class to simulate chunk23.sql not being imported at all. */
    protected bool $includeFacebookOauthTables = true;

    /** Override to false in a test class to simulate a PARTIAL chunk23.sql import (tables exist, trailing ALTER TABLE column does not). */
    protected bool $includeFacebookPageIdColumn = true;

    /** Override to false in a test class to simulate database/sql/chunk25.sql not being imported yet. */
    protected bool $includeAttachmentColumns = true;

    /** Override to false in a test class to simulate database/sql/chunk28.sql (messenger_customers) not being imported yet. */
    protected bool $includeMessengerCustomersTable = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFacebookSchema($this->includeFacebookOauthTables, $this->includeFacebookPageIdColumn, $this->includeAttachmentColumns, $this->includeMessengerCustomersTable);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
