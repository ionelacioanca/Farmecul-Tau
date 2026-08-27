<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/booking.php';

setSalonTimezone();
$dashboardUser = requireAdminUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);

$csrfToken = getAdminCsrfToken();
$message = '';
$errors = [];
$values = [
	'specialist_id' => '',
	'start_date' => '',
	'start_time' => '',
	'end_date' => '',
	'end_time' => '',
	'reason' => '',
];

$specialistStatement = $pdo->prepare(
	'SELECT id, name
	 FROM specialists
	 WHERE active = 1
	 ORDER BY name ASC'
);
$specialistStatement->execute();
$specialists = $specialistStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$values = array_merge($values, [
		'specialist_id' => isset($_POST['specialist_id']) ? (string) $_POST['specialist_id'] : '',
		'start_date' => isset($_POST['start_date']) ? trim((string) $_POST['start_date']) : '',
		'start_time' => isset($_POST['start_time']) ? trim((string) $_POST['start_time']) : '',
		'end_date' => isset($_POST['end_date']) ? trim((string) $_POST['end_date']) : '',
		'end_time' => isset($_POST['end_time']) ? trim((string) $_POST['end_time']) : '',
		'reason' => isset($_POST['reason']) ? trim((string) $_POST['reason']) : '',
	]);

	$specialistId = filter_var($values['specialist_id'], FILTER_VALIDATE_INT, [
		'options' => ['min_range' => 1],
	]);
	$startDate = $values['start_date'] !== '' ? parseBookingDate($values['start_date']) : null;
	$endDate = $values['end_date'] !== '' ? parseBookingDate($values['end_date']) : null;
	$startDateTime = $startDate !== null ? parseBookingTime($startDate, $values['start_time']) : null;
	$endDateTime = $endDate !== null ? parseBookingTime($endDate, $values['end_time']) : null;

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.';
	}

	if ($specialistId === false) {
		$errors[] = 'Alege un specialist valid.';
	}

	if ($startDateTime === null || $endDateTime === null) {
		$errors[] = 'Alege date și ore valide.';
	} elseif ($endDateTime <= $startDateTime) {
		$errors[] = 'Ora de final trebuie să fie după ora de început.';
	} elseif ($startDateTime <= new DateTimeImmutable('now', getSalonTimezone())) {
		$errors[] = 'Nu poți bloca un interval din trecut.';
	}

	if (strlen($values['reason']) > 255) {
		$errors[] = 'Motivul poate avea maximum 255 de caractere.';
	}

	if ($errors === []) {
		$specialistExists = false;

		foreach ($specialists as $specialist) {
			if ((int) $specialist['id'] === $specialistId) {
				$specialistExists = true;
				break;
			}
		}

		if (!$specialistExists) {
			$errors[] = 'Specialistul nu este disponibil.';
		}
	}

	if ($errors === []) {
		$lockName = getBookingLockName($specialistId, $startDateTime);
		$lockAcquired = false;

		try {
			$lockAcquired = acquireBookingLock($pdo, $lockName);

			if (!$lockAcquired) {
				$errors[] = 'Intervalul nu mai este disponibil.';
			} else {
				$pdo->beginTransaction();

				if (bookingSlotHasOverlaps($pdo, $specialistId, $startDateTime, $endDateTime, true)) {
					$pdo->rollBack();
					$errors[] = 'Intervalul se suprapune cu o programare sau cu un blocaj existent.';
				} else {
					$insertStatement = $pdo->prepare(
						'INSERT INTO blocked_slots (specialist_id, start_datetime, end_datetime, reason)
						 VALUES (:specialist_id, :start_datetime, :end_datetime, :reason)'
					);
					$insertStatement->execute([
						'specialist_id' => $specialistId,
						'start_datetime' => $startDateTime->format('Y-m-d H:i:s'),
						'end_datetime' => $endDateTime->format('Y-m-d H:i:s'),
						'reason' => $values['reason'] !== '' ? $values['reason'] : null,
					]);
					$pdo->commit();
					$message = 'Intervalul a fost blocat.';
					$values = [
						'specialist_id' => '',
						'start_date' => '',
						'start_time' => '',
						'end_date' => '',
						'end_time' => '',
						'reason' => '',
					];
				}
			}
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau admin block slot failed: ' . $exception->getMessage());
			$errors[] = 'Intervalul nu a putut fi blocat.';
		} finally {
			if ($lockAcquired) {
				try {
					releaseBookingLock($pdo, $lockName);
				} catch (Throwable $releaseException) {
					error_log('Farmecul Tau admin blocked slot lock release failed: ' . $releaseException->getMessage());
				}
			}
		}
	}
}

