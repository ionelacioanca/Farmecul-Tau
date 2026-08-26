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

echo 'Create Farmecul Tau admin account' . PHP_EOL;
echo 'Run this utility only in a trusted local/CLI environment. Remove or block tools/ in production.' . PHP_EOL . PHP_EOL;

$name = readRequiredInput('Name');
$email = strtolower(readRequiredInput('Email'));
$password = readRequiredInput('Password (minimum 8 characters)', true);
$passwordConfirmation = readRequiredInput('Confirm password', true);

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

$statement = $pdo->prepare(
	"INSERT INTO users (name, email, password_hash, role)
	 VALUES (:name, :email, :password_hash, 'admin')"
);
$statement->execute([
	'name' => $name,
	'email' => $email,
	'password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);

echo 'Admin account created for ' . $email . PHP_EOL;
