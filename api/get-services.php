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

try {
	require_once __DIR__ . '/../includes/db.php';

	$statement = $pdo->prepare(
		'SELECT id, name, description, duration_minutes, price
		 FROM services
		 WHERE active = 1
		 ORDER BY name ASC'
	);
	$statement->execute();

	$services = array_map(
		static fn (array $service): array => [
			'id' => (int) $service['id'],
			'name' => (string) $service['name'],
			'description' => $service['description'] !== null ? (string) $service['description'] : null,
			'duration_minutes' => (int) $service['duration_minutes'],
			'price' => $service['price'] !== null ? (float) $service['price'] : null,
		],
		$statement->fetchAll()
	);

	sendJsonResponse(200, [
		'success' => true,
		'services' => $services,
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau services API failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Serviciile nu au putut fi încărcate.',
	]);
}
