-- Farmecul Tau offers booking architecture migration
-- Run this after the booking foundation migrations.
--
-- This keeps existing appointments as service bookings, then allows future
-- appointments to reference either a service or an offer.

CREATE TABLE IF NOT EXISTS offers (
	id INT AUTO_INCREMENT PRIMARY KEY,
	title VARCHAR(180) NOT NULL,
	slug VARCHAR(180) NOT NULL,
	description TEXT NULL,
	image_path VARCHAR(500) NULL,
	price DECIMAL(10,2) NOT NULL,
	duration_minutes INT NOT NULL,
	start_date DATE NOT NULL,
	end_date DATE NOT NULL,
	active TINYINT(1) NOT NULL DEFAULT 1,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
	UNIQUE KEY ux_offers_slug (slug),
	INDEX idx_offers_bookable (active, start_date, end_date),
	CONSTRAINT chk_offers_price CHECK (price >= 0),
	CONSTRAINT chk_offers_duration CHECK (duration_minutes BETWEEN 5 AND 480),
	CONSTRAINT chk_offers_dates CHECK (start_date <= end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_services (
	offer_id INT NOT NULL,
	service_id INT NOT NULL,
	PRIMARY KEY (offer_id, service_id),
	INDEX idx_offer_services_service_id (service_id),
	CONSTRAINT fk_offer_services_offer
		FOREIGN KEY (offer_id) REFERENCES offers(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT fk_offer_services_service
		FOREIGN KEY (service_id) REFERENCES services(id)
		ON UPDATE CASCADE
		ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_specialists (
	offer_id INT NOT NULL,
	specialist_id INT NOT NULL,
	PRIMARY KEY (offer_id, specialist_id),
	INDEX idx_offer_specialists_specialist_id (specialist_id),
	CONSTRAINT fk_offer_specialists_offer
		FOREIGN KEY (offer_id) REFERENCES offers(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT fk_offer_specialists_specialist
		FOREIGN KEY (specialist_id) REFERENCES specialists(id)
		ON UPDATE CASCADE
		ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE appointments
	ADD COLUMN IF NOT EXISTS booking_type ENUM('service', 'offer') NOT NULL DEFAULT 'service' AFTER customer_phone,
	ADD COLUMN IF NOT EXISTS offer_id INT NULL AFTER service_id;

UPDATE appointments
SET booking_type = 'service'
WHERE booking_type IS NULL
	OR booking_type = '';

ALTER TABLE appointments
	MODIFY service_id INT NULL;

ALTER TABLE appointments
	ADD INDEX IF NOT EXISTS idx_appointments_booking_type (booking_type),
	ADD INDEX IF NOT EXISTS idx_appointments_offer_id (offer_id);

SET @add_appointments_offer_fk = (
	SELECT IF(
		COUNT(*) = 0,
		'ALTER TABLE appointments ADD CONSTRAINT fk_appointments_offer FOREIGN KEY (offer_id) REFERENCES offers(id) ON UPDATE CASCADE ON DELETE RESTRICT',
		'SELECT 1'
	)
	FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
	WHERE CONSTRAINT_SCHEMA = DATABASE()
		AND TABLE_NAME = 'appointments'
		AND CONSTRAINT_NAME = 'fk_appointments_offer'
);
PREPARE add_appointments_offer_fk_stmt FROM @add_appointments_offer_fk;
EXECUTE add_appointments_offer_fk_stmt;
DEALLOCATE PREPARE add_appointments_offer_fk_stmt;

SET @add_appointments_booking_type_check = (
	SELECT IF(
		COUNT(*) = 0,
		'ALTER TABLE appointments ADD CONSTRAINT chk_appointments_booking_type_target CHECK ((booking_type = ''service'' AND service_id IS NOT NULL AND offer_id IS NULL) OR (booking_type = ''offer'' AND offer_id IS NOT NULL AND service_id IS NULL))',
		'SELECT 1'
	)
	FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
	WHERE CONSTRAINT_SCHEMA = DATABASE()
		AND TABLE_NAME = 'appointments'
		AND CONSTRAINT_NAME = 'chk_appointments_booking_type_target'
);
PREPARE add_appointments_booking_type_check_stmt FROM @add_appointments_booking_type_check;
EXECUTE add_appointments_booking_type_check_stmt;
DEALLOCATE PREPARE add_appointments_booking_type_check_stmt;
