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

function getServiceCategoryForSpecialization(?string $specialization): ?string
{
	return match ($specialization) {
		'hairstylist' => 'hairstyle',
		'nails' => 'nails',
		default => null,
	};
}

function normalizeServiceName(string $name): string
{
	$normalized = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

	return function_exists('mb_strtolower')
		? mb_strtolower($normalized, 'UTF-8')
		: strtolower($normalized);
}

function isValidBookingTimeValue(string $time): bool
{
	return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
}

function parseAdminDecimal(string $value): ?float
{
	$normalized = str_replace(',', '.', trim($value));

	if ($normalized === '' || !is_numeric($normalized)) {
		return null;
	}

	return (float) $normalized;
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

function getBookingContext(PDO $pdo, int $serviceId, int $specialistId, bool $lock = false): ?array
{
	$lockClause = $lock ? ' FOR UPDATE' : '';
	$statement = $pdo->prepare(
		'SELECT
			sv.id AS service_id,
			sv.name AS service_name,
			sv.category AS service_category,
			ss.price,
			ss.duration_minutes,
			ss.active AS specialist_service_active,
			sp.id AS specialist_id,
			sp.name AS specialist_name,
			sp.specialization AS specialist_specialization
		 FROM services sv
		 INNER JOIN specialist_services ss ON ss.service_id = sv.id
		 INNER JOIN specialists sp ON sp.id = ss.specialist_id
		 WHERE sv.id = :service_id
			AND sp.id = :specialist_id
			AND sv.active = 1
			AND sp.active = 1
			AND ss.active = 1
			AND ss.price IS NOT NULL
			AND ss.price >= 0
			AND ss.duration_minutes IS NOT NULL
			AND ss.duration_minutes BETWEEN 5 AND 480
			AND sp.specialization = CASE sv.category
				WHEN \'hairstyle\' THEN \'hairstylist\'
				WHEN \'nails\' THEN \'nails\'
			END
		 LIMIT 1' . $lockClause
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

function getSpecialistScheduleExceptionForDate(PDO $pdo, int $specialistId, DateTimeImmutable $date): ?array
{
	$statement = $pdo->prepare(
		'SELECT is_day_off, start_time, end_time, note
		 FROM specialist_schedule_exceptions
		 WHERE specialist_id = :specialist_id
			AND date = :date
		 LIMIT 1'
	);
	$statement->execute([
		'specialist_id' => $specialistId,
		'date' => $date->format('Y-m-d'),
	]);
	$exception = $statement->fetch();

	return $exception !== false ? $exception : null;
}

function getSpecialistSchedulesForDate(PDO $pdo, int $specialistId, DateTimeImmutable $date): array
{
	$exception = getSpecialistScheduleExceptionForDate($pdo, $specialistId, $date);

	if ($exception !== null) {
		if ((int) $exception['is_day_off'] === 1) {
			return [];
		}

		return [[
			'start_time' => (string) $exception['start_time'],
			'end_time' => (string) $exception['end_time'],
		]];
	}

	$statement = $pdo->prepare(
		'SELECT start_time, end_time
		 FROM specialist_schedule
		 WHERE specialist_id = :specialist_id
			AND day_of_week = :day_of_week
			AND active = 1
		 LIMIT 1'
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

function getAvailableBookingSlots(PDO $pdo, int $specialistId, DateTimeImmutable $date, int $durationMinutes): array
{
	if ($durationMinutes < 5 || $durationMinutes > 480) {
		return [];
	}

	$schedules = getSpecialistSchedulesForDate($pdo, $specialistId, $date);

	if ($schedules === []) {
		return [];
	}

	$slotIncrement = new DateInterval('PT30M');
	$bookingDuration = new DateInterval('PT' . $durationMinutes . 'M');
	$slots = [];

	foreach ($schedules as $schedule) {
		[$startHour, $startMinute] = array_map('intval', explode(':', (string) $schedule['start_time']));
		[$endHour, $endMinute] = array_map('intval', explode(':', (string) $schedule['end_time']));

		$scheduleStart = $date->setTime($startHour, $startMinute);
		$scheduleEnd = $date->setTime($endHour, $endMinute);

		for ($candidateStart = $scheduleStart; $candidateStart->add($bookingDuration) <= $scheduleEnd; $candidateStart = $candidateStart->add($slotIncrement)) {
			$candidateEnd = $candidateStart->add($bookingDuration);

			if (isBookingSlotAvailable($pdo, $specialistId, $candidateStart, $candidateEnd)) {
				$slots[] = $candidateStart->format('H:i');
			}
		}
	}

	$slots = array_values(array_unique($slots));
	sort($slots);

	return $slots;
}
