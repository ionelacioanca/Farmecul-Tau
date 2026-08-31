<?php
declare(strict_types=1);

require_once __DIR__ . '/booking.php';

function buildOfferSlugBase(string $title): string
{
	$normalized = trim($title);

	if (function_exists('iconv')) {
		$converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
		if (is_string($converted)) {
			$normalized = $converted;
		}
	}

	$slug = strtolower($normalized);
	$slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
	$slug = trim($slug, '-');

	return $slug !== '' ? $slug : 'oferta';
}

function buildUniqueOfferSlug(PDO $pdo, string $title, ?int $excludeOfferId = null): string
{
	$base = substr(buildOfferSlugBase($title), 0, 160);
	$slug = $base;
	$suffix = 2;

	while (true) {
		$sql = 'SELECT id FROM offers WHERE slug = :slug';
		$params = ['slug' => $slug];

		if ($excludeOfferId !== null) {
			$sql .= ' AND id <> :exclude_offer_id';
			$params['exclude_offer_id'] = $excludeOfferId;
		}

		$sql .= ' LIMIT 1';
		$statement = $pdo->prepare($sql);
		$statement->execute($params);

		if ($statement->fetch() === false) {
			return $slug;
		}

		$tail = '-' . $suffix;
		$slug = substr($base, 0, 180 - strlen($tail)) . $tail;
		$suffix++;
	}
}

function getOfferById(PDO $pdo, int $offerId, bool $lock = false): ?array
{
	$statement = $pdo->prepare(
		'SELECT id, title, slug, description, image_path, price, duration_minutes, start_date, end_date, active
		 FROM offers
		 WHERE id = :offer_id
		 LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
	);
	$statement->execute(['offer_id' => $offerId]);
	$offer = $statement->fetch();

	return $offer !== false ? $offer : null;
}

function isOfferValidForDate(array $offer, DateTimeImmutable $date): bool
{
	if ((int) $offer['active'] !== 1) {
		return false;
	}

	$dateValue = $date->format('Y-m-d');

	return $dateValue >= (string) $offer['start_date']
		&& $dateValue <= (string) $offer['end_date'];
}

function getOfferServiceIds(PDO $pdo, int $offerId): array
{
	$statement = $pdo->prepare(
		'SELECT service_id
		 FROM offer_services
		 WHERE offer_id = :offer_id
		 ORDER BY service_id ASC'
	);
	$statement->execute(['offer_id' => $offerId]);

	return array_map('intval', array_column($statement->fetchAll(), 'service_id'));
}

function normalizeOfferServiceIds(array $serviceIds): array
{
	$normalized = [];

	foreach ($serviceIds as $serviceId) {
		$id = filter_var($serviceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if ($id !== false) {
			$normalized[] = (int) $id;
		}
	}

	return array_values(array_unique($normalized));
}

function getEligibleOfferSpecialists(PDO $pdo, array $serviceIds): array
{
	$serviceIds = normalizeOfferServiceIds($serviceIds);

	if ($serviceIds === []) {
		return [];
	}

	$placeholders = implode(', ', array_fill(0, count($serviceIds), '?'));

	$statement = $pdo->prepare(
		"SELECT sp.id, sp.name
		 FROM specialists sp
		 INNER JOIN specialist_services ss ON ss.specialist_id = sp.id
		 INNER JOIN services sv ON sv.id = ss.service_id
		 WHERE ss.service_id IN ($placeholders)
			AND sv.active = 1
			AND sp.active = 1
			AND ss.active = 1
		 GROUP BY sp.id, sp.name
		 HAVING COUNT(DISTINCT ss.service_id) = ?
		 ORDER BY sp.name ASC"
	);
	$statement->execute(array_merge($serviceIds, [count($serviceIds)]));

	return array_map(
		static fn (array $specialist): array => [
			'id' => (int) $specialist['id'],
			'name' => (string) $specialist['name'],
		],
		$statement->fetchAll()
	);
}

function areOfferServicesCompatibleWithSpecialist(PDO $pdo, array $serviceIds, int $specialistId): bool
{
	foreach (getEligibleOfferSpecialists($pdo, $serviceIds) as $specialist) {
		if ((int) $specialist['id'] === $specialistId) {
			return true;
		}
	}

	return false;
}

function getOfferBookingContext(
	PDO $pdo,
	int $offerId,
	int $specialistId,
	DateTimeImmutable $date,
	bool $lock = false
): ?array {
	$offer = getOfferById($pdo, $offerId, $lock);

	if ($offer === null || !isOfferValidForDate($offer, $date)) {
		return null;
	}

	$statement = $pdo->prepare(
		'SELECT sp.id, sp.name, sp.specialization
		 FROM offer_specialists os
		 INNER JOIN specialists sp ON sp.id = os.specialist_id
		 WHERE os.offer_id = :offer_id
			AND os.specialist_id = :specialist_id
			AND sp.active = 1
		 LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
	);
	$statement->execute([
		'offer_id' => $offerId,
		'specialist_id' => $specialistId,
	]);
	$specialist = $statement->fetch();

	if ($specialist === false) {
		return null;
	}

	$serviceIds = getOfferServiceIds($pdo, $offerId);

	if (!areOfferServicesCompatibleWithSpecialist($pdo, $serviceIds, $specialistId)) {
		return null;
	}

	return [
		'offer_id' => (int) $offer['id'],
		'offer_title' => (string) $offer['title'],
		'offer_slug' => (string) $offer['slug'],
		'offer_description' => $offer['description'] !== null ? (string) $offer['description'] : null,
		'offer_image_path' => $offer['image_path'] !== null ? (string) $offer['image_path'] : null,
		'price' => (float) $offer['price'],
		'duration_minutes' => (int) $offer['duration_minutes'],
		'start_date' => (string) $offer['start_date'],
		'end_date' => (string) $offer['end_date'],
		'specialist_id' => (int) $specialist['id'],
		'specialist_name' => (string) $specialist['name'],
		'specialist_specialization' => $specialist['specialization'] !== null ? (string) $specialist['specialization'] : null,
	];
}
