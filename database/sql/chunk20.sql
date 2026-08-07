-- Custom domain: DNS TXT verification step before staff activates the domain
ALTER TABLE tenants ADD COLUMN custom_domain_verification_token VARCHAR(64) DEFAULT NULL AFTER custom_domain_request_status;
ALTER TABLE tenants ADD COLUMN custom_domain_dns_verified_at TIMESTAMP NULL DEFAULT NULL AFTER custom_domain_verification_token;
ALTER TABLE tenants MODIFY custom_domain_request_status ENUM('none','pending','dns_verified','approved','rejected') DEFAULT 'none';
