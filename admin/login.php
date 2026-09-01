<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin-auth.php';

if (getCurrentDashboardUser($pdo) !== null) {
	header('Location: index.php');
	exit;
}

$error = '';
$csrfToken = getAdminCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$email = isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '';
	$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
	$submittedCsrfToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;

	if (
		verifyAdminCsrfToken($submittedCsrfToken)
		&& filter_var($email, FILTER_VALIDATE_EMAIL)
		&& $password !== ''
	) {
		$statement = $pdo->prepare(
			'SELECT id, password_hash, role
			 FROM users
			 WHERE email = :email
			 LIMIT 1'
		);
		$statement->execute(['email' => $email]);
		$user = $statement->fetch();

		if (
			$user !== false
			&& in_array((string) $user['role'], ['admin', 'specialist'], true)
			&& password_verify($password, (string) $user['password_hash'])
		) {
			setAuthenticatedDashboardUser((int) $user['id'], (string) $user['role']);
			unset($_SESSION['admin_csrf_token']);

			$specialistStatement = $pdo->prepare(
				'SELECT id
				 FROM specialists
				 WHERE user_id = :user_id
					AND active = 1
				 LIMIT 1'
			);
			$specialistStatement->execute(['user_id' => (int) $user['id']]);
			$redirectPath = $specialistStatement->fetch() !== false ? 'my-appointments.php' : 'index.php';

			header('Location: ' . $redirectPath);
			exit;
		}
	}

	$error = 'Datele de autentificare nu sunt corecte.';
}

function adminEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Admin login | Farmecul Tău</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-4">
</head>
<body>
	<main class="admin-login-page" aria-labelledby="admin-login-title">
		<section class="admin-login-panel">
			<p class="admin-kicker">ADMINISTRARE</p>
			<h1 class="admin-title" id="admin-login-title">Farmecul Tău</h1>
			<form class="admin-form" method="post" action="login.php">
				<label>
					<span>Email</span>
					<input type="email" name="email" autocomplete="email" required>
				</label>
				<label>
					<span>Parolă</span>
					<input type="password" name="password" autocomplete="current-password" required>
				</label>
				<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
				<?php if ($error !== ''): ?>
					<p class="admin-form-message" role="alert"><?php echo adminEscape($error); ?></p>
				<?php endif; ?>
				<button class="admin-button" type="submit">Intră în admin</button>
			</form>
		</section>
	</main>
</body>
</html>
