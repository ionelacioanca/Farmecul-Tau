<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/booking.php';

$headerHasCustomerSession = getCurrentUserId() !== null;

setSalonTimezone();

function publicOfferEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function publicOfferDate(string $dateValue): string
{
	try {
		$date = new DateTimeImmutable($dateValue, getSalonTimezone());
	} catch (Exception $exception) {
		return $dateValue;
	}

	$months = [
		1 => 'ianuarie',
		2 => 'februarie',
		3 => 'martie',
		4 => 'aprilie',
		5 => 'mai',
		6 => 'iunie',
		7 => 'iulie',
		8 => 'august',
		9 => 'septembrie',
		10 => 'octombrie',
		11 => 'noiembrie',
		12 => 'decembrie',
	];

	return (int) $date->format('j') . ' ' . $months[(int) $date->format('n')];
}

function publicOfferImagePath(?string $imagePath): ?string
{
	if ($imagePath === null || trim($imagePath) === '') {
		return null;
	}

	$relativePath = ltrim($imagePath, '/\\');

	if (!is_file(dirname(__DIR__) . '/' . $relativePath)) {
		return null;
	}

	return '../' . $relativePath;
}

$today = new DateTimeImmutable('today', getSalonTimezone());
$offerStatement = $pdo->prepare(
	'SELECT
		o.id,
		o.title,
		o.description,
		o.image_path,
		o.price,
		o.duration_minutes,
		o.start_date,
		o.end_date,
		COUNT(DISTINCT os.service_id) AS included_service_count,
		COUNT(DISTINCT sp.id) AS eligible_specialist_count
	 FROM offers o
	 LEFT JOIN offer_services os ON os.offer_id = o.id
	 LEFT JOIN offer_specialists osp ON osp.offer_id = o.id
	 LEFT JOIN specialists sp ON sp.id = osp.specialist_id AND sp.active = 1
	 WHERE o.active = 1
		AND o.end_date >= CURDATE()
	 GROUP BY
		o.id,
		o.title,
		o.description,
		o.image_path,
		o.price,
		o.duration_minutes,
		o.start_date,
		o.end_date
	 ORDER BY
		CASE WHEN o.start_date <= CURDATE() THEN 0 ELSE 1 END ASC,
		o.start_date ASC,
		o.id DESC'
);
$offerStatement->execute();
$offers = $offerStatement->fetchAll();
$offerIds = array_map('intval', array_column($offers, 'id'));
$servicesByOffer = [];

