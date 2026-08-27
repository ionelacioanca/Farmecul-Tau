<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/booking.php';

setSalonTimezone();
$dashboardUser = requireDashboardUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);
$csrfToken = getAdminCsrfToken();
$message = '';
$error = '';
$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'cancelled'];

function myAppointmentReturnPath(?string $value): string
{
	if (!is_string($value) || $value === '') {
		return 'my-appointments.php';
	}

	return preg_match('/^(my-appointments\.php(?:\?.*)?|appointment\.php\?id=\d+)$/', $value)
		? $value
		: 'my-appointments.php';
}

function myRedirectWithMessage(string $path, string $key, string $message): void
{
	$separator = str_contains($path, '?') ? '&' : '?';
	header('Location: ' . $path . $separator . $key . '=' . rawurlencode($message));
	exit;
}

function myLoadAppointmentForAction(PDO $pdo, int $appointmentId, int $specialistId, bool $lock = true): ?array
{
	$statement = $pdo->prepare(
		'SELECT id, specialist_id, start_datetime, end_datetime, status
		 FROM appointments
		 WHERE id = :id
			AND specialist_id = :specialist_id
		 LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
	);
	$statement->execute([
		'id' => $appointmentId,
		'specialist_id' => $specialistId,
	]);
	$appointment = $statement->fetch();

	return $appointment !== false ? $appointment : null;
}

if ($currentSpecialist === null) {
	$error = 'Contul tau nu este legat de un specialist activ.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null;
	$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
	$appointmentId = filter_var($_POST['appointment_id'] ?? null, FILTER_VALIDATE_INT, [
		'options' => ['min_range' => 1],
	]);
	$returnPath = myAppointmentReturnPath($_POST['return_to'] ?? null);
	$currentSpecialistId = (int) $currentSpecialist['id'];

	if (!verifyAdminCsrfToken($csrf)) {
		myRedirectWithMessage($returnPath, 'error', 'Sesiunea a expirat. Reincarca pagina si incearca din nou.');
	}

	if ($appointmentId === false || !in_array($action, ['approve', 'reject'], true)) {
		myRedirectWithMessage($returnPath, 'error', 'Actiunea nu a putut fi procesata.');
	}

	if ($action === 'approve') {
		$lockName = null;
		$lockAcquired = false;

		try {
			$previewStatement = $pdo->prepare(
				'SELECT specialist_id, start_datetime
				 FROM appointments
				 WHERE id = :id
					AND specialist_id = :specialist_id
				 LIMIT 1'
			);
			$previewStatement->execute([
				'id' => $appointmentId,
				'specialist_id' => $currentSpecialistId,
			]);
			$preview = $previewStatement->fetch();

			if ($preview === false) {
				myRedirectWithMessage($returnPath, 'error', 'Programarea nu a fost gasita.');
			}

			$candidateStart = new DateTimeImmutable((string) $preview['start_datetime'], getSalonTimezone());
			$lockName = getBookingLockName($currentSpecialistId, $candidateStart);
			$lockAcquired = acquireBookingLock($pdo, $lockName);

			if (!$lockAcquired) {
				myRedirectWithMessage($returnPath, 'error', 'Programarea nu mai poate fi aprobata deoarece intervalul nu mai este disponibil.');
			}

			$pdo->beginTransaction();
			$appointment = myLoadAppointmentForAction($pdo, $appointmentId, $currentSpecialistId);

			if ($appointment === null) {
				$pdo->rollBack();
				myRedirectWithMessage($returnPath, 'error', 'Programarea nu a fost gasita.');
			}

			if ((string) $appointment['status'] !== 'pending') {
				$pdo->rollBack();
				myRedirectWithMessage($returnPath, 'error', 'Doar programarile in asteptare pot fi aprobate.');
			}

			$candidateStart = new DateTimeImmutable((string) $appointment['start_datetime'], getSalonTimezone());
			$candidateEnd = new DateTimeImmutable((string) $appointment['end_datetime'], getSalonTimezone());

			if (!isBookingSlotAvailable($pdo, $currentSpecialistId, $candidateStart, $candidateEnd, true, $appointmentId)) {
				$pdo->rollBack();
				myRedirectWithMessage($returnPath, 'error', 'Programarea nu mai poate fi aprobata deoarece intervalul nu mai este disponibil.');
			}

			$updateStatement = $pdo->prepare(
				"UPDATE appointments
				 SET status = 'approved'
				 WHERE id = :id
					AND specialist_id = :specialist_id
					AND status = 'pending'"
			);
			$updateStatement->execute([
				'id' => $appointmentId,
				'specialist_id' => $currentSpecialistId,
			]);
			$pdo->commit();
			myRedirectWithMessage($returnPath, 'message', 'Programarea a fost aprobata.');
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau specialist approve appointment failed: ' . $exception->getMessage());
			myRedirectWithMessage($returnPath, 'error', 'Programarea nu a putut fi aprobata.');
		} finally {
			if ($lockName !== null && $lockAcquired) {
				try {
					releaseBookingLock($pdo, $lockName);
				} catch (Throwable $releaseException) {
					error_log('Farmecul Tau specialist booking lock release failed: ' . $releaseException->getMessage());
				}
			}
		}
	}

	if ($action === 'reject') {
		$adminNote = isset($_POST['admin_note']) ? trim((string) $_POST['admin_note']) : '';

		if (strlen($adminNote) > 1000) {
			myRedirectWithMessage($returnPath, 'error', 'Nota de respingere poate avea maximum 1000 de caractere.');
		}

		try {
			$pdo->beginTransaction();
			$appointment = myLoadAppointmentForAction($pdo, $appointmentId, $currentSpecialistId);

			if ($appointment === null) {
				$pdo->rollBack();
				myRedirectWithMessage($returnPath, 'error', 'Programarea nu a fost gasita.');
			}

			if ((string) $appointment['status'] !== 'pending') {
				$pdo->rollBack();
				myRedirectWithMessage($returnPath, 'error', 'Doar programarile in asteptare pot fi respinse.');
			}

			$updateStatement = $pdo->prepare(
				"UPDATE appointments
				 SET status = 'rejected',
					admin_note = :admin_note
				 WHERE id = :id
					AND specialist_id = :specialist_id
					AND status = 'pending'"
			);
			$updateStatement->execute([
				'id' => $appointmentId,
				'specialist_id' => $currentSpecialistId,
				'admin_note' => $adminNote !== '' ? $adminNote : null,
			]);
			$pdo->commit();
			myRedirectWithMessage($returnPath, 'message', 'Programarea a fost respinsa.');
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau specialist reject appointment failed: ' . $exception->getMessage());
			myRedirectWithMessage($returnPath, 'error', 'Programarea nu a putut fi respinsa.');
		}
	}
}

