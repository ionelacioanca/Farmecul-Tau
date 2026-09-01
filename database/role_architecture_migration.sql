-- Farmecul Tau role architecture migration
-- Safe for existing data: does not delete rows and does not auto-link admins to specialists.

ALTER TABLE users
	MODIFY role ENUM('customer', 'specialist', 'admin') NOT NULL DEFAULT 'customer';

ALTER TABLE specialists
	ADD COLUMN IF NOT EXISTS specialization ENUM('hairstylist', 'nails') NULL
	AFTER email;

ALTER TABLE specialists
	ADD UNIQUE INDEX IF NOT EXISTS ux_specialists_user_id (user_id);

ALTER TABLE specialists
	ADD INDEX IF NOT EXISTS idx_specialists_specialization_active (specialization, active);

-- Link the two founder admin accounts manually after confirming the exact records.
-- Replace the sample emails and specialist names before running.
--
-- UPDATE specialists sp
-- INNER JOIN users u ON u.email = 'founder-hairstylist@example.com'
-- SET sp.user_id = u.id,
-- 	sp.name = u.name,
-- 	sp.email = u.email,
-- 	sp.specialization = 'hairstylist'
-- WHERE sp.name = '[DEV] Fondatoare 1'
-- 	AND u.role = 'admin';
--
-- UPDATE specialists sp
-- INNER JOIN users u ON u.email = 'founder-nails@example.com'
-- SET sp.user_id = u.id,
-- 	sp.name = u.name,
-- 	sp.email = u.email,
-- 	sp.specialization = 'nails'
-- WHERE sp.name = '[DEV] Fondatoare 2'
-- 	AND u.role = 'admin';
