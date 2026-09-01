<?php
declare(strict_types=1);

$paragraphs = [
	'Bună, ' . (string) ($appointment['customer_name'] ?? ''),
	'Din păcate, solicitarea ta de programare nu a putut fi confirmată.',
];

$customerNote = trim((string) ($appointment['admin_note'] ?? ''));

if ($customerNote !== '') {
	$paragraphs[] = 'Mesaj din partea salonului: ' . $customerNote;
}

return [
	'subject' => 'Actualizare privind programarea ta | Farmecul Tău',
	'heading' => 'Actualizare privind programarea ta',
	'paragraphs' => $paragraphs,
	'details' => $details,
	'action_url' => $bookingUrl,
	'action_label' => 'Alege alt interval',
];