if ($offerIds !== []) {
	$placeholders = implode(', ', array_fill(0, count($offerIds), '?'));
	$serviceStatement = $pdo->prepare(
		"SELECT os.offer_id, sv.name
		 FROM offer_services os
		 INNER JOIN services sv ON sv.id = os.service_id
		 WHERE os.offer_id IN ($placeholders)
		 ORDER BY sv.name ASC"
	);
	$serviceStatement->execute($offerIds);

	foreach ($serviceStatement->fetchAll() as $service) {
		$servicesByOffer[(int) $service['offer_id']][] = (string) $service['name'];
	}
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Oferte | Farmecul Tau</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="../css/style.css?v=20260901-4">
</head>
<body>
	<?php require_once __DIR__ . '/../includes/header.php'; ?>

	<main class="offers-page" aria-labelledby="offers-title">
		<section class="offers-intro">
			<div class="offers-intro-copy">
				<p class="section-kicker">FARMECUL TAU</p>
				<h1 id="offers-title">Oferte</h1>
				<p class="offers-intro-line">Un plus de rasfat, din partea noastra.</p>
				<p>Descopera ofertele si experientele pregatite pentru urmatoarea ta vizita.</p>
			</div>
			<div class="offers-intro-visual" aria-hidden="true">
				<img src="../images/oferte-home.png" alt="">
			</div>
		</section>

		<section class="offers-current" aria-labelledby="current-offers-title">
			<div class="offers-section-heading">
				<h2 class="site-map-kicker" id="current-offers-title">Ofertele momentului</h2>
			</div>

			<?php if ($offers === []): ?>
				<div class="offers-empty">
					<h3>Pregatim noi surprize pentru tine.</h3>
					<p>Revino in curand pentru a descoperi urmatoarele oferte Farmecul Tau.</p>
					<a class="offer-cta" href="servicii.php">VEZI SERVICIILE</a>
				</div>
			<?php else: ?>
				<div class="offers-list">
					<?php foreach ($offers as $index => $offer): ?>
						<?php
							$offerId = (int) $offer['id'];
							$serviceNames = $servicesByOffer[$offerId] ?? [];
							$isUpcoming = (string) $offer['start_date'] > $today->format('Y-m-d');
							$isBookable = (int) $offer['eligible_specialist_count'] > 0;
							$imagePath = publicOfferImagePath($offer['image_path'] !== null ? (string) $offer['image_path'] : null);
							$statusLabel = $isUpcoming ? 'IN CURAND' : 'DISPONIBILA';
							$validityLabel = $isUpcoming
								? 'Disponibila din ' . publicOfferDate((string) $offer['start_date'])
								: 'Valabila pana la ' . publicOfferDate((string) $offer['end_date']);
						?>
						<article class="offer-card<?php echo $index % 2 === 1 ? ' offer-card-reverse' : ''; ?>" aria-labelledby="offer-title-<?php echo $offerId; ?>">
							<div class="offer-card-visual">
								<?php if ($imagePath !== null): ?>
									<img src="<?php echo publicOfferEscape($imagePath); ?>" alt="<?php echo publicOfferEscape((string) $offer['title']); ?>">
								<?php else: ?>
									<div class="offer-card-fallback" aria-hidden="true">
										<span>Farmecul Tau</span>
									</div>
								<?php endif; ?>
							</div>

							<div class="offer-card-content">
								<p class="offer-status"><?php echo publicOfferEscape($statusLabel); ?></p>
								<h3 id="offer-title-<?php echo $offerId; ?>"><?php echo publicOfferEscape((string) $offer['title']); ?></h3>
								<?php if ($offer['description'] !== null && trim((string) $offer['description']) !== ''): ?>
									<p class="offer-description"><?php echo publicOfferEscape((string) $offer['description']); ?></p>
								<?php endif; ?>

								<div class="offer-meta" aria-label="Detalii oferta">
									<span><?php echo (int) $offer['duration_minutes']; ?> min</span>
									<span><?php echo publicOfferEscape(number_format((float) $offer['price'], 0, ',', '.')); ?> lei</span>
								</div>

								<?php if ($serviceNames !== []): ?>
									<div class="offer-includes">
										<p>Include:</p>
										<ul>
											<?php foreach ($serviceNames as $serviceName): ?>
												<li><?php echo publicOfferEscape($serviceName); ?></li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endif; ?>

								<p class="offer-validity"><?php echo publicOfferEscape($validityLabel); ?></p>
								<p class="offer-specialists">
									<?php echo (int) $offer['eligible_specialist_count'] === 1 ? 'Disponibila la 1 specialist' : 'Disponibila la ' . (int) $offer['eligible_specialist_count'] . ' specialisti'; ?>
								</p>

								<?php if ($isBookable): ?>
									<button
										class="offer-cta"
										type="button"
										data-offer-booking-toggle
										aria-expanded="false"
										aria-controls="offer-booking-<?php echo $offerId; ?>"
									>
										PROGRAMEAZA-TE
									</button>
								<?php else: ?>
									<span class="offer-cta offer-cta-disabled" aria-disabled="true">PROGRAMARE INDISPONIBILA</span>
								<?php endif; ?>
							</div>

							<?php if ($isBookable): ?>
								<div
									class="offer-booking-panel"
									id="offer-booking-<?php echo $offerId; ?>"
									data-offer-booking
									data-offer-id="<?php echo $offerId; ?>"
									data-offer-title="<?php echo publicOfferEscape((string) $offer['title']); ?>"
									data-offer-price="<?php echo publicOfferEscape((string) $offer['price']); ?>"
									data-offer-duration="<?php echo (int) $offer['duration_minutes']; ?>"
									hidden
								>
									<div class="offer-booking-heading">
										<p class="booking-kicker">OFERTA SELECTATA</p>
										<h4><?php echo publicOfferEscape((string) $offer['title']); ?></h4>
										<p><?php echo publicOfferEscape(number_format((float) $offer['price'], 0, ',', '.')); ?> lei &bull; <?php echo (int) $offer['duration_minutes']; ?> min</p>
									</div>

									<form class="booking-form offer-booking-controls" data-offer-booking-controls>
										<label data-offer-specialist-field>
											<span>Specialist</span>
											<select name="specialist_id" data-offer-specialist required disabled>
												<option value="">Se incarca specialistii...</option>
											</select>
										</label>

										<label data-offer-date-field hidden>
											<span>Data</span>
											<input type="date" name="date" data-offer-date required>
										</label>
									</form>

									<div class="booking-slots offer-booking-slots" data-offer-slots-section hidden aria-live="polite">
										<p class="booking-status" data-offer-status>Alege specialistul si data.</p>
										<div class="booking-slot-grid" data-offer-slots></div>
									</div>

									<section class="booking-details offer-booking-details" data-offer-details hidden aria-live="polite">
										<div class="booking-summary">
											<p class="booking-summary-label">Rezumat</p>
											<dl class="booking-summary-list">
												<div>
													<dt>Oferta</dt>
													<dd data-offer-summary-title><?php echo publicOfferEscape((string) $offer['title']); ?></dd>
												</div>
												<div>
													<dt>Specialist</dt>
													<dd data-offer-summary-specialist>-</dd>
												</div>
												<div>
													<dt>Data</dt>
													<dd data-offer-summary-date>-</dd>
												</div>
												<div>
													<dt>Ora</dt>
													<dd data-offer-summary-time>-</dd>
												</div>
												<div>
													<dt>Pret</dt>
													<dd data-offer-summary-price><?php echo publicOfferEscape(number_format((float) $offer['price'], 0, ',', '.')); ?> lei</dd>
												</div>
												<div>
													<dt>Durata</dt>
													<dd data-offer-summary-duration><?php echo (int) $offer['duration_minutes']; ?> min</dd>
												</div>
											</dl>
										</div>

										<form class="booking-details-form" data-offer-details-form>
											<label data-offer-contact-field="name">
												<span>Nume</span>
												<input type="text" name="customer_name" autocomplete="name" maxlength="150" required>
											</label>

											<label data-offer-contact-field="email">
												<span>Email</span>
												<input type="email" name="customer_email" autocomplete="email" maxlength="255" required>
											</label>

											<label data-offer-contact-field="phone">
												<span>Telefon</span>
												<input type="tel" name="customer_phone" autocomplete="tel" maxlength="50" required>
											</label>

											<label class="booking-full-field" data-offer-contact-field="notes">
												<span>Observatii</span>
												<textarea name="notes" maxlength="1000" rows="4"></textarea>
											</label>

											<p class="booking-form-message" data-offer-form-message></p>
											<button type="submit" class="booking-submit">TRIMITE PROGRAMAREA</button>
										</form>
									</section>
								</div>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<section class="offers-surprise" aria-labelledby="offers-surprise-title">
			<p class="section-kicker">SURPRIZA</p>
			<h2 id="offers-surprise-title">Mai avem o surpriza pentru tine.</h2>
			<p>Recunosti cine a spus citatul? Pune-ti inspiratia la incercare si descopera ce premiu te asteapta.</p>
			<a class="offer-cta offer-cta-light" href="../index.php#beauty-quote-surpriza">DESCOPERA SURPRIZA</a>
		</section>

		<section class="offers-final-cta" aria-labelledby="offers-final-title">
			<h2 id="offers-final-title">Ti-ai ales rasfatul?</h2>
			<p>Rezerva momentul tau la Farmecul Tau.</p>
			<a class="offer-cta" href="programari.php">PROGRAMEAZA-TE</a>
		</section>
	</main>

	<script src="../js/script.js?v=20260901-2"></script>
	<script src="../js/offer-booking.js?v=20260831-1"></script>
</body>
</html>
