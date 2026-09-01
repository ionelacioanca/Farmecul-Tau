<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/service-helpers.php';

$headerHasCustomerSession = getCurrentUserId() !== null;

ensureAllServiceSlugs($pdo);

$serviceCategories = [
	'hairstyle' => 'Par',
	'nails' => 'Unghii',
];
$serviceCategoryHeadings = [
	'hairstyle' => 'Servicii de par',
	'nails' => 'Servicii de unghii',
];
$requestedCategory = isset($_GET['category']) ? (string) $_GET['category'] : '';
$selectedCategory = isset($serviceCategories[$requestedCategory]) ? $requestedCategory : null;
$servicesHeading = $selectedCategory !== null ? $serviceCategoryHeadings[$selectedCategory] : 'Ritualuri create de specialistii Farmecul Tau';
$serviceSql =
	'SELECT
		sv.id AS service_id,
		sv.name AS service_name,
		sv.slug,
		sv.description,
		sv.category,
		sp.id AS specialist_id,
		sp.name AS specialist_name,
		ss.price,
		ss.duration_minutes
	 FROM services sv
	 INNER JOIN specialist_services ss ON ss.service_id = sv.id
	 INNER JOIN specialists sp ON sp.id = ss.specialist_id
	 WHERE sv.active = 1
		AND sp.active = 1
		AND ss.active = 1
		AND sp.specialization = CASE sv.category
			WHEN \'hairstyle\' THEN \'hairstylist\'
			WHEN \'nails\' THEN \'nails\'
		END';

if ($selectedCategory !== null) {
	$serviceSql .= ' AND sv.category = :category';
}

$serviceSql .= ' ORDER BY sv.category ASC, sv.name ASC, sp.name ASC';
$statement = $pdo->prepare($serviceSql);
$statement->execute($selectedCategory !== null ? ['category' => $selectedCategory] : []);
$services = [];
$serviceIds = [];

foreach ($statement->fetchAll() as $row) {
	$serviceId = (int) $row['service_id'];

	if (!isset($services[$serviceId])) {
		$services[$serviceId] = [
			'id' => $serviceId,
			'name' => (string) $row['service_name'],
			'slug' => (string) $row['slug'],
			'description' => $row['description'] !== null ? (string) $row['description'] : '',
			'category' => (string) $row['category'],
			'specialists' => [],
		];
		$serviceIds[] = $serviceId;
	}

	$services[$serviceId]['specialists'][(int) $row['specialist_id']] = [
		'id' => (int) $row['specialist_id'],
		'name' => (string) $row['specialist_name'],
		'price' => $row['price'] !== null ? (float) $row['price'] : null,
		'duration_minutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
		'bookable' => $row['price'] !== null
			&& (float) $row['price'] >= 0
			&& $row['duration_minutes'] !== null
			&& (int) $row['duration_minutes'] >= 5
			&& (int) $row['duration_minutes'] <= 480,
		'images' => [],
	];
}

if ($serviceIds !== []) {
	$placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
	$imageStatement = $pdo->prepare(
		"SELECT specialist_id, service_id, image_path, alt_text
		 FROM specialist_service_images
		 WHERE active = 1
			AND service_id IN ($placeholders)
		 ORDER BY service_id ASC, specialist_id ASC, sort_order ASC, id ASC"
	);
	$imageStatement->execute($serviceIds);

	foreach ($imageStatement->fetchAll() as $image) {
		$serviceId = (int) $image['service_id'];
		$specialistId = (int) $image['specialist_id'];

		if (isset($services[$serviceId]['specialists'][$specialistId])) {
			$services[$serviceId]['specialists'][$specialistId]['images'][] = [
				'path' => '../' . ltrim((string) $image['image_path'], '/\\'),
				'alt' => $image['alt_text'] !== null ? (string) $image['alt_text'] : '',
			];
		}
	}
}

