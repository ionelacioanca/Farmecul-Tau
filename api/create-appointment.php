<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/offer-helpers.php';

setSalonTimezone();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

$payload = readJsonRequestBody();

$bookingType = isset($payload['booking_type']) ? strtolower(trim((string) $payload['booking_type'])) : 'service';
$serviceId = filter_var($payload['service_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$offerId = filter_var($payload['offer_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$specialistId = filter_var($payload['specialist_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$dateInput = isset($payload['date']) ? trim((string) $payload['date']) : '';
$timeInput = isset($payload['time']) ? trim((string) $payload['time']) : '';
$customerName = isset($payload['customer_name']) ? trim((string) $payload['customer_name']) : '';
$customerEmail = isset($payload['customer_email']) ? strtolower(trim((string) $payload['customer_email'])) : '';
$customerPhone = isset($payload['customer_phone']) ? trim((string) $payload['customer_phone']) : '';
$notes = isset($payload['notes']) ? trim((string) $payload['notes']) : '';

$date = $dateInput !== '' ? parseBookingDate($dateInput) : null;
$candidateStart = $date !== null ? parseBookingTime($date, $timeInput) : null;
$errors = [];

if (!in_array($bookingType, ['service', 'offer'], true)) {
	$errors['booking_type'] = 'Tipul programarii nu este valid.';
}

if ($bookingType === 'service' && $serviceId === false) {
	$errors['service_id'] = 'Te rugăm să alegi un serviciu valid.';
}

if ($bookingType === 'offer' && $offerId === false) {
	$errors['offer_id'] = 'Te rugam sa alegi o oferta valida.';
}

if ($specialistId === false) {
	$errors['specialist_id'] = 'Te rugăm să alegi un specialist valid.';
}

if ($date === null) {
	$errors['date'] = 'Te rugăm să alegi o dată validă.';
} elseif ($date < new DateTimeImmutable('today', getSalonTimezone())) {
	$errors['date'] = 'Data nu poate fi în trecut.';
}

if ($candidateStart === null) {
	$errors['time'] = 'Te rugăm să alegi o oră validă.';
} elseif (!in_array((int) $candidateStart->format('i'), [0, 30], true)) {
	$errors['time'] = 'Te rugăm să alegi o oră disponibilă din listă.';
}

if ($customerName === '' || strlen($customerName) > 150) {
	$errors['customer_name'] = 'Te rugăm să introduci numele.';
}

if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || strlen($customerEmail) > 255) {
	$errors['customer_email'] = 'Te rugăm să introduci o adresă de email validă.';
}

if ($customerPhone === '' || strlen($customerPhone) > 50 || !preg_match('/^[0-9+\s().-]{6,50}$/', $customerPhone)) {
	$errors['customer_phone'] = 'Te rugăm să introduci un număr de telefon valid.';
}

if (strlen($notes) > 1000) {
	$errors['notes'] = 'Observațiile pot avea maximum 1000 de caractere.';
}

if ($errors !== []) {
	sendJsonResponse(422, [
		'success' => false,
		'errors' => $errors,
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';

	$currentUser = getCurrentUser($pdo);
	$customerUserId = $currentUser !== null ? (int) $currentUser['id'] : null;
	$lockName = getBookingLockName($specialistId, $date);
	$lockAcquired = false;

	$lockAcquired = acquireBookingLock($pdo, $lockName);

	if (!$lockAcquired) {
		sendJsonResponse(409, [
			'success' => false,
			'code' => 'slot_unavailable',
			'message' => 'Ora selectată nu mai este disponibilă. Te rugăm să alegi un alt interval.',
		]);
	}

	$pdo->beginTransaction();

	$bookingContext = $bookingType === 'offer'
		? getOfferBookingContext($pdo, $offerId, $specialistId, $date, true)
		: getBookingContext($pdo, $serviceId, $specialistId, true);

	if ($bookingContext === null) {
		$pdo->rollBack();
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Serviciul sau specialistul nu este disponibil.',
		]);
	}

	$durationMinutes = (int) $bookingContext['duration_minutes'];
	$priceAtBooking = (float) $bookingContext['price'];

	if ($durationMinutes <= 0) {
		$pdo->rollBack();
		sendJsonResponse(409, [
			'success' => false,
			'error' => 'Durata serviciului nu este configurată corect.',
		]);
	}

	$candidateEnd = $candidateStart->add(new DateInterval('PT' . $durationMinutes . 'M'));

	if (!isBookingSlotAvailable($pdo, $specialistId, $candidateStart, $candidateEnd, true)) {
		$pdo->rollBack();
		sendJsonResponse(409, [
			'success' => false,
			'code' => 'slot_unavailable',
			'message' => 'Ora selectată nu mai este disponibilă. Te rugăm să alegi un alt interval.',
		]);
	}

	$insertStatement = $pdo->prepare(
		"INSERT INTO appointments (
			customer_user_id,
			customer_name,
			customer_email,
			customer_phone,
			booking_type,
			service_id,
			offer_id,
			specialist_id,
			start_datetime,
			end_datetime,
			price_at_booking,
			duration_minutes_at_booking,
			status,
			source,
			notes
		) VALUES (
			:customer_user_id,
			:customer_name,
			:customer_email,
			:customer_phone,
			:booking_type,
			:service_id,
			:offer_id,
			:specialist_id,
			:start_datetime,
			:end_datetime,
			:price_at_booking,
			:duration_minutes_at_booking,
			'pending',
			'online',
			:notes
		)"
	);
	$insertStatement->execute([
		'customer_user_id' => $customerUserId,
		'customer_name' => $customerName,
		'customer_email' => $customerEmail,
		'customer_phone' => $customerPhone,
		'booking_type' => $bookingType,
		'service_id' => $bookingType === 'service' ? $serviceId : null,
		'offer_id' => $bookingType === 'offer' ? $offerId : null,
		'specialist_id' => $specialistId,
		'start_datetime' => $candidateStart->format('Y-m-d H:i:s'),
		'end_datetime' => $candidateEnd->format('Y-m-d H:i:s'),
		'price_at_booking' => number_format($priceAtBooking, 2, '.', ''),
		'duration_minutes_at_booking' => $durationMinutes,
		'notes' => $notes !== '' ? $notes : null,
	]);

	$appointmentId = (int) $pdo->lastInsertId();
	$pdo->commit();

	sendJsonResponse(201, [
		'success' => true,
		'appointment' => [
			'id' => $appointmentId,
			'status' => 'pending',
			'date' => $date->format('Y-m-d'),
			'time' => $candidateStart->format('H:i'),
			'price_at_booking' => $priceAtBooking,
			'duration_minutes_at_booking' => $durationMinutes,
		],
		'message' => 'Solicitarea ta de programare a fost trimisă.',
	]);
} catch (Throwable $exception) {
	if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
		$pdo->rollBack();
	}

	error_log('Farmecul Tau create appointment failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Solicitarea de programare nu a putut fi trimisă.',
	]);
} finally {
	if (isset($pdo, $lockAcquired, $lockName) && $pdo instanceof PDO && $lockAcquired) {
		try {
			releaseBookingLock($pdo, $lockName);
		} catch (Throwable $releaseException) {
			error_log('Farmecul Tau booking lock release failed: ' . $releaseException->getMessage());
		}
	}
}
