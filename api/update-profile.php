<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

$payload = readJsonRequestBody();

$name = isset($payload['name']) ? trim((string) $payload['name']) : '';
$email = isset($payload['email']) ? strtolower(trim((string) $payload['email'])) : '';
$phone = isset($payload['phone']) ? trim((string) $payload['phone']) : '';
$errors = [];

if ($name === '' || strlen($name) > 150) {
	$errors['name'] = 'Te rugam sa introduci numele.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
	$errors['email'] = 'Te rugam sa introduci o adresa de email valida.';
}

if ($phone !== '' && (strlen($phone) > 50 || !preg_match('/^[0-9+\s().-]{6,50}$/', $phone))) {
	$errors['phone'] = 'Te rugam sa introduci un numar de telefon valid.';
}

if ($errors !== []) {
	sendJsonResponse(422, [
		'success' => false,
		'errors' => $errors,
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';

	$currentUser = getCurrentUser($pdo);

	if ($currentUser === null) {
		sendJsonResponse(401, [
			'success' => false,
			'error' => 'Trebuie sa fii autentificat pentru a actualiza contul.',
		]);
	}

	$statement = $pdo->prepare(
		'SELECT id
		 FROM users
		 WHERE email = :email
			AND id <> :id
		 LIMIT 1'
	);
	$statement->execute([
		'email' => $email,
		'id' => (int) $currentUser['id'],
	]);

	if ($statement->fetch() !== false) {
		sendJsonResponse(409, [
			'success' => false,
			'errors' => [
				'email' => 'Exista deja un cont cu aceasta adresa de email.',
			],
		]);
	}

	$statement = $pdo->prepare(
		'UPDATE users
		 SET name = :name,
			email = :email,
			phone = :phone
		 WHERE id = :id
			AND role = \'customer\''
	);
	$statement->execute([
		'name' => $name,
		'email' => $email,
		'phone' => $phone !== '' ? $phone : null,
		'id' => (int) $currentUser['id'],
	]);

	sendJsonResponse(200, [
		'success' => true,
		'user' => [
			'id' => (int) $currentUser['id'],
			'name' => $name,
			'email' => $email,
			'phone' => $phone !== '' ? $phone : null,
		],
		'message' => 'Datele contului au fost actualizate.',
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau profile update failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Datele contului nu au putut fi actualizate.',
	]);
}