$fallbackImage = '../images/hero-farmecul-tau.png';
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Servicii | Farmecul Tau</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="../css/style.css?v=20260901-4">
</head>
<body>
	<?php require_once __DIR__ . '/../includes/header.php'; ?>

	<main class="services-page" aria-labelledby="services-title">
		<section class="services-hero">
			<p class="section-kicker">SERVICII</p>
			<h1 id="services-title"><?php echo htmlspecialchars($servicesHeading, ENT_QUOTES, 'UTF-8'); ?></h1>
			<nav class="services-category-filter" aria-label="Filtre servicii">
				<a class="<?php echo $selectedCategory === null ? 'is-active' : ''; ?>" href="servicii.php">Toate</a>
				<?php foreach ($serviceCategories as $categoryKey => $categoryLabel): ?>
					<a class="<?php echo $selectedCategory === $categoryKey ? 'is-active' : ''; ?>" href="servicii.php?category=<?php echo htmlspecialchars($categoryKey, ENT_QUOTES, 'UTF-8'); ?>">
						<?php echo htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8'); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</section>

		<section class="services-list" aria-label="Lista servicii">
			<?php if ($services === []): ?>
				<p class="services-empty">Momentan nu exista servicii disponibile.</p>
			<?php else: ?>
				<?php foreach ($services as $service): ?>
					<?php
						$specialists = array_values($service['specialists']);
						$bookableSpecialists = array_values(array_filter($specialists, static fn (array $specialist): bool => (bool) $specialist['bookable']));
						$initialSpecialist = $bookableSpecialists[0] ?? $specialists[0];
						$initialImages = $initialSpecialist['images'] !== [] ? $initialSpecialist['images'] : [[
							'path' => $fallbackImage,
							'alt' => (string) $service['name'],
						]];
					?>
					<article class="public-service-card" id="<?php echo htmlspecialchars((string) $service['slug'], ENT_QUOTES, 'UTF-8'); ?>" data-service-card>
						<div class="public-service-content">
							<p class="section-kicker"><?php echo htmlspecialchars(serviceCategoryLabel((string) $service['category']), ENT_QUOTES, 'UTF-8'); ?></p>
							<h2><?php echo htmlspecialchars((string) $service['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
							<?php if ($service['description'] !== ''): ?>
								<p><?php echo htmlspecialchars((string) $service['description'], ENT_QUOTES, 'UTF-8'); ?></p>
							<?php endif; ?>

							<div class="service-specialist-tabs" role="tablist" aria-label="Specialisti pentru <?php echo htmlspecialchars((string) $service['name'], ENT_QUOTES, 'UTF-8'); ?>">
								<?php foreach ($specialists as $index => $specialist): ?>
									<button
										type="button"
										role="tab"
										class="<?php echo $index === 0 ? 'is-active' : ''; ?>"
										aria-selected="<?php echo $index === 0 ? 'true' : 'false'; ?>"
										data-specialist-tab
										data-specialist-id="<?php echo (int) $specialist['id']; ?>"
									>
										<?php echo htmlspecialchars((string) $specialist['name'], ENT_QUOTES, 'UTF-8'); ?>
									</button>
								<?php endforeach; ?>
							</div>

							<div class="public-service-meta">
								<p>Specialist: <strong data-specialist-name><?php echo htmlspecialchars((string) $initialSpecialist['name'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
								<p>Pret: <strong data-specialist-price><?php echo $initialSpecialist['price'] !== null ? htmlspecialchars(number_format((float) $initialSpecialist['price'], 2, '.', '') . ' lei', ENT_QUOTES, 'UTF-8') : '-'; ?></strong></p>
								<p>Durata: <strong data-specialist-duration><?php echo $initialSpecialist['duration_minutes'] !== null ? (int) $initialSpecialist['duration_minutes'] . ' min' : '-'; ?></strong></p>
							</div>

							<a
								class="service-booking-link<?php echo (bool) $initialSpecialist['bookable'] ? '' : ' is-disabled'; ?>"
								data-booking-link
								<?php if ((bool) $initialSpecialist['bookable']): ?>
									href="programari.php?service_id=<?php echo (int) $service['id']; ?>&specialist_id=<?php echo (int) $initialSpecialist['id']; ?>"
								<?php else: ?>
									aria-disabled="true"
									tabindex="-1"
								<?php endif; ?>
							><?php echo (bool) $initialSpecialist['bookable'] ? 'PROGRAMEAZA-TE' : 'PROGRAMARE INDISPONIBILA'; ?></a>
						</div>

						<div class="public-service-gallery" data-gallery>
							<button type="button" class="gallery-arrow" data-gallery-prev aria-label="Imaginea anterioara">&lsaquo;</button>
							<button type="button" class="gallery-image-button" data-lightbox-open>
								<img data-gallery-image src="<?php echo htmlspecialchars($initialImages[0]['path'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($initialImages[0]['alt'] !== '' ? $initialImages[0]['alt'] : (string) $service['name'], ENT_QUOTES, 'UTF-8'); ?>">
							</button>
							<button type="button" class="gallery-arrow" data-gallery-next aria-label="Imaginea urmatoare">&rsaquo;</button>
							<div class="gallery-dots" data-gallery-dots></div>
						</div>

						<script type="application/json" data-service-gallery-data><?php echo json_encode([
							'serviceId' => (int) $service['id'],
							'specialists' => array_map(static function (array $specialist) use ($service, $fallbackImage): array {
								$images = $specialist['images'] !== [] ? $specialist['images'] : [[
									'path' => $fallbackImage,
									'alt' => (string) $service['name'],
								]];

								return [
									'id' => (int) $specialist['id'],
									'name' => (string) $specialist['name'],
									'price' => $specialist['price'] !== null ? (float) $specialist['price'] : null,
									'duration_minutes' => $specialist['duration_minutes'] !== null ? (int) $specialist['duration_minutes'] : null,
									'bookable' => (bool) $specialist['bookable'],
									'images' => $images,
								];
							}, $specialists),
						], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
					</article>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
	</main>

	<div class="service-lightbox" data-service-lightbox hidden>
		<button type="button" class="lightbox-close" data-lightbox-close aria-label="Inchide galeria">&times;</button>
		<button type="button" class="lightbox-arrow lightbox-prev" data-lightbox-prev aria-label="Imaginea anterioara">&lsaquo;</button>
		<img data-lightbox-image src="" alt="">
		<button type="button" class="lightbox-arrow lightbox-next" data-lightbox-next aria-label="Imaginea urmatoare">&rsaquo;</button>
	</div>

	<script src="../js/script.js?v=20260901-2"></script>
	<script src="../js/services-gallery.js?v=20260828-2"></script>
</body>
</html>
