<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';
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
		'error' => 'Trebuie sa fii autentificat pentru a sterge contul.',
	]);
}

$payload = readJsonRequestBody();
$cancelActiveAppointments = filter_var($payload['cancel_active_appointments'] ?? false, FILTER_VALIDATE_BOOL);
$userId = (int) $currentUser['id'];
$now = new DateTimeImmutable('now', getSalonTimezone());

try {
	$pdo->beginTransaction();

	$userStatement = $pdo->prepare(
		'SELECT id
		 FROM users
		 WHERE id = :id
			AND role = \'customer\'
		 LIMIT 1
		 FOR UPDATE'
	);
	$userStatement->execute(['id' => $userId]);

	if ($userStatement->fetch() === false) {
		$pdo->rollBack();
		clearAuthenticatedUser();
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Contul nu a fost gasit.',
		]);
	}

	$countStatement = $pdo->prepare(
		"SELECT COUNT(*) AS active_count
		 FROM appointments
		 WHERE customer_user_id = :user_id
			AND status IN ('pending', 'approved')
			AND start_datetime >= :now"
	);
	$countStatement->execute([
		'user_id' => $userId,
		'now' => $now->format('Y-m-d H:i:s'),
	]);
	$activeAppointmentCount = (int) ($countStatement->fetch()['active_count'] ?? 0);

	if ($activeAppointmentCount > 0 && !$cancelActiveAppointments) {
		$pdo->rollBack();
		sendJsonResponse(409, [
			'success' => false,
			'requires_confirmation' => true,
			'active_appointment_count' => $activeAppointmentCount,
			'message' => $activeAppointmentCount === 1
				? 'Ai o programare activa. Vrei sa o anulezi si sa stergi contul?'
				: 'Ai programari active. Vrei sa le anulezi si sa stergi contul?',
		]);
	}

	if ($activeAppointmentCount > 0) {
		$cancelStatement = $pdo->prepare(
			"UPDATE appointments
			 SET status = 'cancelled',
				admin_note = CASE
					WHEN admin_note IS NULL OR admin_note = '' THEN 'Anulata la stergerea contului de client.'
					ELSE CONCAT(admin_note, '\nAnulata la stergerea contului de client.')
				END
			 WHERE customer_user_id = :user_id
				AND status IN ('pending', 'approved')
				AND start_datetime >= :now"
		);
		$cancelStatement->execute([
			'user_id' => $userId,
			'now' => $now->format('Y-m-d H:i:s'),
		]);
	}

	$deleteStatement = $pdo->prepare(
		'DELETE FROM users
		 WHERE id = :id
			AND role = \'customer\''
	);
	$deleteStatement->execute(['id' => $userId]);

	$pdo->commit();
	clearAuthenticatedUser();

	sendJsonResponse(200, [
		'success' => true,
		'cancelled_appointments' => $activeAppointmentCount,
		'message' => 'Contul tau a fost sters.',
	]);
} catch (Throwable $exception) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}

	error_log('Farmecul Tau delete account failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Contul nu a putut fi sters.',
	]);
}
