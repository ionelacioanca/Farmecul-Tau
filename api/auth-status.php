<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';
	require_once __DIR__ . '/../includes/auth.php';
	require_once __DIR__ . '/../includes/promo-eligibility.php';

	$user = getCurrentUser($pdo);
	$activePromo = null;

	if ($user !== null) {
		$activePromo = getActivePromoCodeForUser($pdo, (int) $user['id']);
	}

	sendJsonResponse(200, [
		'success' => true,
		'authenticated' => $user !== null,
		'user' => $user,
		'active_promo' => $activePromo,
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau auth status failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Statusul autentificării nu a putut fi verificat.',
	]);
}
