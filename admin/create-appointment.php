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
$allowedSources = ['admin', 'phone', 'instagram', 'other'];
$values = [
	'customer_name' => '',
	'customer_email' => '',
	'customer_phone' => '',
	'service_id' => '',
	'specialist_id' => '',
	'date' => '',
	'time' => '',
	'source' => 'phone',
	'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$values = array_merge($values, [
		'customer_name' => isset($_POST['customer_name']) ? trim((string) $_POST['customer_name']) : '',
		'customer_email' => isset($_POST['customer_email']) ? strtolower(trim((string) $_POST['customer_email'])) : '',
		'customer_phone' => isset($_POST['customer_phone']) ? trim((string) $_POST['customer_phone']) : '',
		'service_id' => isset($_POST['service_id']) ? (string) $_POST['service_id'] : '',
		'specialist_id' => isset($_POST['specialist_id']) ? (string) $_POST['specialist_id'] : '',
		'date' => isset($_POST['date']) ? trim((string) $_POST['date']) : '',
		'time' => isset($_POST['time']) ? trim((string) $_POST['time']) : '',
		'source' => isset($_POST['source']) ? strtolower(trim((string) $_POST['source'])) : 'phone',
		'notes' => isset($_POST['notes']) ? trim((string) $_POST['notes']) : '',
	]);

	$serviceId = filter_var($values['service_id'], FILTER_VALIDATE_INT, [
		'options' => ['min_range' => 1],
	]);
	$specialistId = filter_var($values['specialist_id'], FILTER_VALIDATE_INT, [
		'options' => ['min_range' => 1],
	]);
	$date = $values['date'] !== '' ? parseBookingDate($values['date']) : null;
	$candidateStart = $date !== null ? parseBookingTime($date, $values['time']) : null;
	$customerEmail = $values['customer_email'] !== '' ? $values['customer_email'] : null;

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reîncarcă pagina și încearcă din nou.';
	}

	if ($values['customer_name'] === '' || strlen($values['customer_name']) > 150) {
		$errors[] = 'Introdu numele clientei.';
	}

	if ($customerEmail !== null && (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || strlen($customerEmail) > 255)) {
		$errors[] = 'Emailul clientei nu este valid.';
	}

	if ($values['customer_phone'] === '' || strlen($values['customer_phone']) > 50 || !preg_match('/^[0-9+\s().-]{6,50}$/', $values['customer_phone'])) {
		$errors[] = 'Introdu un număr de telefon valid.';
	}

	if ($serviceId === false) {
		$errors[] = 'Alege un serviciu valid.';
	}

	if ($specialistId === false) {
		$errors[] = 'Alege un specialist valid.';
	}

	if ($date === null) {
		$errors[] = 'Alege o dată validă.';
	} elseif ($date < new DateTimeImmutable('today', getSalonTimezone())) {
		$errors[] = 'Data nu poate fi în trecut.';
	}

	if ($candidateStart === null) {
		$errors[] = 'Alege o oră validă.';
	} elseif (!in_array((int) $candidateStart->format('i'), [0, 30], true)) {
		$errors[] = 'Alege o oră disponibilă din listă.';
	}

	if (!in_array($values['source'], $allowedSources, true)) {
		$errors[] = 'Alege o sursă validă.';
	}

	if (strlen($values['notes']) > 1000) {
		$errors[] = 'Observațiile pot avea maximum 1000 de caractere.';
	}

	if ($errors === []) {
		$lockName = getBookingLockName($specialistId, $date);
		$lockAcquired = false;

		try {
			$lockAcquired = acquireBookingLock($pdo, $lockName);

			if (!$lockAcquired) {
				$errors[] = 'Intervalul nu mai este disponibil. Alege altă oră.';
			} else {
				$pdo->beginTransaction();
				$bookingContext = getBookingContext($pdo, $serviceId, $specialistId, true);

				if ($bookingContext === null) {
					$pdo->rollBack();
					$errors[] = 'Serviciul sau specialistul nu este disponibil.';
				} else {
					$durationMinutes = (int) $bookingContext['duration_minutes'];
					$priceAtBooking = (float) $bookingContext['price'];

					if ($durationMinutes <= 0) {
						$pdo->rollBack();
						$errors[] = 'Durata serviciului nu este configurată corect.';
					} else {
						$candidateEnd = $candidateStart->add(new DateInterval('PT' . $durationMinutes . 'M'));

						if (!isBookingSlotAvailable($pdo, $specialistId, $candidateStart, $candidateEnd, true)) {
							$pdo->rollBack();
							$errors[] = 'Intervalul nu mai este disponibil. Alege altă oră.';
						} else {
							$insertStatement = $pdo->prepare(
								"INSERT INTO appointments (
									customer_user_id,
									customer_name,
									customer_email,
									customer_phone,
									service_id,
									specialist_id,
									start_datetime,
									end_datetime,
									price_at_booking,
									duration_minutes_at_booking,
									status,
									source,
									notes
								) VALUES (
									NULL,
									:customer_name,
									:customer_email,
									:customer_phone,
									:service_id,
									:specialist_id,
									:start_datetime,
									:end_datetime,
									:price_at_booking,
									:duration_minutes_at_booking,
									'approved',
									:source,
									:notes
								)"
							);
							$insertStatement->execute([
								'customer_name' => $values['customer_name'],
								'customer_email' => $customerEmail,
								'customer_phone' => $values['customer_phone'],
								'service_id' => $serviceId,
								'specialist_id' => $specialistId,
								'start_datetime' => $candidateStart->format('Y-m-d H:i:s'),
								'end_datetime' => $candidateEnd->format('Y-m-d H:i:s'),
								'price_at_booking' => number_format($priceAtBooking, 2, '.', ''),
								'duration_minutes_at_booking' => $durationMinutes,
								'source' => $values['source'],
								'notes' => $values['notes'] !== '' ? $values['notes'] : null,
							]);
							$appointmentId = (int) $pdo->lastInsertId();
							$pdo->commit();
							$message = 'Programarea manuală a fost adăugată și aprobată.';
							$values = [
								'customer_name' => '',
								'customer_email' => '',
								'customer_phone' => '',
								'service_id' => '',
								'specialist_id' => '',
								'date' => '',
								'time' => '',
								'source' => 'phone',
								'notes' => '',
							];
							$message .= ' ID: #' . $appointmentId . '.';
						}
					}
				}
			}
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau admin create appointment failed: ' . $exception->getMessage());
			$errors[] = 'Programarea manuală nu a putut fi creată.';
		} finally {
			if ($lockAcquired) {
				try {
					releaseBookingLock($pdo, $lockName);
				} catch (Throwable $releaseException) {
					error_log('Farmecul Tau admin manual booking lock release failed: ' . $releaseException->getMessage());
				}
			}
		}
	}
}

