-- "পণ্যের ছবি" (Product Image Memory) — a completely separate, generic
-- tenant-authored product-name -> image mapping. Additive only: one new
-- table, nothing else changes.
--
-- Distinct from product_images (a real catalog Product's own gallery,
-- FK'd to products.id) — this table lets a tenant teach the AI to send a
-- picture for anything a customer might ask a picture of, whether or not
-- it corresponds to a distinct Product/SKU row (a combo set, a bundle, a
-- service). Same "no OpenAI call to save it" design as tenant_ai_memories
-- (chunk41.sql) — pure DB write + a plain image upload, see
-- Tenant\ProductImageMemoryController.
--
-- Matching a real customer's "ছবি দেন"/"pic den" against these rows at
-- reply time is a cheap, deterministic keyword-overlap + conversation-
-- relevance score (App\Services\AI\AiProductImageMemoryService) — no
-- embeddings, no OpenAI call, same "simple reliable architecture" choice
-- AiTenantMemoryService's own docblock already documents. A confident
-- match sends the stored image directly via the existing outbound-media
-- paths (MessengerApi::sendAttachment()/WhatsAppSendService::sendMedia()),
-- bypassing OpenAI whenever the customer's turn is nothing but an image
-- request.
CREATE TABLE IF NOT EXISTS tenant_product_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant (tenant_id)
);
