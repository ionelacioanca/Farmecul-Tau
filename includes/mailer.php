<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

function farmeculLoadEnvFile(): void
{
	static $loaded = false;

	if ($loaded) {
		return;
	}

	$loaded = true;
	$envPath = dirname(__DIR__) . '/.env';

	if (!is_file($envPath) || !is_readable($envPath)) {
		return;
	}

	foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
		$line = trim($line);

		if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
			continue;
		}

		[$name, $value] = array_map('trim', explode('=', $line, 2));

		if ($name === '' || getenv($name) !== false) {
			continue;
		}

		$value = trim($value, "\"'");
		putenv($name . '=' . $value);
		$_ENV[$name] = $value;
	}
}

function farmeculMailConfig(): array
{
	farmeculLoadEnvFile();

	$config = require dirname(__DIR__) . '/config/mail.php';

	if (!is_array($config)) {
		return [];
	}

	return $config;
}

function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool
{
	if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
		error_log('Farmecul Tau mail skipped: invalid recipient email.');
		return false;
	}

	$autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';

	if (!is_file($autoloadPath)) {
		error_log('Farmecul Tau mail skipped: Composer autoload not found. Run composer install.');
		return false;
	}

	require_once $autoloadPath;

	if (!class_exists(PHPMailer::class)) {
		error_log('Farmecul Tau mail skipped: PHPMailer is not installed.');
		return false;
	}

	$config = farmeculMailConfig();
	$host = trim((string) ($config['host'] ?? ''));
	$fromAddress = trim((string) ($config['from_address'] ?? ''));

	if ($host === '' || !filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
		error_log('Farmecul Tau mail skipped: SMTP host or sender address is not configured.');
		return false;
	}

	$mail = new PHPMailer(true);

	try {
		$mail->CharSet = PHPMailer::CHARSET_UTF8;
		$mail->isSMTP();
		$mail->Host = $host;
		$mail->Port = (int) ($config['port'] ?? 587);

		$username = (string) ($config['username'] ?? '');
		$password = (string) ($config['password'] ?? '');

		if ($username !== '') {
			$mail->SMTPAuth = true;
			$mail->Username = $username;
			$mail->Password = $password;
		}

		$encryption = strtolower((string) ($config['encryption'] ?? 'tls'));

		if ($encryption === 'tls') {
			$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
		} elseif (in_array($encryption, ['ssl', 'smtps'], true)) {
			$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		}

		$mail->setFrom($fromAddress, (string) ($config['from_name'] ?? 'Farmecul Tau'));
		$mail->addAddress($toEmail, $toName);
		$mail->Subject = $subject;
		$mail->isHTML(true);
		$mail->Body = $htmlBody;
		$mail->AltBody = $textBody;

		return $mail->send();
	} catch (PHPMailerException $exception) {
		error_log('Farmecul Tau mail failed: ' . $exception->getMessage());
		return false;
	} catch (Throwable $exception) {
		error_log('Farmecul Tau mail failed: ' . $exception->getMessage());
		return false;
	}
}
