<?php
declare(strict_types=1);

function sendJsonResponse(int $statusCode, array $payload): void
{
	http_response_code($statusCode);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}

function readJsonRequestBody(): array
{
	$rawBody = file_get_contents('php://input');
	$payload = json_decode($rawBody !== false ? $rawBody : '', true);

	if (!is_array($payload)) {
		sendJsonResponse(400, [
			'success' => false,
			'error' => 'Invalid JSON payload.',
		]);
	}

	return $payload;
}
