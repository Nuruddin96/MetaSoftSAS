<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductImportController extends Controller
{
    public function form()
    {
        return view('tenant.products.import');
    }

    /** Downloadable CSV template */
    public function template()
    {
        $rows = [
            ['name', 'category', 'variant', 'purchase_price', 'selling_price', 'stock', 'description'],
            ['Cotton Panjabi', 'Panjabi', 'Navy / L', '850', '1250', '20', 'Premium cotton panjabi'],
            ['Cotton Panjabi', 'Panjabi', 'Navy / XL', '850', '1250', '15', ''],
            ['Leather Wallet', 'Accessories', '', '450', '790', '30', ''],
        ];

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="product-template.csv"',
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        $tenant = app('currentTenant');
        $warehouse = Warehouse::where('is_default', 1)->first() ?? Warehouse::first();

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = fgetcsv($handle);

        if (! $header) {
            return back()->with('error', 'CSV ফাইলটি খালি।');
        }

        // normalise header names
        $header = array_map(fn ($h) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h))), $header);

        $required = ['name', 'selling_price'];
        foreach ($required as $col) {
            if (! in_array($col, $header)) {
                return back()->with('error', "CSV-তে '$col' কলামটি নেই। টেমপ্লেট ডাউনলোড করে সেই ফরম্যাটে দিন।");
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

                // group rows with the same product name into one product with variants
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

        fclose($handle);

        $msg = "$created টি প্রোডাক্ট যোগ হয়েছে।".($skipped ? " $skipped টি সারি বাদ পড়েছে।" : '');
        if ($errors) {
            $msg .= ' ('.implode('; ', $errors).')';
        }

        return redirect()->route('tenant.products.index')->with($created ? 'success' : 'error', $msg);
    }
}
