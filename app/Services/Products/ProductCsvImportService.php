<?php

namespace App\Services\Products;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The real CSV product import logic — extracted from
 * `Tenant\ProductImportController::store()` unchanged (Web/Flutter parity
 * task) so `Api\Mobile\ProductImportController` can call the exact same
 * business rules instead of a second, drifting copy. Rows sharing the same
 * product `name` become variants of one product (see [$productsByName]),
 * `category` auto-creates on first use, and the tenant's `max_products`
 * plan limit is enforced mid-import exactly like every other product-
 * creation path.
 *
 * Column headers (case-insensitive): name, category, variant,
 * purchase_price, selling_price, stock, description. Only `name` and
 * `selling_price` are required.
 */
class ProductCsvImportService
{
    public const REQUIRED_COLUMNS = ['name', 'selling_price'];

    /**
     * @param  resource  $handle  An open CSV file handle, header row not yet read.
     * @return array{created: int, skipped: int, errors: array<int, string>}
     *
     * @throws \RuntimeException if the file is empty or missing a required column.
     */
    public function import($handle): array
    {
        $tenant = app('currentTenant');
        $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

        $header = fgetcsv($handle);

        if (! $header) {
            throw new \RuntimeException('CSV ফাইলটি খালি।');
        }

        $header = array_map(fn ($h) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h))), $header);

        foreach (self::REQUIRED_COLUMNS as $col) {
            if (! in_array($col, $header)) {
                throw new \RuntimeException("CSV-তে '$col' কলামটি নেই। টেমপ্লেট ডাউনলোড করে সেই ফরম্যাটে দিন।");
            }
        }

        $created = 0;
        $skipped = 0;
        $errors = [];
        $productCount = Product::count();

        DB::transaction(function () use ($handle, $header, $tenant, $warehouse, &$created, &$skipped, &$errors, &$productCount) {
            $productsByName = [];
            $line = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (count(array_filter($row)) === 0) {
                    continue;
                }

                $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), null));
                $name = trim($data['name'] ?? '');
                $price = $data['selling_price'] ?? null;

                if ($name === '' || ! is_numeric($price)) {
                    $skipped++;
                    if (count($errors) < 5) {
                        $errors[] = "লাইন $line: নাম বা বিক্রয় মূল্য ঠিক নেই";
                    }

                    continue;
                }

                if (! $tenant->isWithinLimit('max_products', $productCount)) {
                    if (count($errors) < 5) {
                        $errors[] = 'প্ল্যানের প্রোডাক্ট লিমিট শেষ — বাকিগুলো বাদ পড়েছে';
                    }
                    $skipped++;

                    continue;
                }

                $key = mb_strtolower($name);

                if (! isset($productsByName[$key])) {
                    $categoryId = null;
                    if (! empty($data['category'])) {
                        $categoryId = Category::firstOrCreate(
                            ['name' => trim($data['category'])],
                            ['slug' => Str::slug($data['category']).'-'.Str::lower(Str::random(3))]
                        )->id;
                    }

                    $productsByName[$key] = Product::create([
                        'name' => $name,
                        'category_id' => $categoryId,
                        'description' => $data['description'] ?? null,
                        'is_active' => 1,
                    ]);
                    $productCount++;
                    $created++;
                }

                $product = $productsByName[$key];

                $variant = $product->variants()->create([
                    'tenant_id' => $tenant->id,
                    'variant_name' => trim($data['variant'] ?? '') ?: 'Default',
                    'purchase_price' => is_numeric($data['purchase_price'] ?? null) ? $data['purchase_price'] : 0,
                    'selling_price' => $price,
                ]);

                if ($product->variants()->count() > 1) {
                    $product->update(['has_variants' => 1]);
                }

                if ($warehouse) {
                    Inventory::create([
                        'variant_id' => $variant->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => is_numeric($data['stock'] ?? null) ? (int) $data['stock'] : 0,
                    ]);
                }
            }
        });

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }
}
