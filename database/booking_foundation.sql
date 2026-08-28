CREATE TABLE IF NOT EXISTS services (
	id INT AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(150) NOT NULL,
	description TEXT NULL,
	category ENUM('hairstyle', 'nails') NOT NULL,
	duration_minutes INT NOT NULL,
	price DECIMAL(10,2) NULL,
	active TINYINT(1) DEFAULT 1,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE services
	ADD COLUMN IF NOT EXISTS category ENUM('hairstyle', 'nails') NULL
	AFTER description;

UPDATE services
SET category = 'hairstyle'
WHERE name IN ('[DEV] Tuns', '[DEV] Coafat', '[DEV] Vopsit');

UPDATE services
SET category = 'nails'
WHERE name IN ('[DEV] Manichiură', '[DEV] ManichiurÄƒ', '[DEV] Manichiur?', '[DEV] Pedichiură', '[DEV] PedichiurÄƒ', '[DEV] Pedichiur?');

UPDATE services
SET category = 'nails'
WHERE id IN (4, 5)
	AND (category IS NULL OR category = '');

ALTER TABLE services
	MODIFY category ENUM('hairstyle', 'nails') NOT NULL;

ALTER TABLE services
	ADD INDEX IF NOT EXISTS idx_services_category_active (category, active);

CREATE TABLE IF NOT EXISTS specialists (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NULL,
	name VARCHAR(150) NOT NULL,
	email VARCHAR(255) NULL,
	specialization ENUM('hairstylist', 'nails') NULL,
	phone VARCHAR(50) NULL,
	active TINYINT(1) DEFAULT 1,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_specialists_user_id (user_id),
	UNIQUE KEY ux_specialists_user_id (user_id),
	INDEX idx_specialists_specialization_active (specialization, active),
	CONSTRAINT fk_specialists_user
		FOREIGN KEY (user_id) REFERENCES users(id)
		ON UPDATE CASCADE
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE specialists
	ADD COLUMN IF NOT EXISTS specialization ENUM('hairstylist', 'nails') NULL
	AFTER email;

ALTER TABLE specialists
	ADD UNIQUE INDEX IF NOT EXISTS ux_specialists_user_id (user_id);

ALTER TABLE specialists
	ADD INDEX IF NOT EXISTS idx_specialists_specialization_active (specialization, active);

-- Link founder admins manually after creating/updating their user accounts.
-- Do not run this blindly; replace the emails and specialist names with the real records:
--
-- UPDATE specialists sp
-- INNER JOIN users u ON u.email = 'founder-hairstylist@example.com'
-- SET sp.user_id = u.id,
-- 	sp.email = u.email,
-- 	sp.name = u.name,
-- 	sp.specialization = 'hairstylist'
-- WHERE sp.name = '[DEV] Fondatoare 1'
-- 	AND u.role = 'admin';
--
-- UPDATE specialists sp
-- INNER JOIN users u ON u.email = 'founder-nails@example.com'
-- SET sp.user_id = u.id,
-- 	sp.email = u.email,
-- 	sp.name = u.name,
-- 	sp.specialization = 'nails'
-- WHERE sp.name = '[DEV] Fondatoare 2'
-- 	AND u.role = 'admin';

CREATE TABLE IF NOT EXISTS specialist_services (
	specialist_id INT NOT NULL,
	service_id INT NOT NULL,
	price DECIMAL(10,2) NULL,
	duration_minutes INT NULL,
	active TINYINT(1) NOT NULL DEFAULT 1,
	PRIMARY KEY (specialist_id, service_id),
	INDEX idx_specialist_services_service_id (service_id),
	INDEX idx_specialist_services_bookable (service_id, active, duration_minutes, price),
	CONSTRAINT fk_specialist_services_specialist
		FOREIGN KEY (specialist_id) REFERENCES specialists(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT fk_specialist_services_service
		FOREIGN KEY (service_id) REFERENCES services(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE specialist_services
	ADD COLUMN IF NOT EXISTS price DECIMAL(10,2) NULL AFTER service_id,
	ADD COLUMN IF NOT EXISTS duration_minutes INT NULL AFTER price,
	ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER duration_minutes;

UPDATE specialist_services ss
INNER JOIN services sv ON sv.id = ss.service_id
SET ss.price = COALESCE(ss.price, sv.price),
	ss.duration_minutes = COALESCE(ss.duration_minutes, sv.duration_minutes),
	ss.active = COALESCE(ss.active, 1);

ALTER TABLE specialist_services
	ADD INDEX IF NOT EXISTS idx_specialist_services_bookable (service_id, active, duration_minutes, price);

CREATE TABLE IF NOT EXISTS specialist_schedule (
	id INT AUTO_INCREMENT PRIMARY KEY,
	specialist_id INT NOT NULL,
	day_of_week TINYINT NOT NULL COMMENT '1 = Monday, 2 = Tuesday, ..., 7 = Sunday',
	start_time TIME NOT NULL,
	end_time TIME NOT NULL,
	active TINYINT(1) DEFAULT 1,
	INDEX idx_specialist_schedule_lookup (specialist_id, day_of_week, active),
	UNIQUE KEY ux_specialist_schedule_day (specialist_id, day_of_week),
	CONSTRAINT fk_specialist_schedule_specialist
		FOREIGN KEY (specialist_id) REFERENCES specialists(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT chk_specialist_schedule_day CHECK (day_of_week BETWEEN 1 AND 7),
	CONSTRAINT chk_specialist_schedule_time CHECK (start_time < end_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocked_slots (
	id INT AUTO_INCREMENT PRIMARY KEY,
	specialist_id INT NOT NULL,
	start_datetime DATETIME NOT NULL,
	end_datetime DATETIME NOT NULL,
	reason VARCHAR(255) NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_blocked_slots_lookup (specialist_id, start_datetime, end_datetime),
	CONSTRAINT fk_blocked_slots_specialist
		FOREIGN KEY (specialist_id) REFERENCES specialists(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT chk_blocked_slots_time CHECK (start_datetime < end_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
	id INT AUTO_INCREMENT PRIMARY KEY,
	customer_user_id INT NULL,
	customer_name VARCHAR(150) NOT NULL,
	customer_email VARCHAR(255) NULL,
	customer_phone VARCHAR(50) NULL,
	service_id INT NOT NULL,
	specialist_id INT NOT NULL,
	start_datetime DATETIME NOT NULL,
	end_datetime DATETIME NOT NULL,
	price_at_booking DECIMAL(10,2) NULL,
	duration_minutes_at_booking INT NOT NULL,
	status ENUM(
		'pending',
		'approved',
		'rejected',
		'cancelled'
	) NOT NULL DEFAULT 'pending',
	source ENUM(
		'online',
		'admin',
		'phone',
		'instagram',
		'other'
	) NOT NULL DEFAULT 'online',
	notes TEXT NULL,
	admin_note TEXT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_appointments_customer_user_id (customer_user_id),
	INDEX idx_appointments_service_id (service_id),
	INDEX idx_appointments_availability (specialist_id, status, start_datetime, end_datetime),
	CONSTRAINT fk_appointments_customer_user
		FOREIGN KEY (customer_user_id) REFERENCES users(id)
		ON UPDATE CASCADE
		ON DELETE SET NULL,
	CONSTRAINT fk_appointments_service
		FOREIGN KEY (service_id) REFERENCES services(id)
		ON UPDATE CASCADE
		ON DELETE RESTRICT,
	CONSTRAINT fk_appointments_specialist
		FOREIGN KEY (specialist_id) REFERENCES specialists(id)
		ON UPDATE CASCADE
		ON DELETE RESTRICT,
	CONSTRAINT chk_appointments_time CHECK (start_datetime < end_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE appointments
	ADD COLUMN IF NOT EXISTS admin_note TEXT NULL AFTER notes;

ALTER TABLE appointments
	ADD COLUMN IF NOT EXISTS price_at_booking DECIMAL(10,2) NULL AFTER end_datetime,
	ADD COLUMN IF NOT EXISTS duration_minutes_at_booking INT NULL AFTER price_at_booking;

UPDATE appointments a
LEFT JOIN services sv ON sv.id = a.service_id
SET a.price_at_booking = COALESCE(a.price_at_booking, sv.price),
	a.duration_minutes_at_booking = COALESCE(
		a.duration_minutes_at_booking,
		TIMESTAMPDIFF(MINUTE, a.start_datetime, a.end_datetime),
		sv.duration_minutes
	)
WHERE a.duration_minutes_at_booking IS NULL
	OR a.price_at_booking IS NULL;

UPDATE appointments
SET duration_minutes_at_booking = 5
WHERE duration_minutes_at_booking IS NULL
	OR duration_minutes_at_booking < 1;

ALTER TABLE appointments
	MODIFY duration_minutes_at_booking INT NOT NULL;

ALTER TABLE appointments
	MODIFY customer_email VARCHAR(255) NULL;

INSERT INTO services (name, description, category, duration_minutes, price, active)
SELECT '[DEV] Tuns', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 'hairstyle', 45, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Tuns');

INSERT INTO services (name, description, category, duration_minutes, price, active)
SELECT '[DEV] Coafat', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 'hairstyle', 60, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Coafat');

INSERT INTO services (name, description, category, duration_minutes, price, active)
SELECT '[DEV] Vopsit', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 'hairstyle', 120, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Vopsit');

INSERT INTO services (name, description, category, duration_minutes, price, active)
SELECT '[DEV] Manichiură', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 'nails', 60, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Manichiură');

INSERT INTO services (name, description, category, duration_minutes, price, active)
SELECT '[DEV] Pedichiură', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 'nails', 60, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Pedichiură');

INSERT INTO specialists (user_id, name, email, phone, active)
SELECT NULL, '[DEV] Fondatoare 1', NULL, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM specialists WHERE name = '[DEV] Fondatoare 1');

INSERT INTO specialists (user_id, name, email, phone, active)
SELECT NULL, '[DEV] Fondatoare 2', NULL, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM specialists WHERE name = '[DEV] Fondatoare 2');

INSERT IGNORE INTO specialist_services (specialist_id, service_id, price, duration_minutes, active)
SELECT sp.id, sv.id, sv.price, sv.duration_minutes, 1
FROM specialists sp
CROSS JOIN services sv
WHERE sp.name IN ('[DEV] Fondatoare 1', '[DEV] Fondatoare 2')
	AND sv.name IN ('[DEV] Tuns', '[DEV] Coafat', '[DEV] Vopsit', '[DEV] Manichiură', '[DEV] Pedichiură');

DELETE ss
FROM specialist_services ss
INNER JOIN services sv ON sv.id = ss.service_id
INNER JOIN specialists sp ON sp.id = ss.specialist_id
WHERE sp.specialization IS NOT NULL
	AND sp.specialization <> CASE sv.category
		WHEN 'hairstyle' THEN 'hairstylist'
		WHEN 'nails' THEN 'nails'
	END;

INSERT INTO specialist_schedule (specialist_id, day_of_week, start_time, end_time, active)
SELECT sp.id, days.day_of_week, '09:00:00', '17:00:00', 1
FROM specialists sp
CROSS JOIN (
	SELECT 1 AS day_of_week UNION ALL
	SELECT 2 UNION ALL
	SELECT 3 UNION ALL
	SELECT 4 UNION ALL
	SELECT 5
) days
WHERE sp.name IN ('[DEV] Fondatoare 1', '[DEV] Fondatoare 2')
	AND NOT EXISTS (
		SELECT 1
		FROM specialist_schedule existing
		WHERE existing.specialist_id = sp.id
			AND existing.day_of_week = days.day_of_week
	);

INSERT INTO specialist_schedule (specialist_id, day_of_week, start_time, end_time, active)
SELECT sp.id, 6, '10:00:00', '14:00:00', 1
FROM specialists sp
WHERE sp.name IN ('[DEV] Fondatoare 1', '[DEV] Fondatoare 2')
	AND NOT EXISTS (
		SELECT 1
		FROM specialist_schedule existing
		WHERE existing.specialist_id = sp.id
			AND existing.day_of_week = 6
	);

CREATE TABLE IF NOT EXISTS specialist_schedule_exceptions (
	id INT AUTO_INCREMENT PRIMARY KEY,
	specialist_id INT NOT NULL,
	date DATE NOT NULL,
	is_day_off TINYINT(1) NOT NULL DEFAULT 0,
	start_time TIME NULL,
	end_time TIME NULL,
	note VARCHAR(255) NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY ux_specialist_schedule_exception_date (specialist_id, date),
	INDEX idx_specialist_schedule_exceptions_lookup (specialist_id, date, is_day_off),
	CONSTRAINT fk_specialist_schedule_exceptions_specialist
		FOREIGN KEY (specialist_id) REFERENCES specialists(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT chk_specialist_schedule_exceptions_day_off CHECK (
			(is_day_off = 1 AND start_time IS NULL AND end_time IS NULL)
			OR (is_day_off = 0 AND start_time IS NOT NULL AND end_time IS NOT NULL AND start_time < end_time)
	)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
