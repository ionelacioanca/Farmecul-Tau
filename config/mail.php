<?php
declare(strict_types=1);

return [
	'host' => getenv('MAIL_HOST') ?: '',
	'port' => (int) (getenv('MAIL_PORT') ?: 587),
	'username' => getenv('MAIL_USERNAME') ?: '',
	'password' => getenv('MAIL_PASSWORD') ?: '',
	'encryption' => strtolower((string) (getenv('MAIL_ENCRYPTION') ?: 'tls')),
	'from_address' => getenv('MAIL_FROM_ADDRESS') ?: '',
	'from_name' => getenv('MAIL_FROM_NAME') ?: 'Farmecul Tău',
];
