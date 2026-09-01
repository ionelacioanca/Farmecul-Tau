<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$headerHasCustomerSession = getCurrentUserId() !== null;

function pageEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool
{
	$statement = $pdo->prepare(
		'SELECT COUNT(*) AS column_exists
		 FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = :table_name
			AND COLUMN_NAME = :column_name'
	);
	$statement->execute([
		'table_name' => $tableName,
		'column_name' => $columnName,
	]);

	return (int) ($statement->fetch()['column_exists'] ?? 0) > 0;
}

function localImageUrl(?string $path): ?string
{
	if ($path === null) {
		return null;
	}

	$normalizedPath = ltrim(str_replace('\\', '/', trim($path)), '/');

	if (
		$normalizedPath === ''
		|| preg_match('/^(https?:)?\/\//i', $normalizedPath)
		|| preg_match('#(^|/)\.\.(/|$)#', $normalizedPath)
	) {
		return null;
	}

	$fullPath = dirname(__DIR__) . '/' . $normalizedPath;

	if (!is_file($fullPath)) {
		return null;
	}

	return '../' . $normalizedPath;
}

function specialistLabel(?string $specialization): string
{
	return match ($specialization) {
		'hairstylist' => 'Hair stylist',
		'nails' => 'Nails specialist',
		default => 'Specialist Farmecul Tău',
	};
}

function specialistInitial(string $name): string
{
	if (function_exists('mb_substr')) {
		return mb_substr($name, 0, 1, 'UTF-8');
	}

	return substr($name, 0, 1);
}

$hasProfileImage = columnExists($pdo, 'specialists', 'profile_image');
$hasBio = columnExists($pdo, 'specialists', 'bio');
$selectFields = [
	'sp.id',
	'sp.name',
	'sp.specialization',
];

if ($hasProfileImage) {
	$selectFields[] = 'sp.profile_image';
}

if ($hasBio) {
	$selectFields[] = 'sp.bio';
}

$specialistsStatement = $pdo->prepare(
	'SELECT ' . implode(', ', $selectFields) . '
	 FROM specialists sp
	 WHERE sp.active = 1
	 ORDER BY sp.name ASC'
);
$specialistsStatement->execute();
$specialists = $specialistsStatement->fetchAll();
$specialistIds = array_map(static fn (array $specialist): int => (int) $specialist['id'], $specialists);
$servicesBySpecialist = [];

if ($specialistIds !== []) {
	$placeholders = implode(',', array_fill(0, count($specialistIds), '?'));
	$servicesStatement = $pdo->prepare(
		"SELECT
			ss.specialist_id,
			sv.id AS service_id,
			sv.name AS service_name,
			ss.price,
			ss.duration_minutes
		 FROM specialist_services ss
		 INNER JOIN services sv ON sv.id = ss.service_id
		 INNER JOIN specialists sp ON sp.id = ss.specialist_id
		 WHERE ss.active = 1
			AND sv.active = 1
			AND sp.active = 1
			AND ss.specialist_id IN ($placeholders)
			AND sp.specialization = CASE sv.category
				WHEN 'hairstyle' THEN 'hairstylist'
				WHEN 'nails' THEN 'nails'
			END
		 ORDER BY ss.specialist_id ASC, sv.name ASC"
	);
	$servicesStatement->execute($specialistIds);

	foreach ($servicesStatement->fetchAll() as $service) {
		$specialistId = (int) $service['specialist_id'];
		$servicesBySpecialist[$specialistId][] = [
			'id' => (int) $service['service_id'],
			'name' => (string) $service['service_name'],
			'bookable' => $service['price'] !== null
				&& (float) $service['price'] >= 0
				&& $service['duration_minutes'] !== null
				&& (int) $service['duration_minutes'] >= 5
				&& (int) $service['duration_minutes'] <= 480,
		];
	}
}

