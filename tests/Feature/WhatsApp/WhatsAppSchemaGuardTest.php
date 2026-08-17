<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppPhoneNumber;

/**
 * Simulates database/sql/chunk26.sql not having been imported yet — none of
 * the four WhatsApp tables exist, exactly like a production environment
 * where this code was deployed before the schema migration. Mirrors
 * tests/Feature/Facebook/SchemaGuardTest.php's role for the Facebook OAuth
 * tables.
 */
class WhatsAppSchemaGuardTest extends WhatsAppFeatureTestCase
{
    protected bool $includeWhatsAppTables = false;

    public function test_tables_ready_reports_false_when_tables_are_missing(): void
    {
        $this->assertFalse(WhatsAppPhoneNumber::tablesReady());
    }
}
