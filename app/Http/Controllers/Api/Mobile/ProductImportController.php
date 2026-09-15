<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Products\ProductCsvImportService;
use Illuminate\Http\Request;

/**
 * CSV product import — mirrors `Tenant\ProductImportController::store()`'s
 * real capability via the same [ProductCsvImportService] (Web/Flutter
 * parity task; previously left desktop-only, see this app's
 * ApiConfig.settingsWordpress doc comment for that earlier call — this is
 * the mobile mirror now). No template-download endpoint: the column
 * format is fixed, documented reference data (name/category/variant/
 * purchase_price/selling_price/stock/description), not a business rule,
 * so the Flutter screen shows it inline rather than round-tripping a file.
 */
class ProductImportController extends Controller
{
    public function store(Request $request, ProductCsvImportService $importer)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:4096']);

        $handle = fopen($request->file('file')->getRealPath(), 'r');

        try {
            $result = $importer->import($handle);
        } catch (\RuntimeException $e) {
            fclose($handle);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        fclose($handle);

        return response()->json($result);
    }
}
