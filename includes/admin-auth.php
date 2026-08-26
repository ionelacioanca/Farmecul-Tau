<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function getCurrentAdminUser(PDO $pdo): ?array
{
	startAppSession();

	$userId = $_SESSION['admin_user_id'] ?? null;

	if (!is_int($userId) || $userId <= 0) {
		return null;
	}

	$statement = $pdo->prepare(
		"SELECT id, name, email, role
		 FROM users
		 WHERE id = :id
			AND role = 'admin'
		 LIMIT 1"
	);
	$statement->execute(['id' => $userId]);
	$user = $statement->fetch();

	if ($user === false) {
		return null;
	}

	return [
		'id' => (int) $user['id'],
		'name' => (string) $user['name'],
		'email' => (string) $user['email'],
		'role' => (string) $user['role'],
	];
}

function setAuthenticatedAdminUser(int $userId): void
{
	startAppSession();
	session_regenerate_id(true);
	unset($_SESSION['user_id']);
	$_SESSION['admin_user_id'] = $userId;
	$_SESSION['admin_role'] = 'admin';
}

function clearAuthenticatedAdminUser(): void
{
	startAppSession();
	unset($_SESSION['admin_user_id'], $_SESSION['admin_role'], $_SESSION['admin_csrf_token'], $_SESSION['user_id']);
	session_regenerate_id(true);
}

function requireAdminUser(PDO $pdo): array
{
	$adminUser = getCurrentAdminUser($pdo);

	if ($adminUser !== null) {
		return $adminUser;
	}

	header('Location: login.php');
	exit;
}

function getAdminCsrfToken(): string
{
	startAppSession();

	if (!isset($_SESSION['admin_csrf_token']) || !is_string($_SESSION['admin_csrf_token'])) {
		$_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
	}

	return $_SESSION['admin_csrf_token'];
}

function verifyAdminCsrfToken(?string $token): bool
{
	startAppSession();

	$sessionToken = $_SESSION['admin_csrf_token'] ?? null;

	return is_string($sessionToken)
		&& is_string($token)
		&& hash_equals($sessionToken, $token);
}
