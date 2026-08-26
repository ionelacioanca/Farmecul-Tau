<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

try {
	require_once __DIR__ . '/../includes/auth.php';

	clearAuthenticatedUser();

	sendJsonResponse(200, [
		'success' => true,
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau logout failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Deconectarea nu a putut fi finalizată.',
	]);
}
