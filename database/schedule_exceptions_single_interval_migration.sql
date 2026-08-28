CREATE TABLE IF NOT EXISTS specialist_schedule_duplicates_archive LIKE specialist_schedule;

INSERT IGNORE INTO specialist_schedule_duplicates_archive
SELECT ss.*
FROM specialist_schedule ss
INNER JOIN (
	SELECT specialist_id, day_of_week, COUNT(*) AS row_count
	FROM specialist_schedule
	GROUP BY specialist_id, day_of_week
	HAVING COUNT(*) > 1
) duplicates
	ON duplicates.specialist_id = ss.specialist_id
	AND duplicates.day_of_week = ss.day_of_week;

CREATE TEMPORARY TABLE specialist_schedule_keep AS
SELECT id
FROM (
	SELECT
		id,
		ROW_NUMBER() OVER (
			PARTITION BY specialist_id, day_of_week
			ORDER BY active DESC, start_time ASC, end_time DESC, id ASC
		) AS row_rank
	FROM specialist_schedule
) ranked
WHERE row_rank = 1;

DELETE ss
FROM specialist_schedule ss
LEFT JOIN specialist_schedule_keep keep_row ON keep_row.id = ss.id
WHERE keep_row.id IS NULL;

DROP TEMPORARY TABLE specialist_schedule_keep;

ALTER TABLE specialist_schedule
	ADD UNIQUE INDEX IF NOT EXISTS ux_specialist_schedule_day (specialist_id, day_of_week);

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
