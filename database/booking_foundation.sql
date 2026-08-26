CREATE TABLE IF NOT EXISTS services (
	id INT AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(150) NOT NULL,
	description TEXT NULL,
	duration_minutes INT NOT NULL,
	price DECIMAL(10,2) NULL,
	active TINYINT(1) DEFAULT 1,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS specialists (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NULL,
	name VARCHAR(150) NOT NULL,
	email VARCHAR(255) NULL,
	phone VARCHAR(50) NULL,
	active TINYINT(1) DEFAULT 1,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_specialists_user_id (user_id),
	CONSTRAINT fk_specialists_user
		FOREIGN KEY (user_id) REFERENCES users(id)
		ON UPDATE CASCADE
		ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS specialist_services (
	specialist_id INT NOT NULL,
	service_id INT NOT NULL,
	PRIMARY KEY (specialist_id, service_id),
	INDEX idx_specialist_services_service_id (service_id),
	CONSTRAINT fk_specialist_services_specialist
		FOREIGN KEY (specialist_id) REFERENCES specialists(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT fk_specialist_services_service
		FOREIGN KEY (service_id) REFERENCES services(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS specialist_schedule (
	id INT AUTO_INCREMENT PRIMARY KEY,
	specialist_id INT NOT NULL,
	day_of_week TINYINT NOT NULL COMMENT '1 = Monday, 2 = Tuesday, ..., 7 = Sunday',
	start_time TIME NOT NULL,
	end_time TIME NOT NULL,
	active TINYINT(1) DEFAULT 1,
	INDEX idx_specialist_schedule_lookup (specialist_id, day_of_week, active),
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
	customer_email VARCHAR(255) NOT NULL,
	customer_phone VARCHAR(50) NULL,
	service_id INT NOT NULL,
	specialist_id INT NOT NULL,
	start_datetime DATETIME NOT NULL,
	end_datetime DATETIME NOT NULL,
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

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT '[DEV] Tuns', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 45, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Tuns');

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT '[DEV] Coafat', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 60, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Coafat');

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT '[DEV] Vopsit', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 120, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Vopsit');

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT '[DEV] Manichiură', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 60, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Manichiură');

INSERT INTO services (name, description, duration_minutes, price, active)
SELECT '[DEV] Pedichiură', 'Serviciu exemplu pentru dezvoltarea sistemului de programări.', 60, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM services WHERE name = '[DEV] Pedichiură');

INSERT INTO specialists (user_id, name, email, phone, active)
SELECT NULL, '[DEV] Fondatoare 1', NULL, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM specialists WHERE name = '[DEV] Fondatoare 1');

INSERT INTO specialists (user_id, name, email, phone, active)
SELECT NULL, '[DEV] Fondatoare 2', NULL, NULL, 1
WHERE NOT EXISTS (SELECT 1 FROM specialists WHERE name = '[DEV] Fondatoare 2');

INSERT IGNORE INTO specialist_services (specialist_id, service_id)
SELECT sp.id, sv.id
FROM specialists sp
CROSS JOIN services sv
WHERE sp.name IN ('[DEV] Fondatoare 1', '[DEV] Fondatoare 2')
	AND sv.name IN ('[DEV] Tuns', '[DEV] Coafat', '[DEV] Vopsit', '[DEV] Manichiură', '[DEV] Pedichiură');

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
			AND existing.start_time = '09:00:00'
			AND existing.end_time = '17:00:00'
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
			AND existing.start_time = '10:00:00'
			AND existing.end_time = '14:00:00'
	);
