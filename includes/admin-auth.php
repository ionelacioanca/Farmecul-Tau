<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function getCurrentDashboardUser(PDO $pdo): ?array
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
			AND role IN ('admin', 'specialist')
		 LIMIT 1"
	);
	$statement->execute(['id' => $userId]);
	$user = $statement->fetch();

	if ($user === false) {
		clearAuthenticatedAdminUser();
		return null;
	}

	return [
		'id' => (int) $user['id'],
		'name' => (string) $user['name'],
		'email' => (string) $user['email'],
		'role' => (string) $user['role'],
	];
}

function getCurrentAdminUser(PDO $pdo): ?array
{
	$user = getCurrentDashboardUser($pdo);

	return $user !== null && $user['role'] === 'admin' ? $user : null;
}

function getCurrentSpecialist(PDO $pdo, ?array $dashboardUser = null): ?array
{
	$user = $dashboardUser ?? getCurrentDashboardUser($pdo);

	if ($user === null) {
		return null;
	}

	$columnStatement = $pdo->prepare(
		'SELECT COUNT(*) AS column_exists
		 FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = :table_name
			AND COLUMN_NAME = :column_name'
	);
	$columnStatement->execute([
		'table_name' => 'specialists',
		'column_name' => 'specialization',
	]);
	$hasSpecializationColumn = (int) ($columnStatement->fetch()['column_exists'] ?? 0) > 0;
	$specializationSelect = $hasSpecializationColumn ? 'specialization' : 'NULL AS specialization';

	$statement = $pdo->prepare(
		'SELECT id, user_id, name, email, ' . $specializationSelect . ', active
		 FROM specialists
		 WHERE user_id = :user_id
			AND active = 1
		 LIMIT 1'
	);
	$statement->execute(['user_id' => (int) $user['id']]);
	$specialist = $statement->fetch();

	if ($specialist === false) {
		return null;
	}

	return [
		'id' => (int) $specialist['id'],
		'user_id' => (int) $specialist['user_id'],
		'name' => (string) $specialist['name'],
		'email' => $specialist['email'] !== null ? (string) $specialist['email'] : null,
		'specialization' => $specialist['specialization'] !== null ? (string) $specialist['specialization'] : null,
		'active' => (int) $specialist['active'],
	];
}

function setAuthenticatedDashboardUser(int $userId, string $role): void
{
	startAppSession();
	session_regenerate_id(true);
	unset($_SESSION['user_id']);
	$_SESSION['admin_user_id'] = $userId;
	$_SESSION['admin_role'] = $role;
}

function setAuthenticatedAdminUser(int $userId): void
{
	setAuthenticatedDashboardUser($userId, 'admin');
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

	if (getCurrentDashboardUser($pdo) !== null) {
		http_response_code(403);
		exit('Access denied.');
	}

	header('Location: login.php');
	exit;
}

function requireDashboardUser(PDO $pdo): array
{
	$dashboardUser = getCurrentDashboardUser($pdo);

	if ($dashboardUser !== null) {
		return $dashboardUser;
	}

	header('Location: login.php');
	exit;
}

function requireAdmin(PDO $pdo): array
{
	return requireAdminUser($pdo);
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
