<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit('Not found.');
}

require_once __DIR__ . '/../includes/db.php';

function readRequiredInput(string $label, bool $hidden = false): string
{
	if ($hidden) {
		echo $label . ': ';
		$value = trim((string) fgets(STDIN));
		echo PHP_EOL;
		return $value;
	}

	do {
		echo $label . ': ';
		$value = trim((string) fgets(STDIN));
	} while ($value === '');

	return $value;
}

function readYesNoInput(string $label): bool
{
	do {
		echo $label . ' [y/n]: ';
		$value = strtolower(trim((string) fgets(STDIN)));
	} while (!in_array($value, ['y', 'yes', 'n', 'no'], true));

	return in_array($value, ['y', 'yes'], true);
}

function readSpecializationInput(): string
{
	$allowed = ['hairstylist', 'nails'];

	do {
		echo 'Specialization (hairstylist/nails): ';
		$value = strtolower(trim((string) fgets(STDIN)));
	} while (!in_array($value, $allowed, true));

	return $value;
}

echo 'Create Farmecul Tau admin account' . PHP_EOL;
echo 'Run this utility only in a trusted local/CLI environment. Remove or block tools/ in production.' . PHP_EOL . PHP_EOL;

$name = readRequiredInput('Name');
$email = strtolower(readRequiredInput('Email'));
$password = readRequiredInput('Password (minimum 8 characters)', true);
$passwordConfirmation = readRequiredInput('Confirm password', true);
$isSpecialist = readYesNoInput('Is this admin also a specialist?');
$specialization = $isSpecialist ? readSpecializationInput() : null;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
	fwrite(STDERR, 'Invalid email address.' . PHP_EOL);
	exit(1);
}

if (strlen($password) < 8) {
	fwrite(STDERR, 'Password must have at least 8 characters.' . PHP_EOL);
	exit(1);
}

if ($password !== $passwordConfirmation) {
	fwrite(STDERR, 'Password confirmation does not match.' . PHP_EOL);
	exit(1);
}

$existingStatement = $pdo->prepare(
	'SELECT id
	 FROM users
	 WHERE email = :email
	 LIMIT 1'
);
$existingStatement->execute(['email' => $email]);

if ($existingStatement->fetch() !== false) {
	fwrite(STDERR, 'A user with this email already exists.' . PHP_EOL);
	exit(1);
}

try {
	$pdo->beginTransaction();

	$statement = $pdo->prepare(
		"INSERT INTO users (name, email, password_hash, role)
		 VALUES (:name, :email, :password_hash, 'admin')"
	);
	$statement->execute([
		'name' => $name,
		'email' => $email,
		'password_hash' => password_hash($password, PASSWORD_DEFAULT),
	]);
	$userId = (int) $pdo->lastInsertId();

	if ($isSpecialist) {
		$specialistStatement = $pdo->prepare(
			'INSERT INTO specialists (user_id, name, email, specialization, active)
			 VALUES (:user_id, :name, :email, :specialization, 1)'
		);
		$specialistStatement->execute([
			'user_id' => $userId,
			'name' => $name,
			'email' => $email,
			'specialization' => $specialization,
		]);
	}

	$pdo->commit();
} catch (Throwable $exception) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}

	fwrite(STDERR, 'Admin account could not be created.' . PHP_EOL);
	error_log('Farmecul Tau CLI admin creation failed: ' . $exception->getMessage());
	exit(1);
}

echo 'Admin account created for ' . $email . PHP_EOL;
