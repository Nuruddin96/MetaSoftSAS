<?php
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Translates the payloads MetaSoft_Connector_REST_Controller receives into
 * WooCommerce products/variations/terms/stock. MetaSoftSAS is the source
 * of truth (see WordPressProductSyncService's docblock on the MetaSoftSAS
 * side) — every method here is an upsert keyed by a MetaSoftSAS id stored
 * in WordPress post/term meta, never the other way around. Nothing here
 * reads FROM WooCommerce to send back to MetaSoftSAS; that direction
 * (orders/customers) is a later phase.
 */
class MetaSoft_Connector_WooCommerce_Sync
{
    const PRODUCT_ID_META = '_metasoft_product_id';

    const VARIANT_ID_META = '_metasoft_variant_id';

    const CATEGORY_ID_META = 'metasoft_category_id';

    public static function is_available(): bool
    {
        return class_exists('WooCommerce') && class_exists('WC_Product_Simple') && class_exists('WC_Product_Variable');
    }

    /**
     * @return array{ok: bool, message?: string, product_id?: int}
     */
    public static function upsert_product(array $data): array
    {
        if (! self::is_available()) {
            return ['ok' => false, 'message' => 'WooCommerce is not active on this site.'];
        }

        $metasoftId = isset($data['id']) ? (int) $data['id'] : 0;
        if ($metasoftId <= 0) {
            return ['ok' => false, 'message' => 'Missing product id.'];
        }

        $variants = is_array($data['variants'] ?? null) ? $data['variants'] : [];
        $existingPostId = self::find_post_by_meta(self::PRODUCT_ID_META, $metasoftId, 'product');

        $isVariable = count($variants) > 1;

        // If the product already exists but its type (simple<->variable)
        // needs to change (a product gained/lost variants on the
        // MetaSoftSAS side), start fresh rather than fighting WooCommerce's
        // own product-type migration path — same "delete and recreate"
        // simplicity WooCommerce itself recommends for a type change.
        if ($existingPostId) {
            $currentProduct = wc_get_product($existingPostId);
            $currentIsVariable = $currentProduct && $currentProduct->is_type('variable');

            if ($currentProduct && $currentIsVariable !== $isVariable) {
                $currentProduct->delete(true);
                $existingPostId = 0;
            }
        }

        $product = $isVariable
            ? ($existingPostId ? new WC_Product_Variable($existingPostId) : new WC_Product_Variable)
            : ($existingPostId ? new WC_Product_Simple($existingPostId) : new WC_Product_Simple);

        $product->set_name($data['name'] ?? ('Product #'.$metasoftId));
        $product->set_slug($data['slug'] ?? '');
        $product->set_description($data['description'] ?? '');
        $product->set_status(! empty($data['is_active']) ? 'publish' : 'draft');
        $product->set_featured(! empty($data['is_featured']));

        if (! empty($data['category_id'])) {
            $termId = self::find_term_by_meta(self::CATEGORY_ID_META, (int) $data['category_id']);
            if ($termId) {
                $product->set_category_ids([$termId]);
            }
        }

        self::apply_images($product, $data);

        if ($isVariable) {
            self::apply_variable_attributes($product, $variants);
        } elseif (! empty($variants)) {
            self::apply_simple_variant($product, $variants[0]);
        }

        $productId = $product->save();
        update_post_meta($productId, self::PRODUCT_ID_META, $metasoftId);

        if ($isVariable) {
            self::sync_variations($productId, $variants);
        }

        return ['ok' => true, 'product_id' => $productId];
    }