$message = isset($_GET['message']) ? trim((string) $_GET['message']) : $message;
$error = isset($_GET['error']) ? trim((string) $_GET['error']) : $error;
$statusFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : 'all';

	if (!in_array($statusFilter, $allowedStatuses, true)) {
		$statusFilter = 'all';
	}

$dateFilterInput = isset($_GET['date']) ? trim((string) $_GET['date']) : '';
$dateFilter = $dateFilterInput !== '' ? parseBookingDate($dateFilterInput) : null;
$appointments = [];

if ($currentSpecialist !== null) {
	$where = ['a.specialist_id = :specialist_id'];
	$params = ['specialist_id' => (int) $currentSpecialist['id']];

	if ($statusFilter !== 'all') {
		$where[] = 'a.status = :status';
		$params['status'] = $statusFilter;
	}

	if ($dateFilter !== null) {
		$where[] = 'a.start_datetime >= :date_start AND a.start_datetime < :date_end';
		$params['date_start'] = $dateFilter->format('Y-m-d 00:00:00');
		$params['date_end'] = $dateFilter->add(new DateInterval('P1D'))->format('Y-m-d 00:00:00');
	}

	$statement = $pdo->prepare(
		"SELECT
			a.id,
			a.customer_name,
			a.customer_email,
			a.customer_phone,
			a.start_datetime,
			a.end_datetime,
			a.status,
			a.source,
			a.created_at,
			sv.name AS service_name
		 FROM appointments a
		 INNER JOIN services sv ON sv.id = a.service_id
		 WHERE " . implode(' AND ', $where) . "
		 ORDER BY
			CASE WHEN a.start_datetime >= NOW() AND a.status IN ('pending', 'approved') THEN 0 ELSE 1 END ASC,
			CASE WHEN a.start_datetime >= NOW() THEN a.start_datetime END ASC,
			a.created_at DESC"
	);
	$statement->execute($params);
	$appointments = $statement->fetchAll();
}

