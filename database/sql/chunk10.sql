-- Correct plan limits and feature flags
UPDATE plans SET
    max_products = 100, max_staff = 2, max_warehouses = 1,
    allow_courier_api = 1, allow_pos = 0, allow_custom_domain = 0
WHERE slug = 'starter';

UPDATE plans SET
    max_products = 500, max_staff = 5, max_warehouses = 2,
    allow_courier_api = 1, allow_pos = 1, allow_custom_domain = 0
WHERE slug = 'business';

UPDATE plans SET
    max_products = NULL, max_staff = NULL, max_warehouses = NULL,
    allow_courier_api = 1, allow_pos = 1, allow_custom_domain = 1
WHERE slug = 'pro';
