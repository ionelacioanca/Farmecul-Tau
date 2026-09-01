ALTER TABLE services
	ADD COLUMN IF NOT EXISTS slug VARCHAR(180) NULL AFTER name;

ALTER TABLE services
	ADD UNIQUE INDEX IF NOT EXISTS ux_services_slug (slug);

CREATE TABLE IF NOT EXISTS specialist_service_images (
	id INT AUTO_INCREMENT PRIMARY KEY,
	specialist_id INT NOT NULL,
	service_id INT NOT NULL,
	image_path VARCHAR(500) NOT NULL,
	alt_text VARCHAR(255) NULL,
	sort_order INT NOT NULL DEFAULT 0,
	active TINYINT(1) NOT NULL DEFAULT 1,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_specialist_service_images_lookup (specialist_id, service_id, active, sort_order),
	CONSTRAINT fk_specialist_service_images_specialist
		FOREIGN KEY (specialist_id) REFERENCES specialists(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT fk_specialist_service_images_service
		FOREIGN KEY (service_id) REFERENCES services(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT fk_specialist_service_images_pair
		FOREIGN KEY (specialist_id, service_id) REFERENCES specialist_services(specialist_id, service_id)
		ON UPDATE CASCADE
		ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
