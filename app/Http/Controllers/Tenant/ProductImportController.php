<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductCsvImportService;
use Illuminate\Http\Request;

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

    public function store(Request $request, ProductCsvImportService $importer)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');

        try {
            $result = $importer->import($handle);
        } catch (\RuntimeException $e) {
            fclose($handle);

            return back()->with('error', $e->getMessage());
        }

        fclose($handle);

        ['created' => $created, 'skipped' => $skipped, 'errors' => $errors] = $result;

        $msg = "$created টি প্রোডাক্ট যোগ হয়েছে।".($skipped ? " $skipped টি সারি বাদ পড়েছে।" : '');
        if ($errors) {
            $msg .= ' ('.implode('; ', $errors).')';
        }

        return redirect()->route('tenant.products.index')->with($created ? 'success' : 'error', $msg);
    }
}
