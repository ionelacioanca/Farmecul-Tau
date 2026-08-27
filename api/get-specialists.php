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

$serviceId = null;

if (isset($_GET['service_id']) && $_GET['service_id'] !== '') {
	$serviceId = filter_var($_GET['service_id'], FILTER_VALIDATE_INT, [
		'options' => ['min_range' => 1],
	]);

	if ($serviceId === false) {
		sendJsonResponse(422, [
			'success' => false,
			'error' => 'service_id must be a valid service.',
		]);
	}
}

try {
	require_once __DIR__ . '/../includes/db.php';

	if ($serviceId !== null) {
		$serviceStatement = $pdo->prepare(
			'SELECT id, category
			 FROM services
			 WHERE id = :service_id AND active = 1
			 LIMIT 1'
		);
		$serviceStatement->execute(['service_id' => $serviceId]);

		if ($serviceStatement->fetch() === false) {
			sendJsonResponse(404, [
				'success' => false,
				'error' => 'Service not found.',
			]);
		}

		$statement = $pdo->prepare(
			'SELECT DISTINCT sp.id, sp.name
			 FROM specialists sp
			 INNER JOIN specialist_services ss ON ss.specialist_id = sp.id
			 INNER JOIN services sv ON sv.id = ss.service_id
			 WHERE sp.active = 1
				AND sv.active = 1
				AND sv.id = :service_id
				AND sp.specialization = CASE sv.category
					WHEN \'hairstyle\' THEN \'hairstylist\'
					WHEN \'nails\' THEN \'nails\'
				END
			 ORDER BY sp.name ASC'
		);
		$statement->execute(['service_id' => $serviceId]);
	} else {
		$statement = $pdo->prepare(
			'SELECT id, name
			 FROM specialists
			 WHERE active = 1
			 ORDER BY name ASC'
		);
		$statement->execute();
	}

	$specialists = array_map(
		static fn (array $specialist): array => [
			'id' => (int) $specialist['id'],
			'name' => (string) $specialist['name'],
		],
		$statement->fetchAll()
	);

	sendJsonResponse(200, [
		'success' => true,
		'specialists' => $specialists,
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau specialists API failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Specialiștii nu au putut fi încărcați.',
	]);
}
