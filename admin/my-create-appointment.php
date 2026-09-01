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
$allowedSources = ['phone', 'instagram', 'other'];
$values = [
	'customer_name' => '',
	'customer_email' => '',
	'customer_phone' => '',
	'service_id' => '',
	'date' => '',
	'time' => '',
	'source' => 'phone',
	'notes' => '',
];

if ($currentSpecialist === null) {
	$errors[] = 'Contul tau nu este legat de un specialist activ.';
}

if ($currentSpecialist !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$values = array_merge($values, [
		'customer_name' => isset($_POST['customer_name']) ? trim((string) $_POST['customer_name']) : '',
		'customer_email' => isset($_POST['customer_email']) ? strtolower(trim((string) $_POST['customer_email'])) : '',
		'customer_phone' => isset($_POST['customer_phone']) ? trim((string) $_POST['customer_phone']) : '',
		'service_id' => isset($_POST['service_id']) ? (string) $_POST['service_id'] : '',
		'date' => isset($_POST['date']) ? trim((string) $_POST['date']) : '',
		'time' => isset($_POST['time']) ? trim((string) $_POST['time']) : '',
		'source' => isset($_POST['source']) ? strtolower(trim((string) $_POST['source'])) : 'phone',
		'notes' => isset($_POST['notes']) ? trim((string) $_POST['notes']) : '',
	]);

	$serviceId = filter_var($values['service_id'], FILTER_VALIDATE_INT, [
		'options' => ['min_range' => 1],
	]);
	$specialistId = (int) $currentSpecialist['id'];
	$date = $values['date'] !== '' ? parseBookingDate($values['date']) : null;
	$candidateStart = $date !== null ? parseBookingTime($date, $values['time']) : null;
	$customerEmail = $values['customer_email'] !== '' ? $values['customer_email'] : null;

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
	}

	if ($values['customer_name'] === '' || strlen($values['customer_name']) > 150) {
		$errors[] = 'Introdu numele clientei.';
	}

	if ($customerEmail !== null && (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || strlen($customerEmail) > 255)) {
		$errors[] = 'Emailul clientei nu este valid.';
	}

	if ($values['customer_phone'] === '' || strlen($values['customer_phone']) > 50 || !preg_match('/^[0-9+\s().-]{6,50}$/', $values['customer_phone'])) {
		$errors[] = 'Introdu un numar de telefon valid.';
	}

	if ($serviceId === false) {
		$errors[] = 'Alege un serviciu valid.';
	}

	if ($date === null) {
		$errors[] = 'Alege o data valida.';
	} elseif ($date < new DateTimeImmutable('today', getSalonTimezone())) {
		$errors[] = 'Data nu poate fi in trecut.';
	}

	if ($candidateStart === null) {
		$errors[] = 'Alege o ora valida.';
	} elseif (!in_array((int) $candidateStart->format('i'), [0, 30], true)) {
		$errors[] = 'Alege o ora disponibila din lista.';
	}

	if (!in_array($values['source'], $allowedSources, true)) {
		$errors[] = 'Alege o sursa valida.';
	}

	if (strlen($values['notes']) > 1000) {
		$errors[] = 'Observatiile pot avea maximum 1000 de caractere.';
	}

	if ($errors === []) {
		$lockName = getBookingLockName($specialistId, $date);
		$lockAcquired = false;

		try {
			$lockAcquired = acquireBookingLock($pdo, $lockName);

			if (!$lockAcquired) {
				$errors[] = 'Intervalul nu mai este disponibil. Alege alta ora.';
			} else {
				$pdo->beginTransaction();
				$bookingContext = getBookingContext($pdo, $serviceId, $specialistId, true);

				if ($bookingContext === null) {
					$pdo->rollBack();
					$errors[] = 'Serviciul nu este disponibil pentru contul tau.';
				} else {
					$durationMinutes = (int) $bookingContext['duration_minutes'];
					$priceAtBooking = (float) $bookingContext['price'];
					$candidateEnd = $candidateStart->add(new DateInterval('PT' . $durationMinutes . 'M'));

					if ($durationMinutes <= 0 || !isBookingSlotAvailable($pdo, $specialistId, $candidateStart, $candidateEnd, true)) {
						$pdo->rollBack();
						$errors[] = 'Intervalul nu mai este disponibil. Alege alta ora.';
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
						$message = 'Programarea externa a fost adaugata. ID: #' . $appointmentId . '.';
						$values = [
							'customer_name' => '',
							'customer_email' => '',
							'customer_phone' => '',
							'service_id' => '',
							'date' => '',
							'time' => '',
							'source' => 'phone',
							'notes' => '',
						];
					}
				}
			}
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau specialist create external appointment failed: ' . $exception->getMessage());
			$errors[] = 'Programarea externa nu a putut fi creata.';
		} finally {
			if ($lockAcquired) {
				try {
					releaseBookingLock($pdo, $lockName);
				} catch (Throwable $releaseException) {
					error_log('Farmecul Tau specialist external booking lock release failed: ' . $releaseException->getMessage());
				}
			}
		}
	}
}

