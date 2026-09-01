<?php
declare(strict_types=1);

$databaseConfig = [
	'host' => 'localhost',
	'database' => 'farmecul_tau',
	'username' => 'root',
	'password' => '',
	'charset' => 'utf8mb4',
];

$dsn = sprintf(
	'mysql:host=%s;dbname=%s;charset=%s',
	$databaseConfig['host'],
	$databaseConfig['database'],
	$databaseConfig['charset']
);

try {
	$pdo = new PDO($dsn, $databaseConfig['username'], $databaseConfig['password'], [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);
} catch (PDOException $exception) {
	error_log('Farmecul Tau database connection failed: ' . $exception->getMessage());
	throw new RuntimeException('Database connection failed.', 0, $exception);
}
