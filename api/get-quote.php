<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
	http_response_code(405);
	echo json_encode([
		'success' => false,
		'error' => 'Method not allowed.',
	], JSON_UNESCAPED_UNICODE);
	exit;
}

try {
	require_once __DIR__ . '/../includes/db.php';

	$statement = $pdo->query(
		'SELECT q.id, q.quote_text, q.author, q.wrong_author_1, q.wrong_author_2
		 FROM beauty_quotes q
		 INNER JOIN promo_rewards r ON r.id = q.reward_id AND r.active = 1
		 WHERE q.active = 1
		 ORDER BY RAND()
		 LIMIT 1'
	);
	$quote = $statement->fetch();

	if ($quote === false) {
		http_response_code(404);
		echo json_encode([
			'success' => false,
			'error' => 'No active quote is available.',
		], JSON_UNESCAPED_UNICODE);
		exit;
	}

	$options = [
		$quote['author'],
		$quote['wrong_author_1'],
		$quote['wrong_author_2'],
	];
	shuffle($options);

	echo json_encode([
		'success' => true,
		'quote' => [
			'id' => (int) $quote['id'],
			'text' => $quote['quote_text'],
			'options' => $options,
		],
	], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
	error_log('Farmecul Tau quote API failed: ' . $exception->getMessage());
	http_response_code(500);
	echo json_encode([
		'success' => false,
		'error' => 'The quote could not be loaded.',
	], JSON_UNESCAPED_UNICODE);
}
