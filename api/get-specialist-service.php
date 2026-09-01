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

if ($serviceId === false || $specialistId === false) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'service_id and specialist_id are required.',
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';

	$bookingContext = getBookingContext($pdo, $serviceId, $specialistId);

	if ($bookingContext === null) {
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Serviciul nu este disponibil pentru specialistul ales.',
		]);
	}

	sendJsonResponse(200, [
		'success' => true,
		'service' => [
			'id' => (int) $bookingContext['service_id'],
			'name' => (string) $bookingContext['service_name'],
		],
		'specialist' => [
			'id' => (int) $bookingContext['specialist_id'],
			'name' => (string) $bookingContext['specialist_name'],
		],
		'price' => (float) $bookingContext['price'],
		'duration_minutes' => (int) $bookingContext['duration_minutes'],
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau specialist service API failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Detaliile serviciului nu au putut fi incarcate.',
	]);
}
