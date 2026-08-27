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
$errors = [];
$values = [
	'start_date' => '',
	'start_time' => '',
	'end_date' => '',
	'end_time' => '',
	'all_day' => '0',
	'reason' => '',
];

if ($currentSpecialist === null) {
	$errors[] = 'Contul tau nu este legat de un specialist activ.';
}

if ($currentSpecialist !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) ? (string) $_POST['action'] : 'create';
	$specialistId = (int) $currentSpecialist['id'];

	if ($action === 'delete') {
		$blockedSlotId = filter_var($_POST['blocked_slot_id'] ?? null, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1],
		]);

		if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
			$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
		} elseif ($blockedSlotId === false) {
			$errors[] = 'Intervalul blocat nu a putut fi identificat.';
		} else {
			$deleteStatement = $pdo->prepare(
				'DELETE FROM blocked_slots
				 WHERE id = :id
					AND specialist_id = :specialist_id
					AND end_datetime >= NOW()'
			);
			$deleteStatement->execute([
				'id' => $blockedSlotId,
				'specialist_id' => $specialistId,
			]);
			$message = $deleteStatement->rowCount() === 1
				? 'Intervalul a fost deblocat.'
				: 'Intervalul nu a mai putut fi deblocat.';
		}
	}

	if ($action === 'create') {
	$values = array_merge($values, [
		'start_date' => isset($_POST['start_date']) ? trim((string) $_POST['start_date']) : '',
		'start_time' => isset($_POST['start_time']) ? trim((string) $_POST['start_time']) : '',
		'end_date' => isset($_POST['end_date']) ? trim((string) $_POST['end_date']) : '',
		'end_time' => isset($_POST['end_time']) ? trim((string) $_POST['end_time']) : '',
		'all_day' => isset($_POST['all_day']) ? '1' : '0',
		'reason' => isset($_POST['reason']) ? trim((string) $_POST['reason']) : '',
	]);

	$startDate = $values['start_date'] !== '' ? parseBookingDate($values['start_date']) : null;
	$endDate = $values['end_date'] !== '' ? parseBookingDate($values['end_date']) : null;
	$isAllDay = $values['all_day'] === '1';
	$startDateTime = null;
	$endDateTime = null;

	if ($startDate !== null && $endDate !== null && $isAllDay) {
		$startDateTime = $startDate->setTime(0, 0);
		$endDateTime = $endDate->add(new DateInterval('P1D'))->setTime(0, 0);
	} else {
		$startDateTime = $startDate !== null ? parseBookingTime($startDate, $values['start_time']) : null;
		$endDateTime = $endDate !== null ? parseBookingTime($endDate, $values['end_time']) : null;
	}

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
	}

	if ($startDateTime === null || $endDateTime === null) {
		$errors[] = 'Alege date si ore valide.';
	} elseif ($endDateTime <= $startDateTime) {
		$errors[] = 'Ora de final trebuie sa fie dupa ora de inceput.';
	} elseif (($isAllDay ? $endDateTime : $startDateTime) <= new DateTimeImmutable('now', getSalonTimezone())) {
		$errors[] = 'Nu poti bloca un interval din trecut.';
	}

	if (strlen($values['reason']) > 255) {
		$errors[] = 'Motivul poate avea maximum 255 de caractere.';
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
						'start_date' => '',
						'start_time' => '',
						'end_date' => '',
						'end_time' => '',
						'all_day' => '0',
						'reason' => '',
					];
				}
			}
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau specialist block slot failed: ' . $exception->getMessage());
			$errors[] = 'Intervalul nu a putut fi blocat.';
		} finally {
			if ($lockAcquired) {
				try {
					releaseBookingLock($pdo, $lockName);
				} catch (Throwable $releaseException) {
					error_log('Farmecul Tau specialist blocked slot lock release failed: ' . $releaseException->getMessage());
				}
			}
		}
	}
	}
}

$blockedSlots = [];

