-- Order marketing channel (separate from system source web/pos/manual)
ALTER TABLE orders ADD COLUMN channel ENUM('website','facebook','instagram','call','others') DEFAULT 'website' AFTER source;