$services = [];

if ($currentSpecialist !== null) {
	$serviceCategory = getServiceCategoryForSpecialization($currentSpecialist['specialization'] ?? null);
	$serviceStatement = $pdo->prepare(
		'SELECT sv.id, sv.name, ss.duration_minutes, ss.price
		 FROM services sv
		 INNER JOIN specialist_services ss ON ss.service_id = sv.id
		 WHERE ss.specialist_id = :specialist_id
			AND sv.active = 1
			AND sv.category = :category
			AND ss.active = 1
			AND ss.price IS NOT NULL
			AND ss.duration_minutes IS NOT NULL
			AND ss.duration_minutes BETWEEN 5 AND 480
		 ORDER BY sv.name ASC'
	);
	$serviceStatement->execute([
		'specialist_id' => (int) $currentSpecialist['id'],
		'category' => $serviceCategory,
	]);
	$services = $serviceStatement->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Programare externa | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260827-3">
</head>
<body>
	<?php renderAdminHeader('Programare externa', 'my-create-appointment.php', $csrfToken, $dashboardUser, $currentSpecialist); ?>

	<main class="admin-page">
		<section class="admin-panel" data-own-manual-booking data-specialist-id="<?php echo $currentSpecialist !== null ? (int) $currentSpecialist['id'] : 0; ?>">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">AGENDA MEA</p>
					<h2 class="admin-section-title">Programare externa</h2>
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
				<form class="admin-form admin-form-grid" method="post" action="my-create-appointment.php">
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
						<span>Sursa</span>
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
						<select name="service_id" data-own-service required>
							<option value="">Alege serviciul</option>
							<?php foreach ($services as $service): ?>
								<option value="<?php echo (int) $service['id']; ?>" <?php echo $values['service_id'] === (string) $service['id'] ? 'selected' : ''; ?>>
									<?php echo adminEscape((string) $service['name']); ?> (<?php echo (int) $service['duration_minutes']; ?> min, <?php echo adminEscape(number_format((float) $service['price'], 2, '.', '')); ?> lei)
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span>Data</span>
						<input type="date" name="date" data-own-date value="<?php echo adminEscape($values['date']); ?>" required>
					</label>
					<label>
						<span>Ora</span>
						<select name="time" data-own-time data-selected-value="<?php echo adminEscape($values['time']); ?>" required disabled>
							<option value="">Alege serviciul si data</option>
						</select>
					</label>
					<label class="admin-form-wide">
						<span>Observatii</span>
						<textarea name="notes" maxlength="1000" rows="4"><?php echo adminEscape($values['notes']); ?></textarea>
					</label>
					<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
					<p class="admin-form-message admin-form-wide" data-own-booking-status></p>
					<button class="admin-button admin-form-wide" type="submit">Adauga programarea</button>
				</form>
			<?php endif; ?>
		</section>
	</main>

	<script src="../js/my-appointment-form.js?v=20260827-1"></script>
</body>
</html>
