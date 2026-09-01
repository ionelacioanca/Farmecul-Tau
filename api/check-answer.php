<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/auth.php';

startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

$payload = readJsonRequestBody();

$quoteId = filter_var($payload['quote_id'] ?? null, FILTER_VALIDATE_INT, [
	'options' => ['min_range' => 1],
]);
$selectedAuthorInput = $payload['selected_author'] ?? null;
$selectedAuthor = is_string($selectedAuthorInput) ? trim($selectedAuthorInput) : '';

if ($quoteId === false || $selectedAuthor === '') {
	sendJsonResponse(422, [
		'success' => false,
		'error' => 'quote_id and selected_author are required.',
	]);
}

try {
	require_once __DIR__ . '/../includes/db.php';

	$statement = $pdo->prepare(
		'SELECT
			q.author,
			r.id AS reward_id,
			r.name AS reward_name,
			r.description AS reward_description,
			r.validity_days AS reward_validity_days
		 FROM beauty_quotes q
		 LEFT JOIN promo_rewards r ON r.id = q.reward_id AND r.active = 1
		 WHERE q.id = :quote_id AND q.active = 1
		 LIMIT 1'
	);
	$statement->execute(['quote_id' => $quoteId]);
	$quote = $statement->fetch();

	if ($quote === false) {
		sendJsonResponse(404, [
			'success' => false,
			'error' => 'Quote not found.',
		]);
	}

	if (!hash_equals(trim((string) $quote['author']), $selectedAuthor)) {
		unset($_SESSION['pending_reward_id'], $_SESSION['pending_quote_id']);

		sendJsonResponse(200, [
			'success' => true,
			'correct' => false,
			'message' => 'Nu de data aceasta. Încearcă un alt citat.',
		]);
	}

	if ($quote['reward_id'] === null) {
		sendJsonResponse(409, [
			'success' => false,
			'error' => 'Reward is not available for this quote.',
		]);
	}

	$_SESSION['pending_reward_id'] = (int) $quote['reward_id'];
	$_SESSION['pending_quote_id'] = $quoteId;

	sendJsonResponse(200, [
		'success' => true,
		'correct' => true,
		'reward' => [
			'id' => (int) $quote['reward_id'],
			'name' => (string) $quote['reward_name'],
			'description' => (string) $quote['reward_description'],
			'validity_days' => (int) $quote['reward_validity_days'],
		],
	]);
} catch (Throwable $exception) {
	error_log('Farmecul Tau answer API failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'The answer could not be checked.',
	]);
}
