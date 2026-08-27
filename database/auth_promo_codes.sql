CREATE TABLE IF NOT EXISTS users (
	id INT AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(150) NOT NULL,
	email VARCHAR(255) NOT NULL UNIQUE,
	password_hash VARCHAR(255) NOT NULL,
	role ENUM('customer', 'specialist', 'admin') NOT NULL DEFAULT 'customer',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promo_codes (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	reward_id INT NOT NULL,
	code VARCHAR(50) NOT NULL UNIQUE,
	status ENUM('active', 'used', 'expired') NOT NULL DEFAULT 'active',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	expires_at DATETIME NOT NULL,
	used_at DATETIME NULL,
	INDEX idx_promo_codes_user_status_expires (user_id, status, expires_at),
	INDEX idx_promo_codes_reward_id (reward_id),
	CONSTRAINT fk_promo_codes_user
		FOREIGN KEY (user_id) REFERENCES users(id)
		ON UPDATE CASCADE
		ON DELETE CASCADE,
	CONSTRAINT fk_promo_codes_reward
		FOREIGN KEY (reward_id) REFERENCES promo_rewards(id)
		ON UPDATE CASCADE
	ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE promo_codes
	MODIFY status ENUM('active', 'used', 'expired') NOT NULL DEFAULT 'active';

ALTER TABLE users
	ADD COLUMN IF NOT EXISTS role ENUM('customer', 'specialist', 'admin') NOT NULL DEFAULT 'customer'
	AFTER password_hash;

ALTER TABLE users
	MODIFY role ENUM('customer', 'specialist', 'admin') NOT NULL DEFAULT 'customer';

UPDATE users
SET role = 'customer'
WHERE role IS NULL;
