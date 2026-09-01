<?php
declare(strict_types=1);

require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/mailer.php';

function farmeculEmailEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function farmeculAppUrl(?string $path = null): ?string
{
	farmeculLoadEnvFile();
	$appUrl = rtrim((string) (getenv('APP_URL') ?: ''), '/');

	if ($appUrl === '') {
		return null;
	}

	if ($path === null || $path === '') {
		return $appUrl;
	}

	return $appUrl . '/' . ltrim($path, '/');
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
			a.customer_user_id,
			a.customer_name,
			a.customer_email,
			a.booking_type,
			a.start_datetime,
			a.price_at_booking,
			a.duration_minutes_at_booking,
			a.status,
			a.notes,
			a.admin_note,
			sv.name AS service_name,
			ofr.title AS offer_title,
			sp.name AS specialist_name,
			sp.email AS specialist_email,
			u.email AS specialist_user_email
		 FROM appointments a
		 LEFT JOIN services sv ON sv.id = a.service_id
		 LEFT JOIN offers ofr ON ofr.id = a.offer_id
		 INNER JOIN specialists sp ON sp.id = a.specialist_id
		 LEFT JOIN users u ON u.id = sp.user_id
		 WHERE a.id = :id
		 LIMIT 1'
	);
	$statement->execute(['id' => $appointmentId]);
	$appointment = $statement->fetch();

	return $appointment !== false ? $appointment : null;
}

function farmeculAppointmentServiceOrOfferLabel(array $appointment): string
{
	return (string) ($appointment['booking_type'] ?? 'service') === 'offer'
		? 'Ofertă'
		: 'Serviciu';
}

function farmeculAppointmentServiceOrOfferName(array $appointment): string
{
	$isOffer = (string) ($appointment['booking_type'] ?? 'service') === 'offer';
	$name = $isOffer
		? (string) ($appointment['offer_title'] ?? '')
		: (string) ($appointment['service_name'] ?? '');

	return $name !== '' ? $name : '-';
}

function farmeculAppointmentEmailDetails(array $appointment, bool $includeCustomer = false): array
{
	$typeLabel = farmeculAppointmentServiceOrOfferLabel($appointment);
	$details = [];

	if ($includeCustomer) {
		$details['Clientă'] = (string) ($appointment['customer_name'] ?? '-');
		$details['Tip programare'] = $typeLabel;
	}

	$details[$typeLabel] = farmeculAppointmentServiceOrOfferName($appointment);
	$details['Specialist'] = (string) ($appointment['specialist_name'] ?? '-');
	$details['Data'] = farmeculFormatEmailDate((string) $appointment['start_datetime']);
	$details['Ora'] = farmeculFormatEmailTime((string) $appointment['start_datetime']);
	$details['Durată'] = (int) $appointment['duration_minutes_at_booking'] . ' min';
	$details[$typeLabel === 'Ofertă' ? 'Preț ofertă' : 'Preț'] = farmeculFormatEmailPrice(
		$appointment['price_at_booking'] !== null ? (string) $appointment['price_at_booking'] : null
	);

	return $details;
}

function farmeculBuildAppointmentHtmlEmail(
	string $heading,
	array $paragraphs,
	array $details,
	?string $actionUrl = null,
	string $actionLabel = 'Deschide'
): string {
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
			. '<a href="' . farmeculEmailEscape($actionUrl) . '" style="display:inline-block;padding:12px 18px;background:#195a48;color:#fffff0;text-decoration:none;border-radius:6px;font-weight:700;">' . farmeculEmailEscape($actionLabel) . '</a>'
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
		. '<p style="margin:24px 0 0;color:#6b5a44;font-size:14px;line-height:1.5;">Acesta este un email tranzacțional trimis pentru programarea de la Farmecul Tău.</p>'
		. '</td></tr></table></td></tr></table></body></html>';
}

function farmeculBuildAppointmentTextEmail(
	string $heading,
	array $paragraphs,
	array $details,
	?string $actionUrl = null,
	string $actionLabel = 'Deschide'
): string {
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
		$lines[] = $actionLabel . ': ' . $actionUrl;
	}

	return implode("\n", $lines);
}

