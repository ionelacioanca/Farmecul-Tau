<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/api-response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/promo-codes.php';
require_once __DIR__ . '/../includes/promo-eligibility.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	sendJsonResponse(405, [
		'success' => false,
		'error' => 'Method not allowed.',
	]);
}

startAppSession();

try {
	require_once __DIR__ . '/../includes/db.php';

	$userId = getCurrentUserId();

	if ($userId === null) {
		sendJsonResponse(401, [
			'success' => false,
			'requires_auth' => true,
			'message' => 'Intră în cont sau creează unul pentru a revendica surpriza.',
		]);
	}

	$pendingRewardId = $_SESSION['pending_reward_id'] ?? null;
	$pendingQuoteId = $_SESSION['pending_quote_id'] ?? null;

	if (!is_int($pendingRewardId) || !is_int($pendingQuoteId)) {
		sendJsonResponse(409, [
			'success' => false,
			'requires_pending_reward' => true,
			'message' => 'Alege răspunsul corect înainte de a revendica surpriza.',
		]);
	}

	$pdo->beginTransaction();

	$userLock = $pdo->prepare(
		'SELECT id
		 FROM users
		 WHERE id = :user_id
		 LIMIT 1
		 FOR UPDATE'
	);
	$userLock->execute(['user_id' => $userId]);

	if ($userLock->fetch() === false) {
		$pdo->rollBack();
		clearAuthenticatedUser();
		sendJsonResponse(401, [
			'success' => false,
			'requires_auth' => true,
			'message' => 'Te rugăm să intri din nou în cont.',
		]);
	}

	expirePromoCodes($pdo, $userId);

	$activeStatement = $pdo->prepare(
		"SELECT
			pc.id,
			pc.code,
			pc.status,
			pc.expires_at,
			r.id AS reward_id,
			r.name AS reward_name,
			r.description AS reward_description,
			r.validity_days AS reward_validity_days
		 FROM promo_codes pc
		 INNER JOIN promo_rewards r ON r.id = pc.reward_id
		 WHERE pc.user_id = :user_id
			AND pc.status = 'active'
			AND pc.expires_at > NOW()
		 ORDER BY pc.created_at DESC
		 LIMIT 1
		 FOR UPDATE"
	);
	$activeStatement->execute(['user_id' => $userId]);
	$activePromo = $activeStatement->fetch();

	if ($activePromo !== false) {
		$pdo->commit();
		sendJsonResponse(200, [
			'success' => true,
			'claimed' => false,
			'message' => 'Mai ai deja o surpriză activă în cont.',
			'promo' => formatPromoCodeResponse($activePromo),
		]);
	}

	$rewardStatement = $pdo->prepare(
		'SELECT
			r.id AS reward_id,
			r.name AS reward_name,
			r.description AS reward_description,
			r.validity_days AS reward_validity_days
		 FROM beauty_quotes q
		 INNER JOIN promo_rewards r ON r.id = q.reward_id AND r.active = 1
		 WHERE q.id = :quote_id
			AND q.active = 1
			AND r.id = :reward_id
		 LIMIT 1
		 FOR UPDATE'
	);
	$rewardStatement->execute([
		'quote_id' => $pendingQuoteId,
		'reward_id' => $pendingRewardId,
	]);
	$reward = $rewardStatement->fetch();

	if ($reward === false) {
		$pdo->rollBack();
		unset($_SESSION['pending_reward_id'], $_SESSION['pending_quote_id']);
		sendJsonResponse(409, [
			'success' => false,
			'requires_pending_reward' => true,
			'message' => 'Surpriza câștigată nu mai este disponibilă. Te rugăm să încerci un alt citat.',
		]);
	}

	$validityDays = max(1, (int) $reward['reward_validity_days']);
	$expiresAt = (new DateTimeImmutable('now'))->modify('+' . $validityDays . ' days')->format('Y-m-d H:i:s');
	$insertedPromo = null;

	for ($attempt = 0; $attempt < 5; $attempt++) {
		$code = generatePromoCode($pdo);

		try {
			$insertStatement = $pdo->prepare(
				"INSERT INTO promo_codes (user_id, reward_id, code, status, expires_at)
				 VALUES (:user_id, :reward_id, :code, 'active', :expires_at)"
			);
			$insertStatement->execute([
				'user_id' => $userId,
				'reward_id' => $pendingRewardId,
				'code' => $code,
				'expires_at' => $expiresAt,
			]);

			$insertedPromo = [
				'id' => (int) $pdo->lastInsertId(),
				'code' => $code,
				'status' => 'active',
				'expires_at' => $expiresAt,
				'reward_id' => (int) $reward['reward_id'],
				'reward_name' => (string) $reward['reward_name'],
				'reward_description' => (string) $reward['reward_description'],
				'reward_validity_days' => (int) $reward['reward_validity_days'],
			];
			break;
		} catch (PDOException $exception) {
			if ($exception->getCode() !== '23000' || $attempt === 4) {
				throw $exception;
			}
		}
	}

	if ($insertedPromo === null) {
		throw new RuntimeException('Promo code insert failed.');
	}

	unset($_SESSION['pending_reward_id'], $_SESSION['pending_quote_id']);
	$pdo->commit();

	sendJsonResponse(201, [
		'success' => true,
		'claimed' => true,
		'message' => 'Surpriza este a ta.',
		'promo' => formatPromoCodeResponse($insertedPromo),
	]);
} catch (Throwable $exception) {
	if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
		$pdo->rollBack();
	}

	error_log('Farmecul Tau claim reward failed: ' . $exception->getMessage());
	sendJsonResponse(500, [
		'success' => false,
		'error' => 'Surpriza nu a putut fi revendicată.',
	]);
}
