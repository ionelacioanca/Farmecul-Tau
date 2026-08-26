<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/booking.php';

setSalonTimezone();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

$serviceId = filter_var($_GET['service_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$specialistId = filter_var($_GET['specialist_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$dateInput = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$date = $dateInput !== '' ? parseBookingDate($dateInput) : null;

if ($serviceId === false || $specialistId === false || $date === null) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'service_id, specialist_id and a valid date are required.',
	]);
}

$timezone = getSalonTimezone();
$today = new DateTimeImmutable('today', $timezone);

if ($date < $today) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'Date cannot be before today.',
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';

	$bookingContext = getBookingContext($pdo, $serviceId, $specialistId);

	if ($bookingContext === null) {
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Service or specialist is not available.',
		]);
	}

	$durationMinutes = (int) $bookingContext['duration_minutes'];

	if ($durationMinutes <= 0) {
		sendJsonResponse(409, [
			'success' => false,
			'error' => 'Service duration is not configured correctly.',
		]);
	}

	$schedules = getSpecialistSchedulesForDate($pdo, $specialistId, $date);

	if ($schedules === []) {
		sendJsonResponse(200, [
			'success' => true,
			'date' => $date->format('Y-m-d'),
			'slots' => [],
		]);
	}

	$slotIncrement = new DateInterval('PT30M');
	$serviceDuration = new DateInterval('PT' . $durationMinutes . 'M');
	$slots = [];

	foreach ($schedules as $schedule) {
		[$startHour, $startMinute] = array_map('intval', explode(':', (string) $schedule['start_time']));
		[$endHour, $endMinute] = array_map('intval', explode(':', (string) $schedule['end_time']));

		$scheduleStart = $date->setTime($startHour, $startMinute);
		$scheduleEnd = $date->setTime($endHour, $endMinute);

		for ($candidateStart = $scheduleStart; $candidateStart->add($serviceDuration) <= $scheduleEnd; $candidateStart = $candidateStart->add($slotIncrement)) {
			$candidateEnd = $candidateStart->add($serviceDuration);

			if (isBookingSlotAvailable($pdo, $specialistId, $candidateStart, $candidateEnd)) {
				$slots[] = $candidateStart->format('H:i');
			}
		}
	}

	$slots = array_values(array_unique($slots));
	sort($slots);

	sendJsonResponse(200, [
		'success' => true,
		'date' => $date->format('Y-m-d'),
		'slots' => $slots,
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau availability API failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Disponibilitatea nu a putut fi calculată.',
	]);
}
