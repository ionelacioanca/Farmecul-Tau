<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account-appointments.php';
require_once __DIR__ . '/../includes/offer-helpers.php';
require_once __DIR__ . '/../includes/db.php';

setSalonTimezone();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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

$appointmentId = filter_var($_GET['appointment_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$dateInput = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$date = $dateInput !== '' ? parseBookingDate($dateInput) : null;

if ($appointmentId === false || $date === null) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'Programarea si data sunt obligatorii.',
	]);
}

if ($date < new DateTimeImmutable('today', getSalonTimezone())) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'Data nu poate fi in trecut.',
	]);
}

try {
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

	if ((string) $appointment['booking_type'] === 'offer') {
		$bookingContext = getOfferBookingContext(
			$pdo,
			(int) $appointment['offer_id'],
			(int) $appointment['specialist_id'],
			$date
		);
	} else {
		$bookingContext = getBookingContext(
			$pdo,
			(int) $appointment['service_id'],
			(int) $appointment['specialist_id']
		);
	}

	if ($bookingContext === null) {
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Programarea nu poate fi mutata in aceasta zi.',
		]);
	}

	$durationMinutes = (int) $appointment['duration_minutes_at_booking'];
	$slots = getAvailableBookingSlots($pdo, (int) $appointment['specialist_id'], $date, $durationMinutes, $appointmentId);

	sendJsonResponse(200, [
		'success' => true,
		'date' => $date->format('Y-m-d'),
		'duration_minutes' => $durationMinutes,
		'slots' => $slots,
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau customer appointment availability failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Disponibilitatea nu a putut fi calculata.',
	]);
}
