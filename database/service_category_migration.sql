-- Farmecul Tau service category migration
-- Adds explicit service categories and removes incompatible specialist-service assignments.

ALTER TABLE services
	ADD COLUMN IF NOT EXISTS category ENUM('hairstyle', 'nails') NULL
	AFTER description;

UPDATE services
SET category = 'hairstyle'
WHERE name IN ('[DEV] Tuns', '[DEV] Coafat', '[DEV] Vopsit');

UPDATE services
SET category = 'nails'
WHERE name IN ('[DEV] Manichiură', '[DEV] ManichiurÄƒ', '[DEV] Manichiur?', '[DEV] Pedichiură', '[DEV] PedichiurÄƒ', '[DEV] Pedichiur?');

-- Fallback for the current development seed data if the Romanian characters were imported with a replacement marker.
UPDATE services
SET category = 'nails'
WHERE id IN (4, 5)
	AND (category IS NULL OR category = '');

-- If this returns rows, assign each category manually before enforcing NOT NULL.
SELECT id, name
FROM services
WHERE category IS NULL;

ALTER TABLE services
	MODIFY category ENUM('hairstyle', 'nails') NOT NULL;

ALTER TABLE services
	ADD INDEX IF NOT EXISTS idx_services_category_active (category, active);

DELETE ss
FROM specialist_services ss
INNER JOIN services sv ON sv.id = ss.service_id
INNER JOIN specialists sp ON sp.id = ss.specialist_id
WHERE sp.specialization <> CASE sv.category
	WHEN 'hairstyle' THEN 'hairstylist'
	WHEN 'nails' THEN 'nails'
END;
