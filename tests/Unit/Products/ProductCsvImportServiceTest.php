<?php

namespace Tests\Unit\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithApiSchema;
use Tests\TestCase;
use App\Services\Products\ProductCsvImportService;

/**
 * CSV product import task: ProductCsvImportService is the extracted
 * business logic (Tenant\ProductImportController::store()'s real behavior,
 * unchanged) both the web and the new mobile controller call. Rows sharing
 * a product name become variants of one product, category auto-creates,
 * and the tenant's max_products plan limit is enforced mid-import.
 */
class ProductCsvImportServiceTest extends TestCase
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

    private function csvHandle(string $csv)
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        return $handle;
    }

    private function bindTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    public function test_a_single_row_creates_one_product_with_a_default_variant_and_stock(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);
        Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);

        $csv = "name,category,variant,purchase_price,selling_price,stock,description\n".
            "Leather Wallet,Accessories,,450,790,30,A fine wallet\n";

        $result = app(ProductCsvImportService::class)->import($this->csvHandle($csv));

        $this->assertSame(['created' => 1, 'skipped' => 0, 'errors' => []], $result);
        $product = Product::where('tenant_id', $tenant->id)->where('name', 'Leather Wallet')->first();
        $this->assertNotNull($product);
        $this->assertSame('Accessories', $product->category->name);
        $variant = $product->variants()->first();
        $this->assertSame('Default', $variant->variant_name);
        $this->assertSame('30', (string) $variant->inventory()->sum('quantity'));
    }

    public function test_rows_sharing_a_product_name_become_variants_of_one_product(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);
        Warehouse::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'is_default' => 1]);

        $csv = "name,variant,selling_price\n".
            "Cotton Panjabi,Navy / L,1250\n".
            "Cotton Panjabi,Navy / XL,1250\n";

        $result = app(ProductCsvImportService::class)->import($this->csvHandle($csv));

        $this->assertSame(1, $result['created']);
        $product = Product::where('tenant_id', $tenant->id)->where('name', 'Cotton Panjabi')->first();
        $this->assertSame(2, $product->variants()->count());
        $this->assertTrue((bool) $product->fresh()->has_variants);
    }

    public function test_a_row_missing_name_or_selling_price_is_skipped_with_an_error(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $csv = "name,selling_price\n".
            ",100\n".
            "Valid Product,not-a-number\n";

        $result = app(ProductCsvImportService::class)->import($this->csvHandle($csv));

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['skipped']);
        $this->assertCount(2, $result['errors']);
    }

    public function test_import_stops_creating_new_products_once_the_plan_limit_is_reached(): void
    {
        $tenant = $this->makeTenant();
        $plan = \App\Models\Plan::create(['name' => 'Limited', 'slug' => 'limited-'.uniqid(), 'max_products' => 1]);
        $tenant->update(['plan_id' => $plan->id]);
        $tenant = $tenant->fresh();
        $this->bindTenant($tenant);

        $csv = "name,selling_price\n".
            "Product A,100\n".
            "Product B,200\n";

        $result = app(ProductCsvImportService::class)->import($this->csvHandle($csv));

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('লিমিট', $result['errors'][0]);
    }

    public function test_an_empty_csv_throws(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $this->expectException(\RuntimeException::class);

        app(ProductCsvImportService::class)->import($this->csvHandle(''));
    }

    public function test_a_csv_missing_a_required_column_throws(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $this->expectException(\RuntimeException::class);

        app(ProductCsvImportService::class)->import($this->csvHandle("name,stock\nWidget,5\n"));
    }

    public function test_blank_rows_are_silently_skipped_without_counting_as_an_error(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);

        $csv = "name,selling_price\n".
            "Widget,100\n".
            ",\n".
            "Gadget,200\n";

        $result = app(ProductCsvImportService::class)->import($this->csvHandle($csv));

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['skipped']);
    }

    public function test_reusing_an_existing_category_name_does_not_create_a_duplicate(): void
    {
        $tenant = $this->makeTenant();
        $this->bindTenant($tenant);
        $existing = Category::create(['tenant_id' => $tenant->id, 'name' => 'Accessories', 'slug' => 'accessories-existing']);

        $csv = "name,category,selling_price\nWallet,Accessories,500\n";
        app(ProductCsvImportService::class)->import($this->csvHandle($csv));

        $this->assertSame(1, Category::where('tenant_id', $tenant->id)->where('name', 'Accessories')->count());
        $product = Product::where('tenant_id', $tenant->id)->where('name', 'Wallet')->first();
        $this->assertSame($existing->id, $product->category_id);
    }
}
