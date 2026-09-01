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
