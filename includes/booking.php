<?php
declare(strict_types=1);

function getSalonTimezone(): DateTimeZone
{
	return new DateTimeZone('Europe/Bucharest');
}

function setSalonTimezone(): void
{
	date_default_timezone_set('Europe/Bucharest');
}

function parseBookingDate(string $date): ?DateTimeImmutable
{
	$timezone = getSalonTimezone();
	$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);

	if (!$parsedDate instanceof DateTimeImmutable) {
		return null;
	}

	return $parsedDate->format('Y-m-d') === $date ? $parsedDate : null;
}

function intervalsOverlap(DateTimeImmutable $candidateStart, DateTimeImmutable $candidateEnd, array $interval): bool
{
	$timezone = getSalonTimezone();
	$existingStart = new DateTimeImmutable((string) $interval['start_datetime'], $timezone);
	$existingEnd = new DateTimeImmutable((string) $interval['end_datetime'], $timezone);

	return $candidateStart < $existingEnd && $candidateEnd > $existingStart;
}

function getBookingContext(PDO $pdo, int $serviceId, int $specialistId): ?array
{
	$statement = $pdo->prepare(
		'SELECT
			sv.id AS service_id,
			sv.name AS service_name,
			sv.duration_minutes,
			sp.id AS specialist_id,
			sp.name AS specialist_name
		 FROM services sv
		 INNER JOIN specialist_services ss ON ss.service_id = sv.id
		 INNER JOIN specialists sp ON sp.id = ss.specialist_id
		 WHERE sv.id = :service_id
			AND sp.id = :specialist_id
			AND sv.active = 1
			AND sp.active = 1
		 LIMIT 1'
	);
	$statement->execute([
		'service_id' => $serviceId,
		'specialist_id' => $specialistId,
	]);
	$context = $statement->fetch();

	return $context !== false ? $context : null;
}

function parseBookingTime(DateTimeImmutable $date, string $time): ?DateTimeImmutable
{
	if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
		return null;
	}

	[$hour, $minute] = array_map('intval', explode(':', $time));

	return $date->setTime($hour, $minute);
}

function getBookingLockName(int $specialistId, DateTimeImmutable $date): string
{
	return 'booking:' . $specialistId . ':' . $date->format('Y-m-d');
}

function acquireBookingLock(PDO $pdo, string $lockName, int $timeoutSeconds = 5): bool
{
	$statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds) AS lock_acquired');
	$statement->execute([
		'lock_name' => $lockName,
		'timeout_seconds' => $timeoutSeconds,
	]);
	$result = $statement->fetch();

	return $result !== false && (int) $result['lock_acquired'] === 1;
}

function releaseBookingLock(PDO $pdo, string $lockName): void
{
	$statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
	$statement->execute(['lock_name' => $lockName]);
}

function getSpecialistSchedulesForDate(PDO $pdo, int $specialistId, DateTimeImmutable $date): array
{
	$statement = $pdo->prepare(
		'SELECT start_time, end_time
		 FROM specialist_schedule
		 WHERE specialist_id = :specialist_id
			AND day_of_week = :day_of_week
			AND active = 1
		 ORDER BY start_time ASC'
	);
	$statement->execute([
		'specialist_id' => $specialistId,
		'day_of_week' => (int) $date->format('N'),
	]);

	return $statement->fetchAll();
}

function bookingSlotFitsSchedule(DateTimeImmutable $candidateStart, DateTimeImmutable $candidateEnd, array $schedules): bool
{
	foreach ($schedules as $schedule) {
		[$startHour, $startMinute] = array_map('intval', explode(':', (string) $schedule['start_time']));
		[$endHour, $endMinute] = array_map('intval', explode(':', (string) $schedule['end_time']));

		$scheduleStart = $candidateStart->setTime($startHour, $startMinute);
		$scheduleEnd = $candidateStart->setTime($endHour, $endMinute);

		if ($candidateStart >= $scheduleStart && $candidateEnd <= $scheduleEnd) {
			return true;
		}
	}

	return false;
}

function bookingSlotHasOverlaps(
	PDO $pdo,
	int $specialistId,
	DateTimeImmutable $candidateStart,
	DateTimeImmutable $candidateEnd,
	bool $lock = false,
	?int $excludedAppointmentId = null
): bool
{
	$lockClause = $lock ? ' FOR UPDATE' : '';
	$excludeClause = $excludedAppointmentId !== null ? ' AND id <> :excluded_appointment_id' : '';

	$appointmentStatement = $pdo->prepare(
		"SELECT id
		 FROM appointments
		 WHERE specialist_id = :specialist_id
			AND status IN ('pending', 'approved')
			AND start_datetime < :candidate_end
			AND end_datetime > :candidate_start" .
			$excludeClause .
		"
		 LIMIT 1" . $lockClause
	);
	$appointmentParams = [
		'specialist_id' => $specialistId,
		'candidate_start' => $candidateStart->format('Y-m-d H:i:s'),
		'candidate_end' => $candidateEnd->format('Y-m-d H:i:s'),
	];

	if ($excludedAppointmentId !== null) {
		$appointmentParams['excluded_appointment_id'] = $excludedAppointmentId;
	}

	$appointmentStatement->execute($appointmentParams);

	if ($appointmentStatement->fetch() !== false) {
		return true;
	}

	$blockedStatement = $pdo->prepare(
		'SELECT id
		 FROM blocked_slots
		 WHERE specialist_id = :specialist_id
			AND start_datetime < :candidate_end
			AND end_datetime > :candidate_start
		 LIMIT 1' . $lockClause
	);
	$blockedStatement->execute([
		'specialist_id' => $specialistId,
		'candidate_start' => $candidateStart->format('Y-m-d H:i:s'),
		'candidate_end' => $candidateEnd->format('Y-m-d H:i:s'),
	]);

	return $blockedStatement->fetch() !== false;
}

function isBookingSlotAvailable(
	PDO $pdo,
	int $specialistId,
	DateTimeImmutable $candidateStart,
	DateTimeImmutable $candidateEnd,
	bool $lock = false,
	?int $excludedAppointmentId = null
): bool
{
	if ($candidateStart <= new DateTimeImmutable('now', getSalonTimezone())) {
		return false;
	}

	$schedules = getSpecialistSchedulesForDate($pdo, $specialistId, $candidateStart);

	if (!bookingSlotFitsSchedule($candidateStart, $candidateEnd, $schedules)) {
		return false;
	}

	return !bookingSlotHasOverlaps($pdo, $specialistId, $candidateStart, $candidateEnd, $lock, $excludedAppointmentId);
}
