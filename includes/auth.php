<?php
declare(strict_types=1);

function startAppSession(): void
{
	if (session_status() === PHP_SESSION_ACTIVE) {
		return;
	}

	ini_set('session.use_strict_mode', '1');
	session_start();
}

function getCurrentUserId(): ?int
{
	startAppSession();

	$userId = $_SESSION['customer_user_id'] ?? null;

	return is_int($userId) && $userId > 0 ? $userId : null;
}

function setAuthenticatedUser(int $userId): void
{
	startAppSession();
	session_regenerate_id(true);
	unset($_SESSION['user_id']);
	$_SESSION['customer_user_id'] = $userId;
}

function clearAuthenticatedUser(): void
{
	startAppSession();
	unset($_SESSION['customer_user_id'], $_SESSION['user_id']);
	session_regenerate_id(true);
}

function getCurrentUser(PDO $pdo): ?array
{
	$userId = getCurrentUserId();

	if ($userId === null) {
		return null;
	}

	$statement = $pdo->prepare(
		'SELECT id, name, email, phone
		 FROM users
		 WHERE id = :id
			AND role = \'customer\'
		 LIMIT 1'
	);
	$statement->execute(['id' => $userId]);
	$user = $statement->fetch();

	if ($user === false) {
		clearAuthenticatedUser();
		return null;
	}

	return [
		'id' => (int) $user['id'],
		'name' => (string) $user['name'],
		'email' => (string) $user['email'],
		'phone' => $user['phone'] !== null ? (string) $user['phone'] : null,
	];
}
