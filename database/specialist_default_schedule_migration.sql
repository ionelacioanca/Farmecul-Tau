-- Adds the default working schedule for active specialists that have no active schedule.
-- Default: Monday-Friday 09:00-17:00, Saturday 10:00-14:00.

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
WHERE sp.active = 1
	AND NOT EXISTS (
		SELECT 1
		FROM specialist_schedule existing
		WHERE existing.specialist_id = sp.id
			AND existing.active = 1
	);

INSERT INTO specialist_schedule (specialist_id, day_of_week, start_time, end_time, active)
SELECT sp.id, 6, '10:00:00', '14:00:00', 1
FROM specialists sp
WHERE sp.active = 1
	AND NOT EXISTS (
		SELECT 1
		FROM specialist_schedule existing
		WHERE existing.specialist_id = sp.id
			AND existing.day_of_week = 6
			AND existing.active = 1
	);
