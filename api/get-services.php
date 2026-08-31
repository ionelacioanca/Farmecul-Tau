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
		'SELECT DISTINCT sv.id, sv.name, sv.description, sv.category
		 FROM services sv
		 INNER JOIN specialist_services ss ON ss.service_id = sv.id
		 INNER JOIN specialists sp ON sp.id = ss.specialist_id
		 WHERE sv.active = 1
			AND ss.active = 1
			AND ss.price IS NOT NULL
			AND ss.price >= 0
			AND ss.duration_minutes IS NOT NULL
			AND ss.duration_minutes BETWEEN 5 AND 480
			AND sp.active = 1
			AND sp.specialization = CASE sv.category
				WHEN \'hairstyle\' THEN \'hairstylist\'
				WHEN \'nails\' THEN \'nails\'
			END
		 ORDER BY sv.name ASC'
	);
	$statement->execute();

	$services = array_map(
		static fn (array $service): array => [
			'id' => (int) $service['id'],
			'name' => (string) $service['name'],
			'description' => $service['description'] !== null ? (string) $service['description'] : null,
			'category' => (string) $service['category'],
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