    public static function delete_product(int $metasoftProductId): array
    {
        if (! self::is_available()) {
            return ['ok' => false, 'message' => 'WooCommerce is not active on this site.'];
        }

        $postId = self::find_post_by_meta(self::PRODUCT_ID_META, $metasoftProductId, 'product');

        if (! $postId) {
            return ['ok' => true]; // already absent — deleting is idempotent
        }

        $product = wc_get_product($postId);
        if ($product) {
            // Trash, not force-delete — same "preserve history, allow
            // recovery" posture MetaSoftSAS's own disconnect flows use
            // (see FacebookConnectController::disconnect()'s docblock on
            // the MetaSoftSAS side).
            $product->delete(false);
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, message?: string, term_id?: int}
     */
    public static function upsert_category(array $data): array
    {
        if (! self::is_available()) {
            return ['ok' => false, 'message' => 'WooCommerce is not active on this site.'];
        }

        $metasoftId = isset($data['id']) ? (int) $data['id'] : 0;
        if ($metasoftId <= 0) {
            return ['ok' => false, 'message' => 'Missing category id.'];
        }

        $name = $data['name'] ?? ('Category #'.$metasoftId);
        $existingTermId = self::find_term_by_meta(self::CATEGORY_ID_META, $metasoftId);

        $args = ['slug' => $data['slug'] ?? ''];

        // Parent must already have been synced (its own metasoft_category_id
        // term meta present) — if not, this category is created without a
        // parent for now. A later full re-sync of the parent does not
        // retroactively reparent existing children in this phase; noted as
        // a phase-4 limitation, see the plugin readme.
        if (! empty($data['parent_id'])) {
            $parentTermId = self::find_term_by_meta(self::CATEGORY_ID_META, (int) $data['parent_id']);
            if ($parentTermId) {
                $args['parent'] = $parentTermId;
            }
        }

        if ($existingTermId) {
            $result = wp_update_term($existingTermId, 'product_cat', array_merge($args, ['name' => $name]));
            $termId = is_wp_error($result) ? 0 : $result['term_id'];
        } else {
            $result = wp_insert_term($name, 'product_cat', $args);
            $termId = is_wp_error($result) ? 0 : $result['term_id'];
        }

        if (! $termId) {
            return ['ok' => false, 'message' => is_wp_error($result) ? $result->get_error_message() : 'Unknown term error.'];
        }

        update_term_meta($termId, self::CATEGORY_ID_META, $metasoftId);

        if (! empty($data['image_url'])) {
            update_term_meta($termId, 'thumbnail_url', esc_url_raw($data['image_url']));
        }

        return ['ok' => true, 'term_id' => $termId];
    }

    public static function delete_category(int $metasoftCategoryId): array
    {
        if (! self::is_available()) {
            return ['ok' => false, 'message' => 'WooCommerce is not active on this site.'];
        }

        $termId = self::find_term_by_meta(self::CATEGORY_ID_META, $metasoftCategoryId);

        if ($termId) {
            wp_delete_term($termId, 'product_cat');
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public static function update_stock(array $data): array
    {
        if (! self::is_available()) {
            return ['ok' => false, 'message' => 'WooCommerce is not active on this site.'];
        }

        $variantId = isset($data['variant_id']) ? (int) $data['variant_id'] : 0;
        $productId = isset($data['product_id']) ? (int) $data['product_id'] : 0;
        $quantity = isset($data['quantity']) ? (int) $data['quantity'] : null;

        if ($quantity === null) {
            return ['ok' => false, 'message' => 'Missing quantity.'];
        }

        // A variation carries VARIANT_ID_META; a simple product's single
        // "variant" was stamped with PRODUCT_ID_META instead (see
        // apply_simple_variant()) — try the variation match first since
        // it's the more specific one.
        $postId = self::find_post_by_meta(self::VARIANT_ID_META, $variantId, 'product_variation')
            ?: self::find_post_by_meta(self::PRODUCT_ID_META, $productId, 'product');

        if (! $postId) {
            return ['ok' => false, 'message' => 'No matching product/variation for this stock update.'];
        }

        $product = wc_get_product($postId);
        if (! $product) {
            return ['ok' => false, 'message' => 'Product could not be loaded.'];
        }

        $product->set_manage_stock(true);
        $product->set_stock_quantity($quantity);
        $product->set_stock_status($quantity > 0 ? 'instock' : 'outofstock');
        $product->save();

        return ['ok' => true];
    }

    protected static function apply_simple_variant(WC_Product_Simple $product, array $variant): void
    {
        if (! empty($variant['sku'])) {
            $product->set_sku($variant['sku']);
        }

        $product->set_regular_price((string) ($variant['compare_at_price'] ?? $variant['selling_price'] ?? 0));
        $product->set_sale_price(! empty($variant['compare_at_price']) ? (string) $variant['selling_price'] : '');
        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) ($variant['stock'] ?? 0));
        $product->set_stock_status(((int) ($variant['stock'] ?? 0)) > 0 ? 'instock' : 'outofstock');
        $product->set_status(! empty($variant['is_active']) ? $product->get_status() : 'draft');

        // Stamped so update_stock() can match a single-variant product by
        // PRODUCT_ID_META when no variation-level id applies.
        $product->add_meta_data(self::VARIANT_ID_META, (int) ($variant['id'] ?? 0), true);
    }

    /**
     * Sets up the variable product's own attribute definitions (custom,
     * non-global — no taxonomy pre-registration required) from the union
     * of every variant's attribute keys/values, e.g. {"color":"Red"} +
     * {"color":"Blue"} -> one "Color" attribute with options [Red, Blue].
     */
    protected static function apply_variable_attributes(WC_Product_Variable $product, array $variants): void
    {
        $optionsByKey = [];

        foreach ($variants as $variant) {
            foreach (($variant['attributes'] ?? []) as $key => $value) {
                $optionsByKey[$key][] = (string) $value;
            }
        }

        $attributes = [];
        foreach ($optionsByKey as $key => $options) {
            $attribute = new WC_Product_Attribute;
            $attribute->set_name(ucfirst($key));
            $attribute->set_options(array_values(array_unique($options)));
            $attribute->set_variation(true);
            $attribute->set_visible(true);
            $attributes[] = $attribute;
        }

        $product->set_attributes($attributes);
    }

    protected static function sync_variations(int $productId, array $variants): void
    {
        foreach ($variants as $variant) {
            $variantId = (int) ($variant['id'] ?? 0);
            if ($variantId <= 0) {
                continue;
            }

            $existingVariationId = self::find_post_by_meta(self::VARIANT_ID_META, $variantId, 'product_variation');

            $variation = $existingVariationId
                ? new WC_Product_Variation($existingVariationId)
                : new WC_Product_Variation;

            $variation->set_parent_id($productId);

            if (! empty($variant['sku'])) {
                $variation->set_sku($variant['sku']);
            }

            $variation->set_regular_price((string) ($variant['compare_at_price'] ?? $variant['selling_price'] ?? 0));
            $variation->set_sale_price(! empty($variant['compare_at_price']) ? (string) $variant['selling_price'] : '');
            $variation->set_manage_stock(true);
            $variation->set_stock_quantity((int) ($variant['stock'] ?? 0));
            $variation->set_stock_status(((int) ($variant['stock'] ?? 0)) > 0 ? 'instock' : 'outofstock');
            $variation->set_status(! empty($variant['is_active']) ? 'publish' : 'private');

            $attributes = [];
            foreach (($variant['attributes'] ?? []) as $key => $value) {
                $attributes['attribute_'.sanitize_title(ucfirst($key))] = $value;
            }
            $variation->set_attributes($attributes);

            $savedId = $variation->save();
            update_post_meta($savedId, self::VARIANT_ID_META, $variantId);
        }
    }

    protected static function apply_images(WC_Product $product, array $data): void
    {
        if (! empty($data['thumbnail_url'])) {
            $thumbnailId = self::attach_image_from_url($data['thumbnail_url']);
            if ($thumbnailId) {
                $product->set_image_id($thumbnailId);
            }
        }

        if (! empty($data['images']) && is_array($data['images'])) {
            $galleryIds = array_filter(array_map([self::class, 'attach_image_from_url'], $data['images']));
            if ($galleryIds) {
                $product->set_gallery_image_ids(array_values($galleryIds));
            }
        }
    }

    /**
     * Downloads and sideloads a MetaSoftSAS-hosted image into the WP media
     * library, deduplicated by the source URL (stored in postmeta) so a
     * repeated product push doesn't re-download/re-attach the same image
     * every time.
     */
    protected static function attach_image_from_url(string $url): int
    {
        $existing = self::find_post_by_meta('_metasoft_source_url', $url, 'attachment');
        if ($existing) {
            return $existing;
        }

        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
        }

        $attachmentId = media_sideload_image($url, 0, null, 'id');

        if (is_wp_error($attachmentId)) {
            return 0;
        }

        update_post_meta($attachmentId, '_metasoft_source_url', $url);

        return (int) $attachmentId;
    }

    protected static function find_post_by_meta(string $metaKey, $metaValue, string $postType): int
    {
        if (! $metaValue) {
            return 0;
        }

        $posts = get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'meta_key' => $metaKey,
            'meta_value' => $metaValue,
            'fields' => 'ids',
            'posts_per_page' => 1,
        ]);

        return $posts ? (int) $posts[0] : 0;
    }

    protected static function find_term_by_meta(string $metaKey, $metaValue): int
    {
        if (! $metaValue) {
            return 0;
        }

        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'meta_key' => $metaKey,
            'meta_value' => $metaValue,
            'fields' => 'ids',
            'number' => 1,
        ]);

        return (! is_wp_error($terms) && $terms) ? (int) $terms[0] : 0;
    }
}
