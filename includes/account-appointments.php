<?php
declare(strict_types=1);

require_once __DIR__ . '/booking.php';

function getAccountAppointment(PDO $pdo, int $appointmentId, int $customerUserId, bool $lock = false): ?array
{
	$statement = $pdo->prepare(
		'SELECT
			id,
			customer_user_id,
			booking_type,
			service_id,
			offer_id,
			specialist_id,
			start_datetime,
			end_datetime,
			duration_minutes_at_booking,
			price_at_booking,
			status
		 FROM appointments
		 WHERE id = :appointment_id
			AND customer_user_id = :customer_user_id
		 LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
	);
	$statement->execute([
		'appointment_id' => $appointmentId,
		'customer_user_id' => $customerUserId,
	]);
	$appointment = $statement->fetch();

	return $appointment !== false ? $appointment : null;
}

function isAccountAppointmentActive(array $appointment): bool
{
	$status = (string) ($appointment['status'] ?? '');

	if (!in_array($status, ['pending', 'approved'], true)) {
		return false;
	}

	try {
		$start = new DateTimeImmutable((string) $appointment['start_datetime'], getSalonTimezone());
	} catch (Exception $exception) {
		return false;
	}

	return $start > new DateTimeImmutable('now', getSalonTimezone());
}
