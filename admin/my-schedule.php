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
$warnings = [];
$weekdays = [
	1 => 'Luni',
	2 => 'Marti',
	3 => 'Miercuri',
	4 => 'Joi',
	5 => 'Vineri',
	6 => 'Sambata',
	7 => 'Duminica',
];

function scheduleTimeToInput(?string $time, string $fallback = ''): string
{
	return $time !== null && $time !== '' ? substr($time, 0, 5) : $fallback;
}

function validateScheduleInterval(string $start, string $end, string $label, array &$errors): bool
{
	if (!isValidBookingTimeValue($start) || !isValidBookingTimeValue($end)) {
		$errors[] = $label . ': completeaza ore valide.';
		return false;
	}

	if ($start >= $end) {
		$errors[] = $label . ': ora de inceput trebuie sa fie inainte de ora de final.';
		return false;
	}

	return true;
}

function countFutureAppointmentsOutsideSchedule(PDO $pdo, int $specialistId): int
{
	$statement = $pdo->prepare(
		"SELECT start_datetime, end_datetime
		 FROM appointments
		 WHERE specialist_id = :specialist_id
			AND status IN ('pending', 'approved')
			AND start_datetime >= NOW()"
	);
	$statement->execute(['specialist_id' => $specialistId]);
	$count = 0;

	foreach ($statement->fetchAll() as $appointment) {
		$start = new DateTimeImmutable((string) $appointment['start_datetime'], getSalonTimezone());
		$end = new DateTimeImmutable((string) $appointment['end_datetime'], getSalonTimezone());

		if (!bookingSlotFitsSchedule($start, $end, getSpecialistSchedulesForDate($pdo, $specialistId, $start))) {
			$count++;
		}
	}

	return $count;
}

function countAppointmentsOverlappingInterval(PDO $pdo, int $specialistId, DateTimeImmutable $start, DateTimeImmutable $end): int
{
	$statement = $pdo->prepare(
		"SELECT COUNT(*) AS overlap_count
		 FROM appointments
		 WHERE specialist_id = :specialist_id
			AND status IN ('pending', 'approved')
			AND start_datetime < :end_datetime
			AND end_datetime > :start_datetime"
	);
	$statement->execute([
		'specialist_id' => $specialistId,
		'start_datetime' => $start->format('Y-m-d H:i:s'),
		'end_datetime' => $end->format('Y-m-d H:i:s'),
	]);

	return (int) ($statement->fetch()['overlap_count'] ?? 0);
}

