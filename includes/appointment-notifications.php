<?php
declare(strict_types=1);

require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/mailer.php';

function farmeculEmailEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function farmeculFormatEmailDate(string $dateTime): string
{
	try {
		$date = new DateTimeImmutable($dateTime, getSalonTimezone());
	} catch (Exception $exception) {
		return $dateTime;
	}

	$months = [
		1 => 'ianuarie',
		2 => 'februarie',
		3 => 'martie',
		4 => 'aprilie',
		5 => 'mai',
		6 => 'iunie',
		7 => 'iulie',
		8 => 'august',
		9 => 'septembrie',
		10 => 'octombrie',
		11 => 'noiembrie',
		12 => 'decembrie',
	];

	return (int) $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
}

function farmeculFormatEmailTime(string $dateTime): string
{
	try {
		$date = new DateTimeImmutable($dateTime, getSalonTimezone());
	} catch (Exception $exception) {
		return $dateTime;
	}

	return $date->format('H:i');
}

function farmeculFormatEmailPrice(?string $price): string
{
	if ($price === null || $price === '') {
		return '-';
	}

	return number_format((float) $price, 2, ',', '.') . ' lei';
}

function farmeculLoadAppointmentNotification(PDO $pdo, int $appointmentId): ?array
{
	$statement = $pdo->prepare(
		'SELECT
			a.id,
			a.customer_name,
			a.customer_email,
			a.booking_type,
			a.start_datetime,
			a.price_at_booking,
			a.duration_minutes_at_booking,
			a.status,
			a.admin_note,
			sv.name AS service_name,
			ofr.title AS offer_title,
			sp.name AS specialist_name
		 FROM appointments a
		 LEFT JOIN services sv ON sv.id = a.service_id
		 LEFT JOIN offers ofr ON ofr.id = a.offer_id
		 INNER JOIN specialists sp ON sp.id = a.specialist_id
		 WHERE a.id = :id
		 LIMIT 1'
	);
	$statement->execute(['id' => $appointmentId]);
	$appointment = $statement->fetch();

	return $appointment !== false ? $appointment : null;
}

function farmeculAppointmentEmailDetails(array $appointment): array
{
	$isOffer = (string) ($appointment['booking_type'] ?? 'service') === 'offer';
	$title = $isOffer ? (string) ($appointment['offer_title'] ?? '') : (string) ($appointment['service_name'] ?? '');

	return [
		$isOffer ? 'Ofertă' : 'Serviciu' => $title !== '' ? $title : '-',
		'Specialist' => (string) ($appointment['specialist_name'] ?? '-'),
		'Data' => farmeculFormatEmailDate((string) $appointment['start_datetime']),
		'Ora' => farmeculFormatEmailTime((string) $appointment['start_datetime']),
		'Durată' => (int) $appointment['duration_minutes_at_booking'] . ' min',
		$isOffer ? 'Preț ofertă' : 'Preț' => farmeculFormatEmailPrice($appointment['price_at_booking'] !== null ? (string) $appointment['price_at_booking'] : null),
	];
}

function farmeculBuildAppointmentHtmlEmail(string $heading, array $paragraphs, array $details, ?string $actionUrl = null): string
{
	$detailRows = '';

	foreach ($details as $label => $value) {
		$detailRows .= '<tr>'
			. '<td style="padding:10px 0;color:#195a48;font-weight:700;width:36%;vertical-align:top;">' . farmeculEmailEscape((string) $label) . '</td>'
			. '<td style="padding:10px 0;color:#111111;vertical-align:top;">' . farmeculEmailEscape((string) $value) . '</td>'
			. '</tr>';
	}

	$paragraphHtml = '';

	foreach ($paragraphs as $paragraph) {
		if ($paragraph === '') {
			continue;
		}

		$paragraphHtml .= '<p style="margin:0 0 14px;color:#333333;font-size:16px;line-height:1.55;">' . farmeculEmailEscape($paragraph) . '</p>';
	}

	$buttonHtml = '';

	if ($actionUrl !== null && filter_var($actionUrl, FILTER_VALIDATE_URL)) {
		$buttonHtml = '<p style="margin:24px 0 0;">'
			. '<a href="' . farmeculEmailEscape($actionUrl) . '" style="display:inline-block;padding:12px 18px;background:#195a48;color:#fffff0;text-decoration:none;border-radius:6px;font-weight:700;">Alege alt interval</a>'
			. '</p>';
	}

	return '<!doctype html><html lang="ro"><head><meta charset="UTF-8"><title>'
		. farmeculEmailEscape($heading)
		. '</title></head><body style="margin:0;padding:0;background:#fffff0;font-family:Georgia,Times,serif;color:#111111;">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#fffff0;padding:28px 14px;"><tr><td align="center">'
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #e4d2a8;border-radius:8px;overflow:hidden;">'
		. '<tr><td style="padding:28px 30px;background:#195a48;color:#fffff0;">'
		. '<p style="margin:0 0 8px;color:#c6a15b;font-size:13px;letter-spacing:2px;text-transform:uppercase;font-weight:700;">Farmecul Tău</p>'
		. '<h1 style="margin:0;font-size:30px;line-height:1.12;font-weight:600;">' . farmeculEmailEscape($heading) . '</h1>'
		. '</td></tr><tr><td style="padding:28px 30px;">'
		. $paragraphHtml
		. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:18px;border-top:1px solid #eadfbe;border-bottom:1px solid #eadfbe;">'
		. $detailRows
		. '</table>'
		. $buttonHtml
		. '<p style="margin:24px 0 0;color:#6b5a44;font-size:14px;line-height:1.5;">Acesta este un email tranzacțional trimis pentru programarea ta la Farmecul Tău.</p>'
		. '</td></tr></table></td></tr></table></body></html>';
}