$currentQuery = $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
$currentPath = 'my-appointments.php' . $currentQuery;
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Programarile mele | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-7">
</head>
<body>
	<?php renderAdminHeader('Programarile mele', 'my-appointments.php', $csrfToken, $dashboardUser, $currentSpecialist); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">LISTA</p>
					<h2 class="admin-section-title">Programarile mele</h2>
				</div>
				<?php if ($currentSpecialist !== null): ?>
					<div class="admin-inline-actions">
						<a class="admin-button admin-link-button" href="my-create-appointment.php">Programare externa</a>
						<a class="admin-button admin-link-button" href="my-blocked-slots.php">Blocheaza timp</a>
					</div>
				<?php endif; ?>
			</div>

			<?php if ($message !== ''): ?>
				<p class="admin-alert admin-alert-success"><?php echo adminEscape($message); ?></p>
			<?php endif; ?>
			<?php if ($error !== ''): ?>
				<p class="admin-alert admin-alert-error"><?php echo adminEscape($error); ?></p>
			<?php endif; ?>

			<?php if ($currentSpecialist !== null): ?>
				<form class="admin-filters" method="get" action="my-appointments.php">
					<label>
						<span>Status</span>
						<select name="status">
							<?php foreach ($allowedStatuses as $status): ?>
								<option value="<?php echo adminEscape($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
									<?php echo $status === 'all' ? 'ALL' : adminEscape(adminFormatStatus($status)); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span>Data</span>
						<input type="date" name="date" value="<?php echo adminEscape($dateFilter !== null ? $dateFilter->format('Y-m-d') : ''); ?>">
					</label>
					<button class="admin-button" type="submit">Filtreaza</button>
					<a class="admin-reset-link" href="my-appointments.php">Reset</a>
				</form>
			<?php endif; ?>

			<?php if ($appointments === []): ?>
				<p class="admin-empty">Nu exista programari pentru filtrele curente.</p>
			<?php else: ?>
				<div class="admin-table-wrap">
					<table class="admin-table admin-appointments-table">
						<thead>
							<tr>
								<th>ID</th>
								<th>Client</th>
								<th>Email</th>
								<th>Telefon</th>
								<th>Serviciu</th>
								<th>Data</th>
								<th>Start</th>
								<th>End</th>
								<th>Sursa</th>
								<th>Status</th>
								<th>Creat</th>
								<th>Actiuni</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($appointments as $appointment): ?>
								<?php $status = (string) $appointment['status']; ?>
								<tr>
									<td data-label="ID">#<?php echo (int) $appointment['id']; ?></td>
									<td data-label="Client"><?php echo adminEscape((string) $appointment['customer_name']); ?></td>
									<td data-label="Email"><?php echo adminEscape((string) $appointment['customer_email']); ?></td>
									<td data-label="Telefon"><?php echo adminEscape((string) ($appointment['customer_phone'] ?? '-')); ?></td>
									<td data-label="Serviciu"><?php echo adminEscape((string) $appointment['service_name']); ?></td>
									<td data-label="Data"><?php echo adminEscape(adminFormatDate((string) $appointment['start_datetime'], 'd.m.Y')); ?></td>
									<td data-label="Start"><?php echo adminEscape(adminFormatDate((string) $appointment['start_datetime'], 'H:i')); ?></td>
									<td data-label="End"><?php echo adminEscape(adminFormatDate((string) $appointment['end_datetime'], 'H:i')); ?></td>
									<td data-label="Sursa"><?php echo adminEscape(strtoupper((string) $appointment['source'])); ?></td>
									<td data-label="Status">
										<span class="admin-status admin-status-<?php echo adminEscape($status); ?>">
											<?php echo adminEscape(adminFormatStatus($status)); ?>
										</span>
									</td>
									<td data-label="Creat"><?php echo adminEscape(adminFormatDate((string) $appointment['created_at'])); ?></td>
									<td data-label="Actiuni">
										<div class="admin-inline-actions">
											<a class="admin-small-link" href="appointment.php?id=<?php echo (int) $appointment['id']; ?>">Detalii</a>
											<?php if ($status === 'pending'): ?>
												<form method="post" action="my-appointments.php">
													<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
													<input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
													<input type="hidden" name="action" value="approve">
													<input type="hidden" name="return_to" value="<?php echo adminEscape($currentPath); ?>">
													<button class="admin-small-button" type="submit">APROBA</button>
												</form>
												<form method="post" action="my-appointments.php" onsubmit="return confirm('Respinge aceasta programare?');">
													<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
													<input type="hidden" name="appointment_id" value="<?php echo (int) $appointment['id']; ?>">
													<input type="hidden" name="action" value="reject">
													<input type="hidden" name="return_to" value="<?php echo adminEscape($currentPath); ?>">
													<input class="admin-note-input" type="text" name="admin_note" maxlength="1000" placeholder="Nota optionala">
													<button class="admin-small-button admin-danger-button" type="submit">RESPINGE</button>
												</form>
											<?php endif; ?>
										</div>
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