if ($currentSpecialist === null) {
	$errors[] = 'Contul tau nu este legat de un specialist activ.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
	$specialistId = (int) $currentSpecialist['id'];

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
	}

	if ($action === 'save_weekly_schedule') {
		$postedSchedule = isset($_POST['schedule']) && is_array($_POST['schedule']) ? $_POST['schedule'] : [];
		$rows = [];

		foreach ($weekdays as $dayOfWeek => $label) {
			$dayData = isset($postedSchedule[$dayOfWeek]) && is_array($postedSchedule[$dayOfWeek]) ? $postedSchedule[$dayOfWeek] : [];

			if (!isset($dayData['active'])) {
				continue;
			}

			$start = isset($dayData['start']) ? trim((string) $dayData['start']) : '';
			$end = isset($dayData['end']) ? trim((string) $dayData['end']) : '';

			if (!validateScheduleInterval($start, $end, $label, $errors)) {
				break;
			}

			$rows[] = [
				'day_of_week' => $dayOfWeek,
				'start_time' => $start . ':00',
				'end_time' => $end . ':00',
			];
		}

		if ($errors === []) {
			try {
				$pdo->beginTransaction();
				$deleteStatement = $pdo->prepare('DELETE FROM specialist_schedule WHERE specialist_id = :specialist_id');
				$deleteStatement->execute(['specialist_id' => $specialistId]);

				if ($rows !== []) {
					$insertStatement = $pdo->prepare(
						'INSERT INTO specialist_schedule (specialist_id, day_of_week, start_time, end_time, active)
						 VALUES (:specialist_id, :day_of_week, :start_time, :end_time, 1)'
					);

					foreach ($rows as $row) {
						$insertStatement->execute([
							'specialist_id' => $specialistId,
							'day_of_week' => $row['day_of_week'],
							'start_time' => $row['start_time'],
							'end_time' => $row['end_time'],
						]);
					}
				}

				$pdo->commit();
				$message = 'Programul general a fost salvat.';
				$outsideCount = countFutureAppointmentsOutsideSchedule($pdo, $specialistId);

				if ($outsideCount > 0) {
					$warnings[] = 'Exista ' . $outsideCount . ' programari deja create in afara noului interval.';
				}
			} catch (Throwable $exception) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}

				error_log('Farmecul Tau weekly schedule save failed: ' . $exception->getMessage());
				$errors[] = 'Programul general nu a putut fi salvat.';
			}
		}
	} elseif (in_array($action, ['create_exception', 'update_exception'], true)) {
		$exceptionId = $action === 'update_exception'
			? filter_var($_POST['exception_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
			: null;
		$dateInput = isset($_POST['exception_date']) ? trim((string) $_POST['exception_date']) : '';
		$date = $dateInput !== '' ? parseBookingDate($dateInput) : null;
		$isDayOff = isset($_POST['is_day_off']) ? 1 : 0;
		$start = isset($_POST['exception_start']) ? trim((string) $_POST['exception_start']) : '';
		$end = isset($_POST['exception_end']) ? trim((string) $_POST['exception_end']) : '';
		$note = isset($_POST['exception_note']) ? trim((string) $_POST['exception_note']) : '';

		if ($exceptionId === false) {
			$errors[] = 'Programul personalizat nu a putut fi identificat.';
		}

		if ($date === null || $date < new DateTimeImmutable('today', getSalonTimezone())) {
			$errors[] = 'Alege o data valida pentru programul personalizat.';
		}

		if ($isDayOff === 0) {
			validateScheduleInterval($start, $end, 'Program personalizat', $errors);
		}

		if (strlen($note) > 255) {
			$errors[] = 'Nota poate avea maximum 255 de caractere.';
		}

		if ($errors === []) {
			try {
				$pdo->beginTransaction();
				$params = [
					'specialist_id' => $specialistId,
					'date' => $date->format('Y-m-d'),
					'is_day_off' => $isDayOff,
					'start_time' => $isDayOff ? null : $start . ':00',
					'end_time' => $isDayOff ? null : $end . ':00',
					'note' => $note !== '' ? $note : null,
				];

				if ($action === 'create_exception') {
					$statement = $pdo->prepare(
						'INSERT INTO specialist_schedule_exceptions (specialist_id, date, is_day_off, start_time, end_time, note)
						 VALUES (:specialist_id, :date, :is_day_off, :start_time, :end_time, :note)
						 ON DUPLICATE KEY UPDATE
							is_day_off = VALUES(is_day_off),
							start_time = VALUES(start_time),
							end_time = VALUES(end_time),
							note = VALUES(note)'
					);
				} else {
					$statement = $pdo->prepare(
						'UPDATE specialist_schedule_exceptions
						 SET date = :date,
							is_day_off = :is_day_off,
							start_time = :start_time,
							end_time = :end_time,
							note = :note
						 WHERE id = :exception_id
							AND specialist_id = :specialist_id'
					);
					$params['exception_id'] = $exceptionId;
				}

				$statement->execute($params);
				$pdo->commit();
				$message = 'Programul personalizat a fost salvat.';
				$outsideCount = countFutureAppointmentsOutsideSchedule($pdo, $specialistId);

				if ($outsideCount > 0) {
					$warnings[] = 'Exista ' . $outsideCount . ' programari deja create in afara noului interval.';
				}
			} catch (Throwable $exception) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}

				error_log('Farmecul Tau schedule exception save failed: ' . $exception->getMessage());
				$errors[] = 'Programul personalizat nu a putut fi salvat.';
			}
		}
	} elseif ($action === 'delete_exception') {
		$exceptionId = filter_var($_POST['exception_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if ($exceptionId === false) {
			$errors[] = 'Programul personalizat nu a putut fi identificat.';
		}

		if ($errors === []) {
			$statement = $pdo->prepare(
				'DELETE FROM specialist_schedule_exceptions
				 WHERE id = :exception_id
					AND specialist_id = :specialist_id
					AND date >= CURDATE()'
			);
			$statement->execute([
				'exception_id' => $exceptionId,
				'specialist_id' => $specialistId,
			]);
			$message = 'Programul personalizat a fost sters.';
		}
	} elseif ($action === 'add_blocked_time') {
		$dateInput = isset($_POST['blocked_date']) ? trim((string) $_POST['blocked_date']) : '';
		$date = $dateInput !== '' ? parseBookingDate($dateInput) : null;
		$startInput = isset($_POST['blocked_start']) ? trim((string) $_POST['blocked_start']) : '';
		$endInput = isset($_POST['blocked_end']) ? trim((string) $_POST['blocked_end']) : '';
		$reason = isset($_POST['blocked_reason']) ? trim((string) $_POST['blocked_reason']) : '';
		$start = $date !== null ? parseBookingTime($date, $startInput) : null;
		$end = $date !== null ? parseBookingTime($date, $endInput) : null;

		if ($date === null || $date < new DateTimeImmutable('today', getSalonTimezone())) {
			$errors[] = 'Alege o data valida pentru timpul blocat.';
		}

		if ($start === null || $end === null || $start >= $end) {
			$errors[] = 'Intervalul blocat trebuie sa aiba ore valide si start inainte de final.';
		}

		if (strlen($reason) > 255) {
			$errors[] = 'Motivul poate avea maximum 255 de caractere.';
		}

		if ($errors === []) {
			$statement = $pdo->prepare(
				'INSERT INTO blocked_slots (specialist_id, start_datetime, end_datetime, reason)
				 VALUES (:specialist_id, :start_datetime, :end_datetime, :reason)'
			);
			$statement->execute([
				'specialist_id' => $specialistId,
				'start_datetime' => $start->format('Y-m-d H:i:s'),
				'end_datetime' => $end->format('Y-m-d H:i:s'),
				'reason' => $reason !== '' ? $reason : null,
			]);
			$message = 'Timpul blocat a fost adaugat.';
			$overlapCount = countAppointmentsOverlappingInterval($pdo, $specialistId, $start, $end);

			if ($overlapCount > 0) {
				$warnings[] = 'Exista ' . $overlapCount . ' programari deja create in intervalul blocat.';
			}
		}
	} elseif ($action === 'delete_blocked_time') {
		$blockedId = filter_var($_POST['blocked_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if ($blockedId === false) {
			$errors[] = 'Intervalul blocat nu a putut fi identificat.';
		}

		if ($errors === []) {
			$statement = $pdo->prepare(
				'DELETE FROM blocked_slots
				 WHERE id = :blocked_id
					AND specialist_id = :specialist_id
					AND start_datetime >= NOW()'
			);
			$statement->execute([
				'blocked_id' => $blockedId,
				'specialist_id' => $specialistId,
			]);
			$message = 'Timpul blocat a fost sters.';
		}
	} elseif ($errors === []) {
		$errors[] = 'Actiunea nu este valida.';
	}
}

$scheduleByDay = [];
$exceptions = [];
$blockedSlots = [];

foreach (array_keys($weekdays) as $dayOfWeek) {
	$scheduleByDay[$dayOfWeek] = null;
}

if ($currentSpecialist !== null) {
	$scheduleStatement = $pdo->prepare(
		'SELECT day_of_week, start_time, end_time
		 FROM specialist_schedule
		 WHERE specialist_id = :specialist_id
			AND active = 1
		 ORDER BY day_of_week ASC'
	);
	$scheduleStatement->execute(['specialist_id' => (int) $currentSpecialist['id']]);

	foreach ($scheduleStatement->fetchAll() as $row) {
		$scheduleByDay[(int) $row['day_of_week']] = $row;
	}

	$exceptionStatement = $pdo->prepare(
		'SELECT id, date, is_day_off, start_time, end_time, note
		 FROM specialist_schedule_exceptions
		 WHERE specialist_id = :specialist_id
			AND date >= CURDATE()
		 ORDER BY date ASC'
	);
	$exceptionStatement->execute(['specialist_id' => (int) $currentSpecialist['id']]);
	$exceptions = $exceptionStatement->fetchAll();

	$blockedStatement = $pdo->prepare(
		'SELECT id, start_datetime, end_datetime, reason
		 FROM blocked_slots
		 WHERE specialist_id = :specialist_id
			AND start_datetime >= NOW()
		 ORDER BY start_datetime ASC'
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
	<title>Programul meu | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260828-1">
</head>
<body>
	<?php renderAdminHeader('Programul meu', 'my-schedule.php', $csrfToken, $dashboardUser, $currentSpecialist); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">AGENDA MEA</p>
					<h2 class="admin-section-title">Programul meu</h2>
				</div>
			</div>

			<?php if ($message !== ''): ?>
				<p class="admin-alert admin-alert-success"><?php echo adminEscape($message); ?></p>
			<?php endif; ?>
			<?php foreach ($warnings as $warning): ?>
				<p class="admin-alert admin-alert-error"><?php echo adminEscape($warning); ?></p>
			<?php endforeach; ?>
			<?php if ($errors !== []): ?>
				<div class="admin-alert admin-alert-error">
					<?php foreach ($errors as $formError): ?>
						<p><?php echo adminEscape($formError); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ($currentSpecialist !== null): ?>
				<section class="admin-schedule-section">
					<h3>Program general</h3>
					<form class="admin-form" method="post" action="my-schedule.php">
						<div class="admin-table-wrap">
							<table class="admin-table">
								<thead>
									<tr>
										<th>Zi</th>
										<th>Lucrez</th>
										<th>Start</th>
										<th>Final</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($weekdays as $dayOfWeek => $label): ?>
										<?php $row = $scheduleByDay[$dayOfWeek]; ?>
										<tr>
											<td data-label="Zi"><?php echo adminEscape($label); ?></td>
											<td data-label="Lucrez">
												<label class="admin-checkbox-label">
													<input type="checkbox" name="schedule[<?php echo $dayOfWeek; ?>][active]" value="1" <?php echo $row !== null ? 'checked' : ''; ?>>
													<span>Da</span>
												</label>
											</td>
											<td data-label="Start">
												<input type="time" name="schedule[<?php echo $dayOfWeek; ?>][start]" value="<?php echo adminEscape(scheduleTimeToInput($row['start_time'] ?? null, '09:00')); ?>">
											</td>
											<td data-label="Final">
												<input type="time" name="schedule[<?php echo $dayOfWeek; ?>][end]" value="<?php echo adminEscape(scheduleTimeToInput($row['end_time'] ?? null, '17:00')); ?>">
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
						<input type="hidden" name="action" value="save_weekly_schedule">
						<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
						<button class="admin-button" type="submit">Salveaza programul general</button>
					</form>
				</section>

				<section class="admin-schedule-section">
					<h3>Program personalizat</h3>
					<form class="admin-form admin-form-grid" method="post" action="my-schedule.php">
						<label>
							<span>Data</span>
							<input type="date" name="exception_date" min="<?php echo adminEscape((new DateTimeImmutable('today', getSalonTimezone()))->format('Y-m-d')); ?>" required>
						</label>
						<label class="admin-checkbox-label">
							<input type="checkbox" name="is_day_off" value="1">
							<span>Zi libera</span>
						</label>
						<label>
							<span>Start</span>
							<input type="time" name="exception_start" value="09:00">
						</label>
						<label>
							<span>Final</span>
							<input type="time" name="exception_end" value="17:00">
						</label>
						<label class="admin-form-wide">
							<span>Nota</span>
							<input type="text" name="exception_note" maxlength="255">
						</label>
						<input type="hidden" name="action" value="create_exception">
						<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
						<button class="admin-button admin-form-wide" type="submit">+ ADAUGA PROGRAM PERSONALIZAT</button>
					</form>

					<?php if ($exceptions === []): ?>
						<p class="admin-empty">Nu ai programe personalizate viitoare.</p>
					<?php else: ?>
						<div class="admin-table-wrap">
							<table class="admin-table">
								<thead>
									<tr>
										<th>Data</th>
										<th>Zi libera</th>
										<th>Start</th>
										<th>Final</th>
										<th>Nota</th>
										<th>Actiuni</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($exceptions as $exception): ?>
										<?php $formId = 'exception-form-' . (int) $exception['id']; ?>
										<tr>
											<td data-label="Data">
												<input form="<?php echo adminEscape($formId); ?>" type="date" name="exception_date" value="<?php echo adminEscape((string) $exception['date']); ?>" min="<?php echo adminEscape((new DateTimeImmutable('today', getSalonTimezone()))->format('Y-m-d')); ?>" required>
											</td>
											<td data-label="Zi libera">
												<label class="admin-checkbox-label">
													<input form="<?php echo adminEscape($formId); ?>" type="checkbox" name="is_day_off" value="1" <?php echo (int) $exception['is_day_off'] === 1 ? 'checked' : ''; ?>>
													<span>Da</span>
												</label>
											</td>
											<td data-label="Start">
												<input form="<?php echo adminEscape($formId); ?>" type="time" name="exception_start" value="<?php echo adminEscape(scheduleTimeToInput($exception['start_time'] ?? null)); ?>">
											</td>
											<td data-label="Final">
												<input form="<?php echo adminEscape($formId); ?>" type="time" name="exception_end" value="<?php echo adminEscape(scheduleTimeToInput($exception['end_time'] ?? null)); ?>">
											</td>
											<td data-label="Nota">
												<input form="<?php echo adminEscape($formId); ?>" type="text" name="exception_note" maxlength="255" value="<?php echo adminEscape((string) ($exception['note'] ?? '')); ?>">
											</td>
											<td data-label="Actiuni">
												<form id="<?php echo adminEscape($formId); ?>" method="post" action="my-schedule.php">
													<input type="hidden" name="action" value="update_exception">
													<input type="hidden" name="exception_id" value="<?php echo (int) $exception['id']; ?>">
													<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
													<button class="admin-small-button" type="submit">SALVEAZA</button>
												</form>
												<form method="post" action="my-schedule.php" onsubmit="return confirm('Stergi acest program personalizat?');">
													<input type="hidden" name="action" value="delete_exception">
													<input type="hidden" name="exception_id" value="<?php echo (int) $exception['id']; ?>">
													<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
													<button class="admin-small-button admin-danger-button" type="submit">STERGE</button>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>

				<section class="admin-schedule-section">
					<h3>Timp blocat</h3>
					<form class="admin-form admin-form-grid" method="post" action="my-schedule.php">
						<label>
							<span>Data</span>
							<input type="date" name="blocked_date" min="<?php echo adminEscape((new DateTimeImmutable('today', getSalonTimezone()))->format('Y-m-d')); ?>" required>
						</label>
						<label>
							<span>Start</span>
							<input type="time" name="blocked_start" required>
						</label>
						<label>
							<span>Final</span>
							<input type="time" name="blocked_end" required>
						</label>
						<label>
							<span>Motiv</span>
							<input type="text" name="blocked_reason" maxlength="255">
						</label>
						<input type="hidden" name="action" value="add_blocked_time">
						<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
						<button class="admin-button admin-form-wide" type="submit">Adauga timp blocat</button>
					</form>

					<?php if ($blockedSlots === []): ?>
						<p class="admin-empty">Nu ai intervale blocate viitoare.</p>
					<?php else: ?>
						<div class="admin-table-wrap">
							<table class="admin-table">
								<thead>
									<tr>
										<th>Data</th>
										<th>Interval</th>
										<th>Motiv</th>
										<th>Actiuni</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($blockedSlots as $blockedSlot): ?>
										<tr>
											<td data-label="Data"><?php echo adminEscape(adminFormatDate((string) $blockedSlot['start_datetime'], 'd.m.Y')); ?></td>
											<td data-label="Interval">
												<?php echo adminEscape(adminFormatDate((string) $blockedSlot['start_datetime'], 'H:i')); ?>
												-
												<?php echo adminEscape(adminFormatDate((string) $blockedSlot['end_datetime'], 'H:i')); ?>
											</td>
											<td data-label="Motiv"><?php echo adminEscape((string) ($blockedSlot['reason'] ?? '-')); ?></td>
											<td data-label="Actiuni">
												<form method="post" action="my-schedule.php" onsubmit="return confirm('Stergi acest interval blocat?');">
													<input type="hidden" name="action" value="delete_blocked_time">
													<input type="hidden" name="blocked_id" value="<?php echo (int) $blockedSlot['id']; ?>">
													<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
													<button class="admin-small-button admin-danger-button" type="submit">STERGE</button>
												</form>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</section>
			<?php endif; ?>
		</section>
	</main>
</body>
</html>
