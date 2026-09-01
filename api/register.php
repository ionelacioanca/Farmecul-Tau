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

$nameInput = $payload['name'] ?? null;
$emailInput = $payload['email'] ?? null;
$passwordInput = $payload['password'] ?? null;
$passwordConfirmationInput = $payload['password_confirmation'] ?? null;

$name = is_string($nameInput) ? trim($nameInput) : '';
$email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
$password = is_string($passwordInput) ? $passwordInput : '';
$passwordConfirmation = is_string($passwordConfirmationInput) ? $passwordConfirmationInput : '';
$errors = [];

if ($name === '') {
	$errors['name'] = 'Te rugăm să introduci numele.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	$errors['email'] = 'Te rugăm să introduci o adresă de email validă.';
}

if (strlen($password) < 8) {
	$errors['password'] = 'Parola trebuie să aibă cel puțin 8 caractere.';
}

if ($password !== $passwordConfirmation) {
	$errors['password_confirmation'] = 'Confirmarea parolei nu se potrivește.';
}

if ($errors !== []) {
	sendJsonResponse(422, [
		'success' => false,
		'errors' => $errors,
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';
	require_once __DIR__ . '/../includes/auth.php';

	$statement = $pdo->prepare(
		'SELECT id
		 FROM users
		 WHERE email = :email
		 LIMIT 1'
	);
	$statement->execute(['email' => $email]);

	if ($statement->fetch() !== false) {
		sendJsonResponse(409, [
			'success' => false,
			'errors' => [
				'email' => 'Există deja un cont cu această adresă de email.',
			],
		]);
	}

	$passwordHash = password_hash($password, PASSWORD_DEFAULT);
	$statement = $pdo->prepare(
		"INSERT INTO users (name, email, password_hash, role)
		 VALUES (:name, :email, :password_hash, 'customer')"
	);
	$statement->execute([
		'name' => $name,
		'email' => $email,
		'password_hash' => $passwordHash,
	]);

	$userId = (int) $pdo->lastInsertId();
	setAuthenticatedUser($userId);

	sendJsonResponse(201, [
		'success' => true,
		'user' => [
			'id' => $userId,
			'name' => $name,
			'email' => $email,
			'phone' => null,
		],
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau registration failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Contul nu a putut fi creat.',
	]);
}
