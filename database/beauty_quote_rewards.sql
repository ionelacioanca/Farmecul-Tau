CREATE TABLE IF NOT EXISTS promo_rewards (
	id INT AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(150) NOT NULL,
	description TEXT NOT NULL,
	reward_type ENUM(
		'percentage',
		'fixed',
		'free_service',
		'gift',
		'product_discount'
	) NOT NULL,
	reward_value DECIMAL(10,2) NULL,
	validity_days INT DEFAULT 30,
	active TINYINT(1) DEFAULT 1,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE beauty_quotes
	ADD COLUMN IF NOT EXISTS reward_id INT NULL;

SET @constraint_exists = (
	SELECT COUNT(*)
	FROM information_schema.REFERENTIAL_CONSTRAINTS
	WHERE CONSTRAINT_SCHEMA = DATABASE()
		AND CONSTRAINT_NAME = 'fk_beauty_quotes_reward'
		AND TABLE_NAME = 'beauty_quotes'
);

SET @add_constraint = IF(
	@constraint_exists = 0,
	'ALTER TABLE beauty_quotes ADD CONSTRAINT fk_beauty_quotes_reward FOREIGN KEY (reward_id) REFERENCES promo_rewards(id) ON UPDATE CASCADE ON DELETE SET NULL',
	'SELECT 1'
);

PREPARE add_reward_fk FROM @add_constraint;
EXECUTE add_reward_fk;
DEALLOCATE PREPARE add_reward_fk;

UPDATE promo_rewards
SET description = 'O mică atenție elegantă pentru următoarea ta vizită în salon.'
WHERE name = '10% reducere la orice serviciu';

UPDATE promo_rewards
SET name = '20 lei reducere la manichiură',
	description = 'Bucură-te de o reducere la serviciul tău preferat de manichiură.'
WHERE name = '20 lei reducere la manichiura';

UPDATE promo_rewards
SET description = 'Adaugă un plus de îngrijire culorii tale, din partea noastră.'
WHERE name = 'Tratament de hidratare gratuit la vopsit';

UPDATE promo_rewards
SET description = 'Alege produsele potrivite pentru ritualul tău de acasă.'
WHERE name = '15% reducere la produse';

UPDATE promo_rewards
SET description = 'Completează-ți programarea cu un finisaj rafinat, oferit cadou.'
WHERE name = 'Styling gratuit la un serviciu de coafor';

INSERT INTO promo_rewards (name, description, reward_type, reward_value, validity_days)
SELECT '10% reducere la orice serviciu', 'O mică atenție elegantă pentru următoarea ta vizită în salon.', 'percentage', 10.00, 30
WHERE NOT EXISTS (SELECT 1 FROM promo_rewards WHERE name = '10% reducere la orice serviciu');

INSERT INTO promo_rewards (name, description, reward_type, reward_value, validity_days)
SELECT '20 lei reducere la manichiură', 'Bucură-te de o reducere la serviciul tău preferat de manichiură.', 'fixed', 20.00, 30
WHERE NOT EXISTS (SELECT 1 FROM promo_rewards WHERE name = '20 lei reducere la manichiură');

INSERT INTO promo_rewards (name, description, reward_type, reward_value, validity_days)
SELECT 'Tratament de hidratare gratuit la vopsit', 'Adaugă un plus de îngrijire culorii tale, din partea noastră.', 'free_service', NULL, 30
WHERE NOT EXISTS (SELECT 1 FROM promo_rewards WHERE name = 'Tratament de hidratare gratuit la vopsit');

INSERT INTO promo_rewards (name, description, reward_type, reward_value, validity_days)
SELECT '15% reducere la produse', 'Alege produsele potrivite pentru ritualul tău de acasă.', 'product_discount', 15.00, 30
WHERE NOT EXISTS (SELECT 1 FROM promo_rewards WHERE name = '15% reducere la produse');

INSERT INTO promo_rewards (name, description, reward_type, reward_value, validity_days)
SELECT 'Styling gratuit la un serviciu de coafor', 'Completează-ți programarea cu un finisaj rafinat, oferit cadou.', 'gift', NULL, 30
WHERE NOT EXISTS (SELECT 1 FROM promo_rewards WHERE name = 'Styling gratuit la un serviciu de coafor');

UPDATE beauty_quotes
SET reward_id = (
	SELECT id
	FROM promo_rewards
	WHERE active = 1
	ORDER BY id
	LIMIT 1
)
WHERE reward_id IS NULL;
