<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/booking.php';

setSalonTimezone();
$dashboardUser = requireDashboardUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);
$isAdmin = $dashboardUser['role'] === 'admin';
$fallbackRoute = $isAdmin ? 'appointments.php' : 'my-appointments.php';

$csrfToken = getAdminCsrfToken();
$appointmentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);

if ($appointmentId === false) {
	header('Location: ' . $fallbackRoute . '?error=' . rawurlencode('Programarea nu a putut fi identificata.'));
	exit;
}

if (!$isAdmin && $currentSpecialist === null) {
	header('Location: my-appointments.php?error=' . rawurlencode('Contul tau nu este legat de un specialist activ.'));
	exit;
}

$message = isset($_GET['message']) ? trim((string) $_GET['message']) : '';
$error = isset($_GET['error']) ? trim((string) $_GET['error']) : '';
$detailWhereSql = $isAdmin ? '' : ' AND a.specialist_id = :current_specialist_id';
$detailParams = ['id' => $appointmentId];

if (!$isAdmin && $currentSpecialist !== null) {
	$detailParams['current_specialist_id'] = (int) $currentSpecialist['id'];
}

$statement = $pdo->prepare(
	'SELECT
		a.id,
		a.customer_user_id,
		a.customer_name,
		a.customer_email,
		a.customer_phone,
		a.start_datetime,
		a.end_datetime,
		a.price_at_booking,
		a.duration_minutes_at_booking,
		a.status,
		a.source,
		a.notes,
		a.admin_note,
		a.created_at,
		sv.name AS service_name,
		sp.name AS specialist_name,
		u.name AS account_name,
		u.email AS account_email
	 FROM appointments a
	 INNER JOIN services sv ON sv.id = a.service_id
	 INNER JOIN specialists sp ON sp.id = a.specialist_id
	 LEFT JOIN users u ON u.id = a.customer_user_id
	 WHERE a.id = :id' . $detailWhereSql . '
	 LIMIT 1'
);
$statement->execute($detailParams);
$appointment = $statement->fetch();

if ($appointment === false) {
	header('Location: ' . $fallbackRoute . '?error=' . rawurlencode('Programarea nu a fost gasita.'));
	exit;
}

$status = (string) $appointment['status'];
$listRoute = $isAdmin ? 'appointments.php' : 'my-appointments.php';
$activeRoute = $isAdmin ? 'appointments.php' : 'my-appointments.php';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Detalii programare | Farmecul Tău</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-7">
</head>
<body>
	<?php renderAdminHeader('Detalii programare', $activeRoute, $csrfToken); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">PROGRAMARE #<?php echo (int) $appointment['id']; ?></p>
					<h2 class="admin-section-title"><?php echo adminEscape((string) $appointment['customer_name']); ?></h2>
				</div>
				<a class="admin-reset-link" href="<?php echo adminEscape($listRoute); ?>">Inapoi la lista</a>
			</div>

			<?php if ($message !== ''): ?>
				<p class="admin-alert admin-alert-success"><?php echo adminEscape($message); ?></p>
			<?php endif; ?>
			<?php if ($error !== ''): ?>
				<p class="admin-alert admin-alert-error"><?php echo adminEscape($error); ?></p>
			<?php endif; ?>

			<div class="admin-detail-grid">
				<section class="admin-detail-card">
					<h3>Client</h3>
					<dl>
						<div>
							<dt>Nume</dt>
							<dd><?php echo adminEscape((string) $appointment['customer_name']); ?></dd>
						</div>
						<div>
							<dt>Email</dt>
							<dd><?php echo adminEscape((string) $appointment['customer_email']); ?></dd>
						</div>
						<div>
							<dt>Telefon</dt>
							<dd><?php echo adminEscape((string) ($appointment['customer_phone'] ?? '-')); ?></dd>
						</div>
						<div>
							<dt>Cont client</dt>
							<dd>
								<?php if ($appointment['customer_user_id'] !== null): ?>
									#<?php echo (int) $appointment['customer_user_id']; ?>,
									<?php echo adminEscape((string) $appointment['account_name']); ?>
								<?php else: ?>
									-
								<?php endif; ?>
							</dd>
						</div>
					</dl>
				</section>

				<section class="admin-detail-card">
					<h3>Programare</h3>
					<dl>
						<div>
							<dt>Serviciu</dt>
							<dd><?php echo adminEscape((string) $appointment['service_name']); ?></dd>
						</div>
						<div>
							<dt>Specialist</dt>
							<dd><?php echo adminEscape((string) $appointment['specialist_name']); ?></dd>
						</div>
						<div>
							<dt>Data</dt>
							<dd><?php echo adminEscape(adminFormatDate((string) $appointment['start_datetime'], 'd.m.Y')); ?></dd>
						</div>
						<div>
							<dt>Interval</dt>
							<dd>
								<?php echo adminEscape(adminFormatDate((string) $appointment['start_datetime'], 'H:i')); ?>
								-
								<?php echo adminEscape(adminFormatDate((string) $appointment['end_datetime'], 'H:i')); ?>
							</dd>
						</div>
						<div>
							<dt>Durata</dt>
							<dd><?php echo (int) $appointment['duration_minutes_at_booking']; ?> min</dd>
						</div>
						<div>
							<dt>Pret</dt>
							<dd><?php echo $appointment['price_at_booking'] !== null ? adminEscape(number_format((float) $appointment['price_at_booking'], 2, '.', '') . ' lei') : '-'; ?></dd>
						</div>
						<div>
							<dt>Sursă</dt>
							<dd><?php echo adminEscape(strtoupper((string) $appointment['source'])); ?></dd>
						</div>
						<div>
							<dt>Status</dt>
							<dd>
								<span class="admin-status admin-status-<?php echo adminEscape($status); ?>">
									<?php echo adminEscape(adminFormatStatus($status)); ?>
								</span>
							</dd>
						</div>
						<div>
							<dt>Creată</dt>
							<dd><?php echo adminEscape(adminFormatDate((string) $appointment['created_at'])); ?></dd>
						</div>
					</dl>
				</section>

				<section class="admin-detail-card admin-detail-card-wide">
					<h3>Note</h3>
					<dl>
						<div>
							<dt>Observații client</dt>
							<dd><?php echo nl2br(adminEscape((string) ($appointment['notes'] ?? '-'))); ?></dd>
						</div>
						<div>
							<dt>Notă admin</dt>
							<dd><?php echo nl2br(adminEscape((string) ($appointment['admin_note'] ?? '-'))); ?></dd>
						</div>
					</dl>
				</section>
			</div>

			<?php if ($status === 'pending'): ?>
				<div class="admin-detail-actions">
					<form method="post" action="<?php echo adminEscape($listRoute); ?>">
						<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
						<input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
						<input type="hidden" name="action" value="approve">
						<input type="hidden" name="return_to" value="appointment.php?id=<?php echo (int) $appointment['id']; ?>">
						<button class="admin-button" type="submit">APROBĂ</button>
					</form>
					<form class="admin-reject-form" method="post" action="<?php echo adminEscape($listRoute); ?>" onsubmit="return confirm('Respingeți această programare?');">
						<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
						<input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
						<input type="hidden" name="action" value="reject">
						<input type="hidden" name="return_to" value="appointment.php?id=<?php echo (int) $appointment['id']; ?>">
						<label>
							<span>Notă respingere</span>
							<textarea name="admin_note" maxlength="1000" rows="3"></textarea>
						</label>
						<button class="admin-button admin-danger-button" type="submit">RESPINGE</button>
					</form>
				</div>
			<?php endif; ?>
		</section>
	</main>
</body>
</html>
