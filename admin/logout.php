<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-auth.php';

requireAdminUser($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: index.php');
	exit;
}

$csrfToken = $_POST['csrf_token'] ?? null;

if (!verifyAdminCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
	header('Location: index.php');
	exit;
}

clearAuthenticatedAdminUser();
header('Location: login.php');
exit;
