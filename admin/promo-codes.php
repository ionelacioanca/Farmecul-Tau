<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/promo-eligibility.php';

$dashboardUser = requireAdminUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfToken = $_POST['csrf_token'] ?? null;
	$promoCodeId = filter_var($_POST['promo_code_id'] ?? null, FILTER_VALIDATE_INT, [
		'options' => ['min_range' => 1],
	]);

	if (!verifyAdminCsrfToken(is_string($csrfToken) ? $csrfToken : null)) {
		$error = 'Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.';
	} elseif ($promoCodeId === false) {
		$error = 'Codul promoțional nu a putut fi identificat.';
	} else {
		try {
			$pdo->beginTransaction();
			expirePromoCodes($pdo);

			$statement = $pdo->prepare(
				"SELECT id
				 FROM promo_codes
				 WHERE id = :id
					AND status = 'active'
					AND expires_at > NOW()
				 LIMIT 1
				 FOR UPDATE"
			);
			$statement->execute(['id' => $promoCodeId]);

			if ($statement->fetch() === false) {
				$pdo->rollBack();
				$error = 'Codul nu mai este activ sau a expirat.';
			} else {
				$updateStatement = $pdo->prepare(
					"UPDATE promo_codes
					 SET status = 'used',
						used_at = NOW()
					 WHERE id = :id
						AND status = 'active'
						AND expires_at > NOW()"
				);
				$updateStatement->execute(['id' => $promoCodeId]);
				$pdo->commit();
				$message = 'Codul a fost marcat ca folosit.';
			}
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau admin mark promo used failed: ' . $exception->getMessage());
			$error = 'Codul nu a putut fi actualizat.';
		}
	}
}

expirePromoCodes($pdo);

$statement = $pdo->prepare(
	'SELECT
		pc.id,
		pc.code,
		pc.status,
		pc.created_at,
		pc.expires_at,
		pc.used_at,
		u.name AS customer_name,
		u.email AS customer_email,
		r.name AS reward_name
	 FROM promo_codes pc
	 INNER JOIN users u ON u.id = pc.user_id
	 INNER JOIN promo_rewards r ON r.id = pc.reward_id
	 ORDER BY pc.created_at DESC'
);
$statement->execute();
$promoCodes = $statement->fetchAll();
$csrfToken = getAdminCsrfToken();
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Coduri promoționale | Farmecul Tău</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-7">
</head>
<body>
	<?php renderAdminHeader('Coduri promoționale', 'promo-codes.php', $csrfToken); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<?php if ($message !== ''): ?>
				<p class="admin-alert admin-alert-success"><?php echo adminEscape($message); ?></p>
			<?php endif; ?>
			<?php if ($error !== ''): ?>
				<p class="admin-alert admin-alert-error"><?php echo adminEscape($error); ?></p>
			<?php endif; ?>

			<?php if ($promoCodes === []): ?>
				<p class="admin-empty">Nu există încă niciun cod promoțional.</p>
			<?php else: ?>
				<div class="admin-table-wrap">
					<table class="admin-table">
						<thead>
							<tr>
								<th>Client</th>
								<th>Email</th>
								<th>Cod</th>
								<th>Surpriză</th>
								<th>Creat</th>
								<th>Expiră</th>
								<th>Folosit</th>
								<th>Status</th>
								<th>Acțiune</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($promoCodes as $promoCode): ?>
								<?php $status = (string) $promoCode['status']; ?>
								<tr>
									<td data-label="Client"><?php echo adminEscape((string) $promoCode['customer_name']); ?></td>
									<td data-label="Email"><?php echo adminEscape((string) $promoCode['customer_email']); ?></td>
									<td data-label="Cod"><strong><?php echo adminEscape((string) $promoCode['code']); ?></strong></td>
									<td data-label="Surpriză"><?php echo adminEscape((string) $promoCode['reward_name']); ?></td>
									<td data-label="Creat"><?php echo adminEscape(adminFormatDate((string) $promoCode['created_at'])); ?></td>
									<td data-label="Expiră"><?php echo adminEscape(adminFormatDate((string) $promoCode['expires_at'])); ?></td>
									<td data-label="Folosit"><?php echo adminEscape(adminFormatDate($promoCode['used_at'] !== null ? (string) $promoCode['used_at'] : null)); ?></td>
									<td data-label="Status">
										<span class="admin-status admin-status-<?php echo adminEscape($status); ?>">
											<?php echo adminEscape(adminFormatStatus($status)); ?>
										</span>
									</td>
									<td data-label="Acțiune">
										<?php if ($status === 'active'): ?>
											<form method="post" action="promo-codes.php" onsubmit="return confirm('Marcați acest cod ca folosit?');">
												<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
												<input type="hidden" name="promo_code_id" value="<?php echo (int) $promoCode['id']; ?>">
												<button class="admin-small-button" type="submit">MARCAȚI CA FOLOSIT</button>
											</form>
										<?php else: ?>
											<span class="admin-muted">-</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	</main>
</body>
</html>
