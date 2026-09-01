<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account-appointments.php';
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

if ($appointmentId === false) {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'Programarea nu este valida.',
	]);
}

try {
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
			'error' => 'Doar programarile active pot fi anulate.',
		]);
	}

	$statement = $pdo->prepare(
		"UPDATE appointments
		 SET status = 'cancelled'
		 WHERE id = :appointment_id
			AND customer_user_id = :customer_user_id
		 LIMIT 1"
	);
	$statement->execute([
		'appointment_id' => $appointmentId,
		'customer_user_id' => (int) $currentUser['id'],
	]);

	$pdo->commit();

	sendJsonResponse(200, [
		'success' => true,
		'appointment' => [
			'id' => $appointmentId,
			'status' => 'cancelled',
		],
		'message' => 'Programarea a fost anulata.',
	]);
} catch (Throwable $exception) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}

	error_log('Farmecul Tau customer cancel appointment failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Programarea nu a putut fi anulata.',
	]);
}
