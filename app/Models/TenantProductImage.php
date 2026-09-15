<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * "পণ্যের ছবি" (Product Image Memory) — one tenant-authored product-name
 * -> image mapping (database/sql/chunk50.sql), e.g. "Brilliant Skin
 * Rejuvenating Set" -> an uploaded photo. Stored verbatim — Tenant\
 * ProductImageMemoryController never calls OpenAI to save one. Matching a
 * real customer's image request against these rows happens later, at AI
 * reply time, in App\Services\AI\AiProductImageMemoryService.
 *
 * Deliberately separate from App\Models\ProductImage (a real catalog
 * Product's own gallery, FK'd to products.id) — this table lets a tenant
 * teach the AI to send a picture for anything a customer might ask about,
 * whether or not it corresponds to a distinct Product/SKU row (a combo
 * set, a service, a bundle that isn't sold as its own catalog entry).
 */
class TenantProductImage extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_product_images';

    protected $guarded = [];

    /**
     * Additive-table guard (database/sql/chunk50.sql) — same pattern as
     * AiTenantMemory::tablesReady(): a deploy that lands before this chunk
     * is imported must degrade to "no saved product images yet" instead
     * of a raw SQL error.
     */
    public static function tablesReady(): bool
    {
        return Schema::hasTable('tenant_product_images');
    }
}
