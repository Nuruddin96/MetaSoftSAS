-- Custom domain request workflow
ALTER TABLE tenants ADD COLUMN custom_domain_requested VARCHAR(255) DEFAULT NULL AFTER custom_domain;
ALTER TABLE tenants ADD COLUMN custom_domain_request_status ENUM('none','pending','approved','rejected') DEFAULT 'none' AFTER custom_domain_requested;