$serviceStatement = $pdo->prepare(
	'SELECT id, name
	 FROM services
	 WHERE active = 1
	 ORDER BY name ASC'
);
$serviceStatement->execute();
$services = $serviceStatement->fetchAll();
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Adaugă programare | Farmecul Tău</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-7">
</head>
<body>
	<?php renderAdminHeader('Adaugă programare', 'appointments.php', $csrfToken); ?>

	<main class="admin-page">
		<section class="admin-panel" data-admin-manual-booking>
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">MANUAL</p>
					<h2 class="admin-section-title">Programare confirmată</h2>
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

			<form class="admin-form admin-form-grid" method="post" action="create-appointment.php">
				<label>
					<span>Nume client</span>
					<input type="text" name="customer_name" maxlength="150" value="<?php echo adminEscape($values['customer_name']); ?>" required>
				</label>
				<label>
					<span>Email client</span>
					<input type="email" name="customer_email" maxlength="255" value="<?php echo adminEscape($values['customer_email']); ?>">
				</label>
				<label>
					<span>Telefon client</span>
					<input type="tel" name="customer_phone" maxlength="50" value="<?php echo adminEscape($values['customer_phone']); ?>" required>
				</label>
				<label>
					<span>Sursă</span>
					<select name="source" required>
						<?php foreach ($allowedSources as $source): ?>
							<option value="<?php echo adminEscape($source); ?>" <?php echo $values['source'] === $source ? 'selected' : ''; ?>>
								<?php echo adminEscape(strtoupper($source)); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>Serviciu</span>
					<select name="service_id" data-admin-service required>
						<option value="">Alege serviciul</option>
						<?php foreach ($services as $service): ?>
							<option value="<?php echo (int) $service['id']; ?>" <?php echo $values['service_id'] === (string) $service['id'] ? 'selected' : ''; ?>>
								<?php echo adminEscape((string) $service['name']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span>Specialist</span>
					<select name="specialist_id" data-admin-specialist data-selected-value="<?php echo adminEscape($values['specialist_id']); ?>" required disabled>
						<option value="">Alege întâi serviciul</option>
					</select>
				</label>
				<label>
					<span>Data</span>
					<input type="date" name="date" data-admin-date value="<?php echo adminEscape($values['date']); ?>" required>
				</label>
				<label>
					<span>Ora</span>
					<select name="time" data-admin-time data-selected-value="<?php echo adminEscape($values['time']); ?>" required disabled>
						<option value="">Alege serviciu, specialist și dată</option>
					</select>
				</label>
				<label class="admin-form-wide">
					<span>Observații</span>
					<textarea name="notes" maxlength="1000" rows="4"><?php echo adminEscape($values['notes']); ?></textarea>
				</label>
				<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
				<p class="admin-form-message admin-form-wide" data-admin-booking-status></p>
				<button class="admin-button admin-form-wide" type="submit">Adaugă programarea</button>
			</form>
		</section>
	</main>

	<script src="../js/admin-appointment-form.js?v=20260827-1"></script>
</body>
</html>
