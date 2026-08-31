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
$dateInput = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$date = $dateInput !== '' ? parseBookingDate($dateInput) : new DateTimeImmutable('today', getSalonTimezone());

if ($offerId === false || $date === null) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'offer_id and a valid date are required.',
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';

	$today = new DateTimeImmutable('today', getSalonTimezone());
	$offer = getOfferById($pdo, $offerId);

	if (
		$offer === null
		|| (int) $offer['active'] !== 1
		|| $date < $today
		|| $date->format('Y-m-d') > (string) $offer['end_date']
	) {
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Oferta nu este disponibilă pentru data aleasă.',
		]);
	}

	$serviceIds = getOfferServiceIds($pdo, $offerId);

	if ($serviceIds === []) {
		sendJsonResponse(409, [
			'success' => false,
			'error' => 'Oferta nu are servicii incluse.',
		]);
	}

	$statement = $pdo->prepare(
		'SELECT sp.id, sp.name
		 FROM offer_specialists os
		 INNER JOIN specialists sp ON sp.id = os.specialist_id
		 WHERE os.offer_id = :offer_id
			AND sp.active = 1
		 ORDER BY sp.name ASC'
	);
	$statement->execute(['offer_id' => $offerId]);
	$specialists = [];

	foreach ($statement->fetchAll() as $specialist) {
		$specialistId = (int) $specialist['id'];

		if (!areOfferServicesCompatibleWithSpecialist($pdo, $serviceIds, $specialistId)) {
			continue;
		}

		$specialists[] = [
			'id' => $specialistId,
			'name' => (string) $specialist['name'],
			'price' => (float) $offer['price'],
			'duration_minutes' => (int) $offer['duration_minutes'],
		];
	}

	sendJsonResponse(200, [
		'success' => true,
		'offer' => [
			'id' => (int) $offer['id'],
			'title' => (string) $offer['title'],
			'price' => (float) $offer['price'],
			'duration_minutes' => (int) $offer['duration_minutes'],
			'start_date' => (string) $offer['start_date'],
			'end_date' => (string) $offer['end_date'],
		],
		'specialists' => $specialists,
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau offer specialists API failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Specialiștii ofertei nu au putut fi încărcați.',
	]);
}
