<?php
declare(strict_types=1);

function generatePromoCode(PDO $pdo): string
{
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	$alphabetLength = strlen($alphabet);

	for ($attempt = 0; $attempt < 10; $attempt++) {
		$bytes = random_bytes(8);
		$suffix = '';

		for ($index = 0; $index < 8; $index++) {
			$suffix .= $alphabet[ord($bytes[$index]) % $alphabetLength];
		}

		$code = 'FT-' . $suffix;

		$statement = $pdo->prepare(
			'SELECT id
			 FROM promo_codes
			 WHERE code = :code
			 LIMIT 1'
		);
		$statement->execute(['code' => $code]);

		if ($statement->fetch() === false) {
			return $code;
		}
	}

	throw new RuntimeException('Could not generate a unique promo code.');
}
