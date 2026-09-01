<?php
declare(strict_types=1);

return [
	'subject' => 'Solicitare nouă de programare | Farmecul Tău',
	'heading' => 'Solicitare nouă de programare',
	'paragraphs' => [
		'Bună, ' . (string) ($appointment['specialist_name'] ?? ''),
		'Ai primit o nouă solicitare de programare.',
		'Intră în dashboard pentru a aproba sau respinge solicitarea.',
	],
	'details' => $details,
	'action_url' => $dashboardUrl,
	'action_label' => 'Intră în dashboard',
];