$blockedStatement = $pdo->prepare(
	'SELECT
		b.id,
		b.start_datetime,
		b.end_datetime,
		b.reason,
		b.created_at,
		sp.name AS specialist_name
	 FROM blocked_slots b
	 INNER JOIN specialists sp ON sp.id = b.specialist_id
	 WHERE b.end_datetime >= NOW()
	 ORDER BY b.start_datetime ASC
	 LIMIT 80'
);
$blockedStatement->execute();
$blockedSlots = $blockedStatement->fetchAll();
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Timp blocat | Farmecul Tău</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-7">
</head>
<body>
	<?php renderAdminHeader('Timp blocat', 'blocked-slots.php', $csrfToken); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">BLOCARE</p>
					<h2 class="admin-section-title">Interval indisponibil</h2>
				</div>
				<a class="admin-reset-link" href="appointments.php">Înapoi la programări</a>
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

			<form class="admin-form admin-form-grid" method="post" action="blocked-slots.php">
				<label>
					<span>Specialist</span>
					<select name="specialist_id" required>
						<option value="">Alege specialistul</option>
						<?php foreach ($specialists as $specialist): ?>
							<option value="<?php echo (int) $specialist['id']; ?>" <?php echo $values['specialist_id'] === (string) $specialist['id'] ? 'selected' : ''; ?>>
								<?php echo adminEscape((string) $specialist['name']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>Data început</span>
					<input type="date" name="start_date" value="<?php echo adminEscape($values['start_date']); ?>" required>
				</label>
				<label>
					<span>Ora început</span>
					<input type="time" name="start_time" step="1800" value="<?php echo adminEscape($values['start_time']); ?>" required>
				</label>
				<label>
					<span>Data final</span>
					<input type="date" name="end_date" value="<?php echo adminEscape($values['end_date']); ?>" required>
				</label>
				<label>
					<span>Ora final</span>
					<input type="time" name="end_time" step="1800" value="<?php echo adminEscape($values['end_time']); ?>" required>
				</label>
				<label class="admin-form-wide">
					<span>Motiv</span>
					<input type="text" name="reason" maxlength="255" value="<?php echo adminEscape($values['reason']); ?>" placeholder="Lunch, training, holiday...">
				</label>
				<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
				<button class="admin-button admin-form-wide" type="submit">Blochează intervalul</button>
			</form>
		</section>

		<section class="admin-panel admin-panel-spaced">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">LISTĂ</p>
					<h2 class="admin-section-title">Intervale viitoare blocate</h2>
				</div>
			</div>

			<?php if ($blockedSlots === []): ?>
				<p class="admin-empty">Nu există intervale viitoare blocate.</p>
			<?php else: ?>
				<div class="admin-table-wrap">
					<table class="admin-table">
						<thead>
							<tr>
								<th>ID</th>
								<th>Specialist</th>
								<th>Start</th>
								<th>End</th>
								<th>Motiv</th>
								<th>Creat</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($blockedSlots as $blockedSlot): ?>
								<tr>
									<td data-label="ID">#<?php echo (int) $blockedSlot['id']; ?></td>
									<td data-label="Specialist"><?php echo adminEscape((string) $blockedSlot['specialist_name']); ?></td>
									<td data-label="Start"><?php echo adminEscape(adminFormatDate((string) $blockedSlot['start_datetime'])); ?></td>
									<td data-label="End"><?php echo adminEscape(adminFormatDate((string) $blockedSlot['end_datetime'])); ?></td>
									<td data-label="Motiv"><?php echo adminEscape((string) ($blockedSlot['reason'] ?? '-')); ?></td>
									<td data-label="Creat"><?php echo adminEscape(adminFormatDate((string) $blockedSlot['created_at'])); ?></td>
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
