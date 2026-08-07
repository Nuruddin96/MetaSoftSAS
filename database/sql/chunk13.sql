-- Add WhatsApp as an order channel option
ALTER TABLE orders MODIFY channel ENUM('website','facebook','instagram','whatsapp','call','others') DEFAULT 'website';