function farmeculBuildAppointmentTextEmail(string $heading, array $paragraphs, array $details, ?string $actionUrl = null): string
{
	$lines = ['Farmecul Tău', $heading, ''];

	foreach ($paragraphs as $paragraph) {
		if ($paragraph !== '') {
			$lines[] = $paragraph;
			$lines[] = '';
		}
	}

	foreach ($details as $label => $value) {
		$lines[] = $label . ': ' . $value;
	}

	if ($actionUrl !== null && filter_var($actionUrl, FILTER_VALIDATE_URL)) {
		$lines[] = '';
		$lines[] = 'Alege alt interval: ' . $actionUrl;
	}

	return implode("\n", $lines);
}

function farmeculSendAppointmentNotification(PDO $pdo, int $appointmentId, string $type): bool
{
	$appointment = farmeculLoadAppointmentNotification($pdo, $appointmentId);

	if ($appointment === null) {
		error_log('Farmecul Tau appointment mail skipped: appointment not found.');
		return false;
	}

	$email = (string) ($appointment['customer_email'] ?? '');
	$name = (string) ($appointment['customer_name'] ?? '');

	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		error_log('Farmecul Tau appointment mail skipped: invalid appointment customer_email.');
		return false;
	}

	$details = farmeculAppointmentEmailDetails($appointment);
	$subject = '';
	$heading = '';
	$paragraphs = [];
	$actionUrl = null;

	if ($type === 'pending') {
		$subject = 'Am primit solicitarea ta de programare | Farmecul Tău';
		$heading = 'Am primit solicitarea ta de programare';
		$paragraphs = [
			'Bună, ' . $name . ',',
			'Am primit solicitarea ta de programare.',
			'Programarea este momentan în așteptarea confirmării salonului.',
			'Solicitarea nu este încă confirmată.',
		];
	} elseif ($type === 'approved') {
		$subject = 'Programarea ta a fost confirmată | Farmecul Tău';
		$heading = 'Programarea ta a fost confirmată';
		$paragraphs = [
			'Bună, ' . $name . ',',
			'Programarea ta a fost confirmată.',
		];
	} elseif ($type === 'rejected') {
		$subject = 'Actualizare privind programarea ta | Farmecul Tău';
		$heading = 'Actualizare privind programarea ta';
		$paragraphs = [
			'Bună, ' . $name . ',',
			'Din păcate, solicitarea ta de programare nu a putut fi confirmată.',
		];

		$adminNote = trim((string) ($appointment['admin_note'] ?? ''));

		if ($adminNote !== '') {
			$paragraphs[] = 'Mesaj din partea salonului: ' . $adminNote;
		}

		farmeculLoadEnvFile();
		$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');

		if ($appUrl !== '') {
			$actionUrl = $appUrl . '/pages/programari.php';
		}
	} else {
		error_log('Farmecul Tau appointment mail skipped: unknown notification type.');
		return false;
	}

	return sendMail(
		$email,
		$name,
		$subject,
		farmeculBuildAppointmentHtmlEmail($heading, $paragraphs, $details, $actionUrl),
		farmeculBuildAppointmentTextEmail($heading, $paragraphs, $details, $actionUrl)
	);
}

function sendAppointmentPendingEmail(PDO $pdo, int $appointmentId): bool
{
	return farmeculSendAppointmentNotification($pdo, $appointmentId, 'pending');
}

function sendAppointmentApprovedEmail(PDO $pdo, int $appointmentId): bool
{
	return farmeculSendAppointmentNotification($pdo, $appointmentId, 'approved');
}

function sendAppointmentRejectedEmail(PDO $pdo, int $appointmentId): bool
{
	return farmeculSendAppointmentNotification($pdo, $appointmentId, 'rejected');
}