$salonImageCandidates = [
	[
		'path' => 'images/salon-1.jpg',
		'alt' => 'Interiorul salonului Farmecul Tău',
	],
	[
		'path' => 'images/salon-2.jpg',
		'alt' => 'Detaliu din salonul Farmecul Tău',
	],
	[
		'path' => 'images/salon-3.jpg',
		'alt' => 'Atmosfera salonului Farmecul Tău',
	],
	[
		'path' => 'uploads/about/salon-1.jpg',
		'alt' => 'Spațiul Farmecul Tău',
	],
];
$salonImages = [];

foreach ($salonImageCandidates as $candidate) {
	$imageUrl = localImageUrl($candidate['path']);

	if ($imageUrl !== null) {
		$salonImages[] = [
			'url' => $imageUrl,
			'alt' => $candidate['alt'],
		];
	}
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Despre | Farmecul Tău</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="../css/style.css?v=20260901-4">
</head>
<body>
	<?php require_once __DIR__ . '/../includes/header.php'; ?>

	<main class="about-page" aria-labelledby="about-title">
		<section class="about-intro">
			<div class="about-intro-copy">
				<p class="section-kicker">DESPRE</p>
				<h1 id="about-title">Despre Farmecul Tău</h1>
				<p class="about-lede">Un loc gândit pentru ritualuri de frumusețe lucrate cu atenție, calm și grijă pentru detaliile care schimbă o zi.</p>
			</div>
		</section>

		<section class="about-story" aria-labelledby="about-story-title">
			<div class="about-story-content">
				<p class="section-kicker">POVESTE</p>
				<h2 id="about-story-title">Povestea salonului</h2>
				<p><strong>Text placeholder - de înlocuit cu povestea reală a salonului.</strong></p>
				<p>Aici va fi adăugată povestea Farmecul Tău: cum a început salonul, ce au dorit fondatoarele să creeze și filosofia care ghidează fiecare întâlnire cu clientele.</p>
				<p>Până la completarea conținutului real, această zonă rămâne intenționat neutră și nu include ani, premii, biografii sau afirmații neverificate.</p>
			</div>
			<figure class="about-story-visual">
				<div class="about-image-placeholder" role="img" aria-label="Imagine de adăugat cu salonul sau fondatoarele Farmecul Tău">
					<span>Imagine salon / fondatoare</span>
					<small>placeholder pentru fotografie reală</small>
				</div>
			</figure>
		</section>

		<section class="about-team" aria-labelledby="about-team-title">
			<div class="about-section-heading">
				<p class="section-kicker">ECHIPĂ</p>
				<h2 id="about-team-title">Echipa Farmecul Tău</h2>
			</div>

			<?php if ($specialists === []): ?>
				<p class="about-empty">Momentan nu există specialiști activi publicați.</p>
			<?php else: ?>
				<div class="about-team-grid">
					<?php foreach ($specialists as $specialist): ?>
						<?php
							$specialistId = (int) $specialist['id'];
							$specialistName = (string) $specialist['name'];
							$specialistServices = $servicesBySpecialist[$specialistId] ?? [];
							$bookableServices = array_values(array_filter($specialistServices, static fn (array $service): bool => (bool) $service['bookable']));
							$firstBookableService = $bookableServices[0] ?? null;
							$profileImage = $hasProfileImage ? localImageUrl($specialist['profile_image'] ?? null) : null;
							$bio = $hasBio && isset($specialist['bio']) ? trim((string) $specialist['bio']) : '';
							$bookingHref = 'programari.php';

							if ($firstBookableService !== null) {
								$bookingHref .= '?service_id=' . rawurlencode((string) $firstBookableService['id']) . '&specialist_id=' . rawurlencode((string) $specialistId);
							} else {
								$bookingHref .= '?specialist_id=' . rawurlencode((string) $specialistId);
							}
						?>
						<article class="team-card">
							<?php if ($profileImage !== null): ?>
								<img class="team-photo" src="<?php echo pageEscape($profileImage); ?>" alt="Portret <?php echo pageEscape($specialistName); ?>">
							<?php else: ?>
								<div class="team-photo team-photo-placeholder" aria-hidden="true">
									<span><?php echo pageEscape(specialistInitial($specialistName)); ?></span>
								</div>
							<?php endif; ?>

							<div class="team-card-content">
								<p class="team-specialization"><?php echo pageEscape(specialistLabel($specialist['specialization'] !== null ? (string) $specialist['specialization'] : null)); ?></p>
								<h3><?php echo pageEscape($specialistName); ?></h3>
								<?php if ($bio !== ''): ?>
									<p><?php echo pageEscape($bio); ?></p>
								<?php else: ?>
									<p><strong>Bio placeholder - de completat cu descrierea reală a specialistului.</strong></p>
								<?php endif; ?>

								<?php if ($specialistServices !== []): ?>
									<ul class="team-service-list" aria-label="Servicii relevante">
										<?php foreach (array_slice($specialistServices, 0, 4) as $service): ?>
											<li><?php echo pageEscape((string) $service['name']); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php else: ?>
									<p class="team-muted">Serviciile specialistului vor fi afișate după configurare.</p>
								<?php endif; ?>

								<a class="about-cta-link" href="<?php echo pageEscape($bookingHref); ?>">PROGRAMEAZĂ-TE</a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="about-atmosphere" aria-labelledby="about-atmosphere-title">
			<div class="about-section-heading">
				<p class="section-kicker">ÎN SALON</p>
				<h2 id="about-atmosphere-title">Atmosfera Farmecul Tău</h2>
			</div>

			<?php if ($salonImages !== []): ?>
				<div class="salon-gallery" aria-label="Galerie foto salon">
					<?php foreach ($salonImages as $image): ?>
						<figure>
							<img src="<?php echo pageEscape($image['url']); ?>" alt="<?php echo pageEscape($image['alt']); ?>">
						</figure>
					<?php endforeach; ?>
				</div>
			<?php else: ?>
				<div class="salon-gallery salon-gallery-placeholders" aria-label="Spații rezervate pentru fotografii reale din salon">
					<?php for ($index = 1; $index <= 3; $index++): ?>
						<div class="salon-placeholder" role="img" aria-label="Fotografie de adăugat din salonul Farmecul Tău">
							<span>Fotografie salon <?php echo $index; ?></span>
							<small>placeholder pentru imagine locală reală</small>
						</div>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="about-products" id="produse-folosite" aria-labelledby="about-products-title">
			<div class="about-products-copy">
				<p class="section-kicker">PRODUSE</p>
				<h2 id="about-products-title">Produsele pe care le folosim</h2>
				<p>Farmecul Tău nu desfășoară vânzări online. Aici prezentăm, în mod informativ, produsele profesionale pe care le recomandăm și le folosim în salon, pentru ritualuri de îngrijire gândite să completeze experiența de tratament.</p>
			</div>
			<div class="product-info-grid">
				<article class="product-info-card">
					<p class="product-info-label">De completat</p>
					<h3>Brand / gamă profesională</h3>
					<p>Placeholder pentru imagine, numele brandului și o scurtă descriere a gamelor profesionale folosite în salon.</p>
				</article>
				<article class="product-info-card">
					<p class="product-info-label">De completat</p>
					<h3>Ritualuri și îngrijire</h3>
					<p>Placeholder pentru categoria de produs, rolul în tratament și recomandarea generală, fără limbaj de vânzare sau funcții de cumpărare.</p>
				</article>
			</div>
		</section>

		<section class="about-final-cta" aria-labelledby="about-final-title">
			<p class="section-kicker">TE AȘTEPTĂM</p>
			<h2 id="about-final-title">Te așteptăm la Farmecul Tău.</h2>
			<div class="about-final-actions">
				<a class="about-cta-link about-cta-secondary" href="servicii.php">DESCOPERĂ SERVICIILE</a>
				<a class="about-cta-link" href="programari.php">PROGRAMEAZĂ-TE</a>
			</div>
		</section>
	</main>

	<script src="../js/script.js?v=20260901-2"></script>
</body>
</html>
