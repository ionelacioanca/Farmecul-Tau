<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account-appointments.php';
require_once __DIR__ . '/../includes/offer-helpers.php';
require_once __DIR__ . '/../includes/db.php';

setSalonTimezone();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

$currentUser = getCurrentUser($pdo);

if ($currentUser === null) {
	sendJsonResponse(401, [
		'success' => false,
		'error' => 'Trebuie sa fii autentificat.',
	]);
}

$payload = readJsonRequestBody();
$appointmentId = filter_var($payload['appointment_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$dateInput = isset($payload['date']) ? trim((string) $payload['date']) : '';
$timeInput = isset($payload['time']) ? trim((string) $payload['time']) : '';
$date = $dateInput !== '' ? parseBookingDate($dateInput) : null;
$candidateStart = $date !== null ? parseBookingTime($date, $timeInput) : null;
$errors = [];

if ($appointmentId === false) {
	$errors['appointment_id'] = 'Programarea nu este valida.';
}

if ($date === null) {
	$errors['date'] = 'Te rugam sa alegi o data valida.';
} elseif ($date < new DateTimeImmutable('today', getSalonTimezone())) {
	$errors['date'] = 'Data nu poate fi in trecut.';
}

if ($candidateStart === null) {
	$errors['time'] = 'Te rugam sa alegi o ora valida.';
} elseif (!in_array((int) $candidateStart->format('i'), [0, 30], true)) {
	$errors['time'] = 'Te rugam sa alegi o ora disponibila din lista.';
}

if ($errors !== []) {
	sendJsonResponse(422, [
		'success' => false,
		'errors' => $errors,
	]);
}

try {
	$lockAcquired = false;

	$appointment = getAccountAppointment($pdo, $appointmentId, (int) $currentUser['id']);

	if ($appointment === null) {
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Programarea nu a fost gasita.',
		]);
	}

	if (!isAccountAppointmentActive($appointment)) {
		sendJsonResponse(409, [
			'success' => false,
			'error' => 'Doar programarile active pot fi reprogramate.',
		]);
	}

	$specialistId = (int) $appointment['specialist_id'];
	$lockName = getBookingLockName($specialistId, $date);
	$lockAcquired = acquireBookingLock($pdo, $lockName);

	if (!$lockAcquired) {
		$pdo->rollBack();
		sendJsonResponse(409, [
			'success' => false,
			'code' => 'slot_unavailable',
			'message' => 'Ora selectata nu mai este disponibila. Alege un alt interval.',
		]);
	}

	$pdo->beginTransaction();

	$appointment = getAccountAppointment($pdo, $appointmentId, (int) $currentUser['id'], true);

	if ($appointment === null) {
		$pdo->rollBack();
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Programarea nu a fost gasita.',
		]);
	}

	if (!isAccountAppointmentActive($appointment)) {
		$pdo->rollBack();
		sendJsonResponse(409, [
			'success' => false,
			'error' => 'Doar programarile active pot fi reprogramate.',
		]);
	}

	$bookingContext = (string) $appointment['booking_type'] === 'offer'
		? getOfferBookingContext($pdo, (int) $appointment['offer_id'], $specialistId, $date, true)
		: getBookingContext($pdo, (int) $appointment['service_id'], $specialistId, true);

	if ($bookingContext === null) {
		$pdo->rollBack();
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Programarea nu poate fi mutata in acest interval.',
		]);
	}

	$durationMinutes = (int) $appointment['duration_minutes_at_booking'];
	$candidateEnd = $candidateStart->add(new DateInterval('PT' . $durationMinutes . 'M'));

	if (!isBookingSlotAvailable($pdo, $specialistId, $candidateStart, $candidateEnd, true, $appointmentId)) {
		$pdo->rollBack();
		sendJsonResponse(409, [
			'success' => false,
			'code' => 'slot_unavailable',
			'message' => 'Ora selectata nu mai este disponibila. Alege un alt interval.',
		]);
	}

	$statement = $pdo->prepare(
		'UPDATE appointments
		 SET start_datetime = :start_datetime,
			end_datetime = :end_datetime
		 WHERE id = :appointment_id
			AND customer_user_id = :customer_user_id
		 LIMIT 1'
	);
	$statement->execute([
		'start_datetime' => $candidateStart->format('Y-m-d H:i:s'),
		'end_datetime' => $candidateEnd->format('Y-m-d H:i:s'),
		'appointment_id' => $appointmentId,
		'customer_user_id' => (int) $currentUser['id'],
	]);

	$pdo->commit();

	sendJsonResponse(200, [
		'success' => true,
		'appointment' => [
			'id' => $appointmentId,
			'date' => $date->format('Y-m-d'),
			'time' => $candidateStart->format('H:i'),
			'end_time' => $candidateEnd->format('H:i'),
			'status' => (string) $appointment['status'],
		],
		'message' => 'Programarea a fost reprogramata.',
	]);
} catch (Throwable $exception) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}

	error_log('Farmecul Tau customer reschedule appointment failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Programarea nu a putut fi reprogramata.',
	]);
} finally {
	if (isset($pdo, $lockAcquired, $lockName) && $pdo instanceof PDO && $lockAcquired) {
		try {
			releaseBookingLock($pdo, $lockName);
		} catch (Throwable $releaseException) {
			error_log('Farmecul Tau customer reschedule lock release failed: ' . $releaseException->getMessage());
		}
	}
}
