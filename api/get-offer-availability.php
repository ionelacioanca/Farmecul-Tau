<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/offer-helpers.php';

setSalonTimezone();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

$offerId = filter_var($_GET['offer_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$specialistId = filter_var($_GET['specialist_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$dateInput = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$date = $dateInput !== '' ? parseBookingDate($dateInput) : null;

if ($offerId === false || $specialistId === false || $date === null) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'offer_id, specialist_id and a valid date are required.',
	]);
}

if ($date < new DateTimeImmutable('today', getSalonTimezone())) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'Data nu poate fi în trecut.',
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';

	$bookingContext = getOfferBookingContext($pdo, $offerId, $specialistId, $date);

	if ($bookingContext === null) {
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Oferta sau specialistul nu este disponibil.',
		]);
	}

	$durationMinutes = (int) $bookingContext['duration_minutes'];
	$slots = getAvailableBookingSlots($pdo, $specialistId, $date, $durationMinutes);

	sendJsonResponse(200, [
		'success' => true,
		'date' => $date->format('Y-m-d'),
		'offer' => [
			'id' => (int) $bookingContext['offer_id'],
			'title' => (string) $bookingContext['offer_title'],
			'price' => (float) $bookingContext['price'],
			'duration_minutes' => $durationMinutes,
		],
		'specialist' => [
			'id' => (int) $bookingContext['specialist_id'],
			'name' => (string) $bookingContext['specialist_name'],
		],
		'price' => (float) $bookingContext['price'],
		'duration_minutes' => $durationMinutes,
		'slots' => $slots,
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau offer availability API failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Disponibilitatea ofertei nu a putut fi calculată.',
	]);
}
