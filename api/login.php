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

$payload = readJsonRequestBody();

$emailInput = $payload['email'] ?? null;
$passwordInput = $payload['password'] ?? null;

$email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
$password = is_string($passwordInput) ? $passwordInput : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'Te rugăm să introduci emailul și parola.',
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';
	require_once __DIR__ . '/../includes/auth.php';

	$statement = $pdo->prepare(
		'SELECT id, name, email, password_hash, role
		 FROM users
		 WHERE email = :email
		 LIMIT 1'
	);
	$statement->execute(['email' => $email]);
	$user = $statement->fetch();

	if (
		$user === false
		|| (string) $user['role'] !== 'customer'
		|| !password_verify($password, (string) $user['password_hash'])
	) {
		sendJsonResponse(401, [
			'success' => false,
			'error' => 'Emailul sau parola nu este corectă.',
		]);
	}

	setAuthenticatedUser((int) $user['id']);

	sendJsonResponse(200, [
		'success' => true,
		'user' => [
			'id' => (int) $user['id'],
			'name' => (string) $user['name'],
			'email' => (string) $user['email'],
		],
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau login failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Autentificarea nu a putut fi finalizată.',
	]);
}
