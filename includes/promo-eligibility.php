<?php
declare(strict_types=1);

function getAuthenticatedPromoUserId(): ?int
{
	require_once __DIR__ . '/auth.php';

	return getCurrentUserId();
}

function userHasActivePromoReward(PDO $pdo, int $userId): bool
{
	return getActivePromoCodeForUser($pdo, $userId) !== null;
}

function expirePromoCodes(PDO $pdo, ?int $userId = null): void
{
	if ($userId === null) {
		$statement = $pdo->prepare(
			"UPDATE promo_codes
			 SET status = 'expired'
			 WHERE status = 'active' AND expires_at <= NOW()"
		);
		$statement->execute();
		return;
	}

	$statement = $pdo->prepare(
		"UPDATE promo_codes
		 SET status = 'expired'
		 WHERE user_id = :user_id
			AND status = 'active'
			AND expires_at <= NOW()"
	);
	$statement->execute(['user_id' => $userId]);
}

function getActivePromoCodeForUser(PDO $pdo, int $userId): ?array
{
	expirePromoCodes($pdo, $userId);

	$statement = $pdo->prepare(
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
		 LIMIT 1"
	);
	$statement->execute(['user_id' => $userId]);
	$promoCode = $statement->fetch();

	if ($promoCode === false) {
		return null;
	}

	return formatPromoCodeResponse($promoCode);
}

function canUserClaimPromoReward(PDO $pdo, ?int $userId): bool
{
	if ($userId === null) {
		return false;
	}

	return !userHasActivePromoReward($pdo, $userId);
}

function formatPromoCodeResponse(array $promoCode): array
{
	return [
		'id' => (int) $promoCode['id'],
		'code' => (string) $promoCode['code'],
		'status' => (string) $promoCode['status'],
		'expires_at' => (string) $promoCode['expires_at'],
		'reward' => [
			'id' => (int) $promoCode['reward_id'],
			'name' => (string) $promoCode['reward_name'],
			'description' => (string) $promoCode['reward_description'],
			'validity_days' => (int) $promoCode['reward_validity_days'],
		],
	];
}
