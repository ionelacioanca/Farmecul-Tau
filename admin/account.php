<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';

$dashboardUser = requireDashboardUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);
$csrfToken = getAdminCsrfToken();
$message = '';
$errors = [];
$values = [
	'name' => (string) $dashboardUser['name'],
	'email' => (string) $dashboardUser['email'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$values = [
		'name' => isset($_POST['name']) ? trim((string) $_POST['name']) : '',
		'email' => isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '',
	];

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
	}

	if ($values['name'] === '' || strlen($values['name']) > 150) {
		$errors[] = 'Introdu un nume valid.';
	}

	if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 255) {
		$errors[] = 'Introdu o adresa de email valida.';
	}

	if ($errors === []) {
		try {
			$pdo->beginTransaction();

			$existingStatement = $pdo->prepare(
				'SELECT id
				 FROM users
				 WHERE email = :email
					AND id <> :id
				 LIMIT 1
				 FOR UPDATE'
			);
			$existingStatement->execute([
				'id' => (int) $dashboardUser['id'],
				'email' => $values['email'],
			]);

			if ($existingStatement->fetch() !== false) {
				$pdo->rollBack();
				$errors[] = 'Exista deja un cont cu aceasta adresa de email.';
			} else {
				$userStatement = $pdo->prepare(
					'UPDATE users
					 SET name = :name,
						email = :email
					 WHERE id = :id'
				);
				$userStatement->execute([
					'id' => (int) $dashboardUser['id'],
					'name' => $values['name'],
					'email' => $values['email'],
				]);

				$specialistStatement = $pdo->prepare(
					'UPDATE specialists
					 SET name = :name,
						email = :email
					 WHERE user_id = :user_id'
				);
				$specialistStatement->execute([
					'user_id' => (int) $dashboardUser['id'],
					'name' => $values['name'],
					'email' => $values['email'],
				]);

				$pdo->commit();
				$message = 'Datele contului au fost actualizate.';
				$dashboardUser['name'] = $values['name'];
				$dashboardUser['email'] = $values['email'];
				$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);
			}
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau dashboard account update failed: ' . $exception->getMessage());
			$errors[] = 'Datele contului nu au putut fi actualizate.';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Datele contului | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-7">
</head>
<body>
	<?php renderAdminHeader('Datele contului', 'account.php', $csrfToken, $dashboardUser, $currentSpecialist); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">CONT</p>
					<h2 class="admin-section-title">Datele contului</h2>
				</div>
			</div>

			<?php if ($message !== ''): ?>
				<p class="admin-alert admin-alert-success"><?php echo adminEscape($message); ?></p>
			<?php endif; ?>
			<?php if ($errors !== []): ?>
				<div class="admin-alert admin-alert-error">
					<?php foreach ($errors as $formError): ?>
						<p><?php echo adminEscape($formError); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form class="admin-form admin-form-grid" method="post" action="account.php">
				<label>
					<span>Nume</span>
					<input type="text" name="name" maxlength="150" value="<?php echo adminEscape($values['name']); ?>" required>
				</label>
				<label>
					<span>Email</span>
					<input type="email" name="email" maxlength="255" value="<?php echo adminEscape($values['email']); ?>" required>
				</label>
				<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
				<button class="admin-button admin-form-wide" type="submit">Salveaza datele</button>
			</form>

			<section class="admin-detail-card admin-panel-spaced">
				<h3>Parola</h3>
				<p class="admin-empty">Schimbarea parolei va fi adaugata intr-un pas urmator.</p>
			</section>
		</section>
	</main>
</body>
</html>