if ($currentSpecialist !== null) {
	$blockedStatement = $pdo->prepare(
		'SELECT id, start_datetime, end_datetime, reason, created_at
		 FROM blocked_slots
		 WHERE specialist_id = :specialist_id
			AND end_datetime >= NOW()
		 ORDER BY start_datetime ASC
		 LIMIT 80'
	);
	$blockedStatement->execute(['specialist_id' => (int) $currentSpecialist['id']]);
	$blockedSlots = $blockedStatement->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Timpul meu blocat | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260827-4">
</head>
<body>
	<?php renderAdminHeader('Timpul meu blocat', 'my-blocked-slots.php', $csrfToken, $dashboardUser, $currentSpecialist); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">AGENDA MEA</p>
					<h2 class="admin-section-title">Interval indisponibil</h2>
				</div>
				<a class="admin-reset-link" href="my-appointments.php">Inapoi la programarile mele</a>
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

			<?php if ($currentSpecialist !== null): ?>
				<form class="admin-form admin-form-grid" method="post" action="my-blocked-slots.php" data-block-slot-form>
					<label class="admin-checkbox-label admin-form-wide">
						<input type="checkbox" name="all_day" value="1" data-all-day-toggle <?php echo $values['all_day'] === '1' ? 'checked' : ''; ?>>
						<span>Blocheaza zile intregi sau o perioada lunga</span>
					</label>
					<label>
						<span>Data inceput</span>
						<input type="date" name="start_date" value="<?php echo adminEscape($values['start_date']); ?>" required>
					</label>
					<label data-time-field <?php echo $values['all_day'] === '1' ? 'hidden' : ''; ?>>
						<span>Ora inceput</span>
						<input type="time" name="start_time" step="1800" value="<?php echo adminEscape($values['start_time']); ?>" data-time-input <?php echo $values['all_day'] === '1' ? '' : 'required'; ?>>
					</label>
					<label>
						<span>Data final inclusiv</span>
						<input type="date" name="end_date" value="<?php echo adminEscape($values['end_date']); ?>" required>
					</label>
					<label data-time-field <?php echo $values['all_day'] === '1' ? 'hidden' : ''; ?>>
						<span>Ora final</span>
						<input type="time" name="end_time" step="1800" value="<?php echo adminEscape($values['end_time']); ?>" data-time-input <?php echo $values['all_day'] === '1' ? '' : 'required'; ?>>
					</label>
					<label class="admin-form-wide">
						<span>Motiv</span>
						<input type="text" name="reason" maxlength="255" value="<?php echo adminEscape($values['reason']); ?>" placeholder="Pauza, training, concediu...">
					</label>
					<input type="hidden" name="action" value="create">
					<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
					<button class="admin-button admin-form-wide" type="submit">Blocheaza intervalul</button>
				</form>
			<?php endif; ?>
		</section>

		<section class="admin-panel admin-panel-spaced">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">LISTA</p>
					<h2 class="admin-section-title">Intervalele mele blocate</h2>
				</div>
			</div>

			<?php if ($blockedSlots === []): ?>
				<p class="admin-empty">Nu exista intervale viitoare blocate.</p>
			<?php else: ?>
				<div class="admin-table-wrap">
					<table class="admin-table">
						<thead>
							<tr>
								<th>ID</th>
								<th>Start</th>
								<th>End</th>
								<th>Motiv</th>
								<th>Creat</th>
								<th>Actiune</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($blockedSlots as $blockedSlot): ?>
								<tr>
									<td data-label="ID">#<?php echo (int) $blockedSlot['id']; ?></td>
									<td data-label="Start"><?php echo adminEscape(adminFormatDate((string) $blockedSlot['start_datetime'])); ?></td>
									<td data-label="End"><?php echo adminEscape(adminFormatDate((string) $blockedSlot['end_datetime'])); ?></td>
									<td data-label="Motiv"><?php echo adminEscape((string) ($blockedSlot['reason'] ?? '-')); ?></td>
									<td data-label="Creat"><?php echo adminEscape(adminFormatDate((string) $blockedSlot['created_at'])); ?></td>
									<td data-label="Actiune">
										<form method="post" action="my-blocked-slots.php" onsubmit="return confirm('Deblochezi acest interval?');">
											<input type="hidden" name="action" value="delete">
											<input type="hidden" name="blocked_slot_id" value="<?php echo (int) $blockedSlot['id']; ?>">
											<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
											<button class="admin-small-button admin-danger-button" type="submit">DEBLOCHEAZA</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	</main>
	<script>
		document.querySelectorAll('[data-block-slot-form]').forEach((form) => {
			const allDayToggle = form.querySelector('[data-all-day-toggle]');
			const timeFields = Array.from(form.querySelectorAll('[data-time-field]'));
			const timeInputs = Array.from(form.querySelectorAll('[data-time-input]'));

			const syncAllDayMode = () => {
				const isAllDay = allDayToggle.checked;

				timeFields.forEach((field) => {
					field.hidden = isAllDay;
				});

				timeInputs.forEach((input) => {
					input.required = !isAllDay;
					if (isAllDay) {
						input.value = '';
					}
				});
			};

			allDayToggle.addEventListener('change', syncAllDayMode);
			syncAllDayMode();
		});
	</script>
</body>
</html>
