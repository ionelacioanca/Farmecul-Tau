<?php
declare(strict_types=1);

function serviceCategoryLabel(?string $category): string
{
	return match ($category) {
		'hairstyle' => 'Hairstyle',
		'nails' => 'Nails',
		default => 'Servicii',
	};
}

function serviceSlugify(string $name): string
{
	$slug = trim($name);

	if (function_exists('transliterator_transliterate')) {
		$slug = transliterator_transliterate('Any-Latin; Latin-ASCII;', $slug);
	} else {
		$converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
		$slug = is_string($converted) ? $converted : $slug;
	}

	$slug = strtolower($slug);
	$slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
	$slug = trim($slug, '-');

	return $slug !== '' ? $slug : 'serviciu';
}

function buildUniqueServiceSlug(PDO $pdo, string $name, ?int $excludedServiceId = null): string
{
	$baseSlug = serviceSlugify($name);
	$slug = $baseSlug;
	$suffix = 2;

	while (true) {
		$sql = 'SELECT id FROM services WHERE slug = :slug';
		$params = ['slug' => $slug];

		if ($excludedServiceId !== null) {
			$sql .= ' AND id <> :excluded_service_id';
			$params['excluded_service_id'] = $excludedServiceId;
		}

		$sql .= ' LIMIT 1';
		$statement = $pdo->prepare($sql);
		$statement->execute($params);

		if ($statement->fetch() === false) {
			return $slug;
		}

		$slug = $baseSlug . '-' . $suffix;
		$suffix++;
	}
}

function ensureServiceSlug(PDO $pdo, int $serviceId, string $serviceName, ?string $currentSlug = null): string
{
	if (is_string($currentSlug) && trim($currentSlug) !== '') {
		return $currentSlug;
	}

	$slug = buildUniqueServiceSlug($pdo, $serviceName, $serviceId);
	$statement = $pdo->prepare('UPDATE services SET slug = :slug WHERE id = :id');
	$statement->execute([
		'id' => $serviceId,
		'slug' => $slug,
	]);

	return $slug;
}

function ensureAllServiceSlugs(PDO $pdo): void
{
	$statement = $pdo->query('SELECT id, name, slug FROM services WHERE slug IS NULL OR slug = \'\' ORDER BY id ASC');

	foreach ($statement->fetchAll() as $service) {
		ensureServiceSlug($pdo, (int) $service['id'], (string) $service['name'], $service['slug'] !== null ? (string) $service['slug'] : null);
	}
}
