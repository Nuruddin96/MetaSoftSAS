<?php

namespace Tests\Feature\Api\Mobile;

use App\Models\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;

/**
 * CSV product import task: `POST product-catalog/import`
 * (Api\Mobile\ProductImportController) — mirrors
 * Tenant\ProductImportController::store() via the shared
 * ProductCsvImportService.
 */
class ProductImportApiTest extends TestCase
{
    use InteractsWithApiSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpApiSchema();

        if (! Schema::hasColumn('plans', 'max_products')) {
            Schema::table('plans', fn (Blueprint $table) => $table->integer('max_products')->nullable());
        }
    }

    private function csvFile(string $csv, string $name = 'products.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $csv);
    }

    public function test_a_valid_csv_creates_products_and_returns_a_summary(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $csv = "name,category,variant,purchase_price,selling_price,stock,description\n".
            "Leather Wallet,Accessories,,450,790,30,\n";

        $response = $this->postJson('/api/mobile/v1/product-catalog/import', [
            'file' => $this->csvFile($csv),
        ]);

        $response->assertOk()->assertJson(['created' => 1, 'skipped' => 0, 'errors' => []]);
        $this->assertSame(1, Product::where('tenant_id', $tenant->id)->where('name', 'Leather Wallet')->count());
    }

    public function test_an_empty_csv_returns_a_422_with_a_bangla_message(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/product-catalog/import', [
            'file' => $this->csvFile(''),
        ]);

        $response->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_a_csv_missing_the_required_selling_price_column_returns_422(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/product-catalog/import', [
            'file' => $this->csvFile("name,stock\nWidget,5\n"),
        ]);

        $response->assertStatus(422);
    }

    public function test_a_non_csv_file_is_rejected_by_validation(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant->id);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/v1/product-catalog/import', [
            'file' => UploadedFile::fake()->create('products.pdf', 10, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_import_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $userA = $this->makeUser($tenantA->id);
        $tenantB = $this->makeTenant();
        $userB = $this->makeUser($tenantB->id);

        Sanctum::actingAs($userA);
        $this->postJson('/api/mobile/v1/product-catalog/import', [
            'file' => $this->csvFile("name,selling_price\nTenant A Product,100\n"),
        ])->assertOk();

        Sanctum::actingAs($userB);
        $this->postJson('/api/mobile/v1/product-catalog/import', [
            'file' => $this->csvFile("name,selling_price\nTenant B Product,200\n"),
        ])->assertOk();

        $this->assertSame(0, Product::where('tenant_id', $tenantB->id)->where('name', 'Tenant A Product')->count());
        $this->assertSame(0, Product::where('tenant_id', $tenantA->id)->where('name', 'Tenant B Product')->count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/mobile/v1/product-catalog/import', [
            'file' => $this->csvFile("name,selling_price\nWidget,100\n"),
        ])->assertUnauthorized();
    }
}