function farmeculRenderAppointmentEmailTemplate(string $templateName, array $variables): ?array
{
	$templatePath = __DIR__ . '/email-templates/' . $templateName . '.php';

	if (!is_file($templatePath)) {
		error_log('Farmecul Tau appointment mail skipped: template not found: ' . $templateName);
		return null;
	}

	$template = (static function (string $templatePath, array $variables): mixed {
		extract($variables, EXTR_SKIP);
		return require $templatePath;
	})($templatePath, $variables);

	if (!is_array($template)) {
		error_log('Farmecul Tau appointment mail skipped: invalid template: ' . $templateName);
		return null;
	}

	$subject = (string) ($template['subject'] ?? '');
	$heading = (string) ($template['heading'] ?? '');
	$paragraphs = $template['paragraphs'] ?? [];
	$details = $template['details'] ?? [];
	$actionUrl = isset($template['action_url']) ? (string) $template['action_url'] : null;
	$actionLabel = (string) ($template['action_label'] ?? 'Deschide');

	if ($subject === '' || $heading === '' || !is_array($paragraphs) || !is_array($details)) {
		error_log('Farmecul Tau appointment mail skipped: incomplete template: ' . $templateName);
		return null;
	}

	return [
		'subject' => $subject,
		'html' => farmeculBuildAppointmentHtmlEmail($heading, $paragraphs, $details, $actionUrl, $actionLabel),
		'text' => farmeculBuildAppointmentTextEmail($heading, $paragraphs, $details, $actionUrl, $actionLabel),
	];
}

function farmeculSendAppointmentEmail(
	PDO $pdo,
	int $appointmentId,
	string $templateName,
	string $toEmail,
	string $toName,
	array $variables
): bool {
	if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
		error_log('Farmecul Tau appointment mail skipped: invalid recipient email. Appointment ID: ' . $appointmentId);
		return false;
	}

	$message = farmeculRenderAppointmentEmailTemplate($templateName, $variables);

	if ($message === null) {
		return false;
	}

	return sendMail($toEmail, $toName, $message['subject'], $message['html'], $message['text']);
}

function sendAppointmentRequestSpecialistEmail(PDO $pdo, int $appointmentId): bool
{
	$appointment = farmeculLoadAppointmentNotification($pdo, $appointmentId);

	if ($appointment === null) {
		error_log('Farmecul Tau specialist request mail skipped: appointment not found.');
		return false;
	}

	$specialistEmail = trim((string) ($appointment['specialist_user_email'] ?? ''));

	if ($specialistEmail === '') {
		$specialistEmail = trim((string) ($appointment['specialist_email'] ?? ''));
	}

	if (!filter_var($specialistEmail, FILTER_VALIDATE_EMAIL)) {
		error_log('Farmecul Tau specialist request mail skipped: specialist has no usable email. Appointment ID: ' . $appointmentId);
		return false;
	}

	$details = farmeculAppointmentEmailDetails($appointment, true);
	$customerNote = trim((string) ($appointment['notes'] ?? ''));

	if ($customerNote !== '') {
		$details['Observații clientă'] = $customerNote;
	}

	return farmeculSendAppointmentEmail(
		$pdo,
		$appointmentId,
		'appointment-request-specialist',
		$specialistEmail,
		(string) ($appointment['specialist_name'] ?? ''),
		[
			'appointment' => $appointment,
			'details' => $details,
			'dashboardUrl' => farmeculAppUrl('admin/my-appointments.php'),
		]
	);
}

function farmeculSendCustomerAppointmentNotification(PDO $pdo, int $appointmentId, string $templateName): bool
{
	$appointment = farmeculLoadAppointmentNotification($pdo, $appointmentId);

	if ($appointment === null) {
		error_log('Farmecul Tau customer appointment mail skipped: appointment not found.');
		return false;
	}

	$email = trim((string) ($appointment['customer_email'] ?? ''));
	$name = (string) ($appointment['customer_name'] ?? '');

	if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
		error_log('Farmecul Tau customer appointment mail skipped: invalid customer_email. Appointment ID: ' . $appointmentId);
		return false;
	}

	return farmeculSendAppointmentEmail(
		$pdo,
		$appointmentId,
		$templateName,
		$email,
		$name,
		[
			'appointment' => $appointment,
			'details' => farmeculAppointmentEmailDetails($appointment),
			'accountUrl' => $appointment['customer_user_id'] !== null ? farmeculAppUrl('pages/contul-meu.php') : null,
			'bookingUrl' => farmeculAppUrl('pages/programari.php'),
		]
	);
}

function sendAppointmentApprovedEmail(PDO $pdo, int $appointmentId): bool
{
	return farmeculSendCustomerAppointmentNotification($pdo, $appointmentId, 'appointment-approved-customer');
}

function sendAppointmentRejectedEmail(PDO $pdo, int $appointmentId): bool
{
	return farmeculSendCustomerAppointmentNotification($pdo, $appointmentId, 'appointment-rejected-customer');
}

function sendAppointmentPendingEmail(PDO $pdo, int $appointmentId): bool
{
	return sendAppointmentRequestSpecialistEmail($pdo, $appointmentId);
}
