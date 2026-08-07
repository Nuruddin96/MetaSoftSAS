-- Product Source: price range + multiple images
ALTER TABLE source_products ADD COLUMN max_price DECIMAL(12,2) DEFAULT NULL AFTER unit_price;

CREATE TABLE IF NOT EXISTS source_product_images (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_product_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (source_product_id) REFERENCES source_products(id) ON DELETE CASCADE,
    INDEX idx_product (source_product_id)
);
