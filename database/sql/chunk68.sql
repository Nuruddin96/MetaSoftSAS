-- Recognition platform, part 5 (import after chunk67.sql). Data only — no schema change.
--
-- The 50 business categories offered at registration, in display order
-- (App\Support\Platform\BusinessCategories::NAMES; slug = Str::slug(name)).
--
-- 1. Upsert by slug: new categories are added; a category that already exists
--    with the same slug (Food & Beverage, Education & Training) keeps its
--    name, Bangla name, icon and brands, and only gets its new position.
-- 2. Categories that are not on the list are hidden from new selections
--    (is_active = 0). Nothing is renamed or deleted, and no brand changes
--    category: existing brands keep theirs, and their owners and the
--    directory filter still accept it. Re-activate one any time in
--    Super Admin -> Brand categories.
--
-- Safe to re-run (same end state). Check first:
--   SELECT slug, name, is_active, sort_order FROM brand_categories ORDER BY sort_order;

INSERT INTO brand_categories (name, slug, icon, sort_order, is_active, created_at, updated_at) VALUES
('Clothing & Fashion', 'clothing-fashion', 'store', 1, 1, NOW(), NOW()),
('Information Technology & Software', 'information-technology-software', 'store', 2, 1, NOW(), NOW()),
('Digital Marketing & Advertising', 'digital-marketing-advertising', 'store', 3, 1, NOW(), NOW()),
('Food & Beverage', 'food-beverage', 'store', 4, 1, NOW(), NOW()),
('Agriculture & Agribusiness', 'agriculture-agribusiness', 'store', 5, 1, NOW(), NOW()),
('Real Estate & Property Development', 'real-estate-property-development', 'store', 6, 1, NOW(), NOW()),
('Construction & Engineering', 'construction-engineering', 'store', 7, 1, NOW(), NOW()),
('Healthcare & Medical Services', 'healthcare-medical-services', 'store', 8, 1, NOW(), NOW()),
('Pharmaceuticals & Healthcare Products', 'pharmaceuticals-healthcare-products', 'store', 9, 1, NOW(), NOW()),
('Education & Training', 'education-training', 'store', 10, 1, NOW(), NOW()),
('Beauty & Personal Care', 'beauty-personal-care', 'store', 11, 1, NOW(), NOW()),
('Manufacturing & Industrial Products', 'manufacturing-industrial-products', 'store', 12, 1, NOW(), NOW()),
('Import & Export', 'import-export', 'store', 13, 1, NOW(), NOW()),
('Wholesale & Retail Trade', 'wholesale-retail-trade', 'store', 14, 1, NOW(), NOW()),
('Transportation & Logistics', 'transportation-logistics', 'store', 15, 1, NOW(), NOW()),
('Banking, Finance & Insurance', 'banking-finance-insurance', 'store', 16, 1, NOW(), NOW()),
('Travel & Tourism', 'travel-tourism', 'store', 17, 1, NOW(), NOW()),
('Hospitality & Accommodation', 'hospitality-accommodation', 'store', 18, 1, NOW(), NOW()),
('Media & Entertainment', 'media-entertainment', 'store', 19, 1, NOW(), NOW()),
('Professional & Business Services', 'professional-business-services', 'store', 20, 1, NOW(), NOW()),
('Electronics & Technology Products', 'electronics-technology-products', 'store', 21, 1, NOW(), NOW()),
('Telecommunications & Networking', 'telecommunications-networking', 'store', 22, 1, NOW(), NOW()),
('Automotive & Vehicle Services', 'automotive-vehicle-services', 'store', 23, 1, NOW(), NOW()),
('Furniture & Home Decor', 'furniture-home-decor', 'store', 24, 1, NOW(), NOW()),
('Interior Design & Architecture', 'interior-design-architecture', 'store', 25, 1, NOW(), NOW()),
('Electrical & Electronics Services', 'electrical-electronics-services', 'store', 26, 1, NOW(), NOW()),
('Energy & Renewable Energy', 'energy-renewable-energy', 'store', 27, 1, NOW(), NOW()),
('Security & Surveillance Services', 'security-surveillance-services', 'store', 28, 1, NOW(), NOW()),
('Printing, Packaging & Publishing', 'printing-packaging-publishing', 'store', 29, 1, NOW(), NOW()),
('Human Resources & Recruitment', 'human-resources-recruitment', 'store', 30, 1, NOW(), NOW()),
('Event Management & Wedding Services', 'event-management-wedding-services', 'store', 31, 1, NOW(), NOW()),
('Business Consulting & Advisory', 'business-consulting-advisory', 'store', 32, 1, NOW(), NOW()),
('NGOs & Social Enterprises', 'ngos-social-enterprises', 'store', 33, 1, NOW(), NOW()),
('Fashion Accessories & Lifestyle Products', 'fashion-accessories-lifestyle-products', 'store', 34, 1, NOW(), NOW()),
('Sports & Fitness', 'sports-fitness', 'store', 35, 1, NOW(), NOW()),
('Beauty, Wellness & Spa Services', 'beauty-wellness-spa-services', 'store', 36, 1, NOW(), NOW()),
('Legal Services', 'legal-services', 'store', 37, 1, NOW(), NOW()),
('Accounting, Tax & Audit Services', 'accounting-tax-audit-services', 'store', 38, 1, NOW(), NOW()),
('Cleaning & Facility Management', 'cleaning-facility-management', 'store', 39, 1, NOW(), NOW()),
('Courier & Delivery Services', 'courier-delivery-services', 'store', 40, 1, NOW(), NOW()),
('Marine & Shipping Services', 'marine-shipping-services', 'store', 41, 1, NOW(), NOW()),
('Garments & Textile Industry', 'garments-textile-industry', 'store', 42, 1, NOW(), NOW()),
('Leather & Footwear', 'leather-footwear', 'store', 43, 1, NOW(), NOW()),
('Plastic, Rubber & Chemical Products', 'plastic-rubber-chemical-products', 'store', 44, 1, NOW(), NOW()),
('Water, Sanitation & Environmental Services', 'water-sanitation-environmental-services', 'store', 45, 1, NOW(), NOW()),
('Research & Development', 'research-development', 'store', 46, 1, NOW(), NOW()),
('Franchise & Business Opportunities', 'franchise-business-opportunities', 'store', 47, 1, NOW(), NOW()),
('Repair & Maintenance Services', 'repair-maintenance-services', 'store', 48, 1, NOW(), NOW()),
('Pet Care & Animal Services', 'pet-care-animal-services', 'store', 49, 1, NOW(), NOW()),
('Religious & Cultural Services', 'religious-cultural-services', 'store', 50, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = 1, updated_at = NOW();

UPDATE brand_categories SET is_active = 0, updated_at = NOW()
WHERE is_active = 1 AND slug NOT IN ('clothing-fashion', 'information-technology-software', 'digital-marketing-advertising', 'food-beverage', 'agriculture-agribusiness', 'real-estate-property-development', 'construction-engineering', 'healthcare-medical-services', 'pharmaceuticals-healthcare-products', 'education-training', 'beauty-personal-care', 'manufacturing-industrial-products', 'import-export', 'wholesale-retail-trade', 'transportation-logistics', 'banking-finance-insurance', 'travel-tourism', 'hospitality-accommodation', 'media-entertainment', 'professional-business-services', 'electronics-technology-products', 'telecommunications-networking', 'automotive-vehicle-services', 'furniture-home-decor', 'interior-design-architecture', 'electrical-electronics-services', 'energy-renewable-energy', 'security-surveillance-services', 'printing-packaging-publishing', 'human-resources-recruitment', 'event-management-wedding-services', 'business-consulting-advisory', 'ngos-social-enterprises', 'fashion-accessories-lifestyle-products', 'sports-fitness', 'beauty-wellness-spa-services', 'legal-services', 'accounting-tax-audit-services', 'cleaning-facility-management', 'courier-delivery-services', 'marine-shipping-services', 'garments-textile-industry', 'leather-footwear', 'plastic-rubber-chemical-products', 'water-sanitation-environmental-services', 'research-development', 'franchise-business-opportunities', 'repair-maintenance-services', 'pet-care-animal-services', 'religious-cultural-services');
