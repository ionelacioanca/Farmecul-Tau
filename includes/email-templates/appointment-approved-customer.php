<?php
declare(strict_types=1);

return [
	'subject' => 'Programarea ta a fost confirmată | Farmecul Tău',
	'heading' => 'Programarea ta a fost confirmată',
	'paragraphs' => [
		'Bună, ' . (string) ($appointment['customer_name'] ?? ''),
		'Programarea ta a fost confirmată.',
		'Te așteptăm cu drag la Farmecul Tău.',
	],
	'details' => $details,
	'action_url' => $accountUrl,
	'action_label' => 'Vezi programarea în contul tău',
];
