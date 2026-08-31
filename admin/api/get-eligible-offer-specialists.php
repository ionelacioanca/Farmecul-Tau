<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/api-response.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/admin-auth.php';
require_once __DIR__ . '/../../includes/offer-helpers.php';

requireAdminUser($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

$rawServiceIds = $_GET['service_ids'] ?? [];

if (!is_array($rawServiceIds)) {
	$rawServiceIds = $rawServiceIds !== '' ? explode(',', (string) $rawServiceIds) : [];
}

$serviceIds = normalizeOfferServiceIds($rawServiceIds);

if ($serviceIds === []) {
	sendJsonResponse(200, [
		'success' => true,
		'specialists' => [],
	]);
}

try {
	sendJsonResponse(200, [
		'success' => true,
		'specialists' => getEligibleOfferSpecialists($pdo, $serviceIds),
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau eligible offer specialists API failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Specialistii eligibili nu au putut fi incarcati.',
	]);
}
