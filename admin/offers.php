<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/offer-helpers.php';

setSalonTimezone();
$dashboardUser = requireAdminUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);
$csrfToken = getAdminCsrfToken();
$message = '';
$errors = [];
$savedOfferImagePath = null;
$imageToDeleteAfterCommit = null;
$maxOfferUploadBytes = 5 * 1024 * 1024;
$allowedOfferMimeTypes = [
	'image/jpeg' => 'jpg',
	'image/png' => 'png',
	'image/webp' => 'webp',
];

function collectOfferIds(mixed $value): array
{
	$rawValues = is_array($value) ? $value : [];
	$ids = [];

	foreach ($rawValues as $rawValue) {
		$id = filter_var($rawValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if ($id !== false) {
			$ids[] = (int) $id;
		}
	}

	return array_values(array_unique($ids));
}

function loadOfferServicesByOffer(PDO $pdo, array $offerIds): array
{
	if ($offerIds === []) {
		return [];
	}

	$placeholders = implode(', ', array_fill(0, count($offerIds), '?'));
	$statement = $pdo->prepare(
		"SELECT os.offer_id, sv.id, sv.name, sv.category
		 FROM offer_services os
		 INNER JOIN services sv ON sv.id = os.service_id
		 WHERE os.offer_id IN ($placeholders)
		 ORDER BY sv.name ASC"
	);
	$statement->execute($offerIds);
	$servicesByOffer = [];

	foreach ($statement->fetchAll() as $row) {
		$servicesByOffer[(int) $row['offer_id']][] = $row;
	}

	return $servicesByOffer;
}

function loadOfferSpecialistsByOffer(PDO $pdo, array $offerIds): array
{
	if ($offerIds === []) {
		return [];
	}

	$placeholders = implode(', ', array_fill(0, count($offerIds), '?'));
	$statement = $pdo->prepare(
		"SELECT os.offer_id, sp.id, sp.name
		 FROM offer_specialists os
		 INNER JOIN specialists sp ON sp.id = os.specialist_id
		 WHERE os.offer_id IN ($placeholders)
		 ORDER BY sp.name ASC"
	);
	$statement->execute($offerIds);
	$specialistsByOffer = [];

	foreach ($statement->fetchAll() as $row) {
		$specialistsByOffer[(int) $row['offer_id']][] = $row;
	}

	return $specialistsByOffer;
}

function validateOfferInput(PDO $pdo, array $values, array $serviceIds, array $specialistIds, array $servicesById, array $specialistsById): array
{
	$errors = [];
	$eligibleSpecialists = [];
	$eligibleSpecialistIds = [];
	$price = parseAdminDecimal($values['price']);
	$duration = filter_var($values['duration_minutes'], FILTER_VALIDATE_INT, [
		'options' => ['min_range' => 5, 'max_range' => 480],
	]);
	$startDate = $values['start_date'] !== '' ? parseBookingDate($values['start_date']) : null;
	$endDate = $values['end_date'] !== '' ? parseBookingDate($values['end_date']) : null;

	if ($values['title'] === '' || strlen($values['title']) > 180) {
		$errors[] = 'Introdu titlul ofertei.';
	}

	if (strlen($values['description']) > 3000) {
		$errors[] = 'Descrierea poate avea maximum 3000 de caractere.';
	}

	if ($price === null || $price < 0 || $price > 99999.99) {
		$errors[] = 'Pretul ofertei trebuie sa fie intre 0 si 99999.99.';
	}

	if ($duration === false) {
		$errors[] = 'Durata ofertei trebuie sa fie intre 5 si 480 minute.';
	}

	if ($startDate === null || $endDate === null) {
		$errors[] = 'Alege date valide pentru oferta.';
	} elseif ($startDate > $endDate) {
		$errors[] = 'Data de inceput trebuie sa fie inainte de data de sfarsit.';
	}

	if ($serviceIds === []) {
		$errors[] = 'Alege cel putin un serviciu inclus.';
	}

	foreach ($serviceIds as $serviceId) {
		if (!isset($servicesById[$serviceId])) {
			$errors[] = 'Unul dintre serviciile selectate nu este disponibil.';
			break;
		}
	}

	if ($serviceIds !== []) {
		$eligibleSpecialists = getEligibleOfferSpecialists($pdo, $serviceIds);
		$eligibleSpecialistIds = array_map('intval', array_column($eligibleSpecialists, 'id'));

		if ($eligibleSpecialists === []) {
			$errors[] = 'Niciun specialist nu ofera toate serviciile selectate.';
		}
	}

	if ($specialistIds === [] && $eligibleSpecialists !== []) {
		$errors[] = 'Alege cel putin un specialist disponibil.';
	}

	foreach ($specialistIds as $specialistId) {
		if (!isset($specialistsById[$specialistId])) {
			$errors[] = 'Unul dintre specialistii selectati nu este disponibil.';
			break;
		}

		if ($eligibleSpecialistIds !== [] && !in_array($specialistId, $eligibleSpecialistIds, true)) {
			$errors[] = 'Specialistul ' . $specialistsById[$specialistId]['name'] . ' nu mai este disponibil pentru toate serviciile selectate.';
		}
	}

	return $errors;
}

function hasOfferImageUpload(): bool
{
	return isset($_FILES['offer_image'])
		&& is_array($_FILES['offer_image'])
		&& (int) ($_FILES['offer_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

function ensureOfferUploadDirectory(int $offerId): string
{
	$directory = dirname(__DIR__) . '/uploads/offers/' . $offerId;

	if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
		throw new RuntimeException('Offer upload directory could not be created.');
	}

	return $directory;
}

function deleteOfferImageFile(?string $relativePath): void
{
	if ($relativePath === null || $relativePath === '') {
		return;
	}

	$baseDirectory = realpath(dirname(__DIR__) . '/uploads/offers');
	$fullPath = realpath(dirname(__DIR__) . '/' . ltrim($relativePath, '/\\'));

	if ($baseDirectory === false || $fullPath === false || !str_starts_with($fullPath, $baseDirectory)) {
		return;
	}

	if (is_file($fullPath)) {
		unlink($fullPath);
	}
}

function saveOfferCoverImage(int $offerId, int $maxBytes, array $allowedMimeTypes): string
{
	$file = $_FILES['offer_image'] ?? null;

	if (!is_array($file)) {
		throw new RuntimeException('No offer image upload was received.');
	}

	$errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

	if ($errorCode !== UPLOAD_ERR_OK) {
		throw new RuntimeException('Offer image upload failed.');
	}

	$tmpName = (string) ($file['tmp_name'] ?? '');
	$fileSize = (int) ($file['size'] ?? 0);

	if ($fileSize <= 0 || $fileSize > $maxBytes || !is_uploaded_file($tmpName)) {
		throw new RuntimeException('Offer image is too large or invalid.');
	}

	$finfo = new finfo(FILEINFO_MIME_TYPE);
	$mimeType = $finfo->file($tmpName);
	$extension = is_string($mimeType) && isset($allowedMimeTypes[$mimeType]) ? $allowedMimeTypes[$mimeType] : null;

	if ($extension === null || getimagesize($tmpName) === false) {
		throw new RuntimeException('Offer image format is not supported.');
	}

	$directory = ensureOfferUploadDirectory($offerId);
	$filename = bin2hex(random_bytes(16)) . '.' . $extension;
	$targetPath = $directory . '/' . $filename;

	if (!move_uploaded_file($tmpName, $targetPath)) {
		throw new RuntimeException('Offer image could not be saved.');
	}

	return 'uploads/offers/' . $offerId . '/' . $filename;
}

$serviceStatement = $pdo->prepare(
	'SELECT id, name, category
	 FROM services
	 WHERE active = 1
	 ORDER BY category ASC, name ASC'
);
$serviceStatement->execute();
$services = $serviceStatement->fetchAll();
$servicesById = [];

foreach ($services as $service) {
	$servicesById[(int) $service['id']] = $service;
}

$specialistStatement = $pdo->prepare(
	'SELECT id, name, specialization
	 FROM specialists
	 WHERE active = 1
	 ORDER BY name ASC'
);
$specialistStatement->execute();
$specialists = $specialistStatement->fetchAll();
$specialistsById = [];

foreach ($specialists as $specialist) {
	$specialistsById[(int) $specialist['id']] = $specialist;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
	$offerId = $action === 'update_offer'
		? filter_var($_POST['offer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
		: null;
	$values = [
		'title' => isset($_POST['title']) ? trim((string) $_POST['title']) : '',
		'description' => isset($_POST['description']) ? trim((string) $_POST['description']) : '',
		'price' => isset($_POST['price']) ? trim((string) $_POST['price']) : '',
		'duration_minutes' => isset($_POST['duration_minutes']) ? trim((string) $_POST['duration_minutes']) : '',
		'start_date' => isset($_POST['start_date']) ? trim((string) $_POST['start_date']) : '',
		'end_date' => isset($_POST['end_date']) ? trim((string) $_POST['end_date']) : '',
		'active' => isset($_POST['active']) ? '1' : '0',
	];
	$serviceIds = collectOfferIds($_POST['service_ids'] ?? []);
	$specialistIds = collectOfferIds($_POST['specialist_ids'] ?? []);

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
	}

	if (!in_array($action, ['create_offer', 'update_offer'], true)) {
		$errors[] = 'Actiunea nu este valida.';
	}

	if ($action === 'update_offer' && $offerId === false) {
		$errors[] = 'Oferta nu a putut fi identificata.';
	}

	$errors = array_merge(
		$errors,
		validateOfferInput($pdo, $values, $serviceIds, $specialistIds, $servicesById, $specialistsById)
	);

	if ($errors === []) {
		try {
			$pdo->beginTransaction();
			$price = parseAdminDecimal($values['price']);
			$duration = (int) $values['duration_minutes'];
			$slug = buildUniqueOfferSlug($pdo, $values['title'], $offerId !== null && $offerId !== false ? (int) $offerId : null);
			$oldOfferImagePath = null;

			if ($action === 'create_offer') {
				$statement = $pdo->prepare(
					'INSERT INTO offers (title, slug, description, price, duration_minutes, start_date, end_date, active)
					 VALUES (:title, :slug, :description, :price, :duration_minutes, :start_date, :end_date, :active)'
				);
				$statement->execute([
					'title' => $values['title'],
					'slug' => $slug,
					'description' => $values['description'] !== '' ? $values['description'] : null,
					'price' => number_format((float) $price, 2, '.', ''),
					'duration_minutes' => $duration,
					'start_date' => $values['start_date'],
					'end_date' => $values['end_date'],
					'active' => (int) $values['active'],
				]);
				$offerId = (int) $pdo->lastInsertId();
			} else {
				$existingOffer = getOfferById($pdo, (int) $offerId, true);

				if ($existingOffer === null) {
					$pdo->rollBack();
					$errors[] = 'Oferta nu a fost gasita.';
				} else {
					$oldOfferImagePath = $existingOffer['image_path'] !== null ? (string) $existingOffer['image_path'] : null;
					$statement = $pdo->prepare(
						'UPDATE offers
						 SET title = :title,
							slug = :slug,
							description = :description,
							price = :price,
							duration_minutes = :duration_minutes,
							start_date = :start_date,
							end_date = :end_date,
							active = :active,
							updated_at = CURRENT_TIMESTAMP
						 WHERE id = :offer_id'
					);
					$statement->execute([
						'title' => $values['title'],
						'slug' => $slug,
						'description' => $values['description'] !== '' ? $values['description'] : null,
						'price' => number_format((float) $price, 2, '.', ''),
						'duration_minutes' => $duration,
						'start_date' => $values['start_date'],
						'end_date' => $values['end_date'],
						'active' => (int) $values['active'],
						'offer_id' => (int) $offerId,
					]);
				}
			}

			if ($errors === []) {
				if (hasOfferImageUpload()) {
					$savedOfferImagePath = saveOfferCoverImage((int) $offerId, $maxOfferUploadBytes, $allowedOfferMimeTypes);
					$imageStatement = $pdo->prepare(
						'UPDATE offers
						 SET image_path = :image_path,
							updated_at = CURRENT_TIMESTAMP
						 WHERE id = :offer_id'
					);
					$imageStatement->execute([
						'image_path' => $savedOfferImagePath,
						'offer_id' => (int) $offerId,
					]);
					$imageToDeleteAfterCommit = $oldOfferImagePath;
				}

				$deleteServices = $pdo->prepare('DELETE FROM offer_services WHERE offer_id = :offer_id');
				$deleteServices->execute(['offer_id' => (int) $offerId]);
				$insertService = $pdo->prepare(
					'INSERT INTO offer_services (offer_id, service_id)
					 VALUES (:offer_id, :service_id)'
				);

				foreach ($serviceIds as $serviceId) {
					$insertService->execute([
						'offer_id' => (int) $offerId,
						'service_id' => $serviceId,
					]);
				}

				$deleteSpecialists = $pdo->prepare('DELETE FROM offer_specialists WHERE offer_id = :offer_id');
				$deleteSpecialists->execute(['offer_id' => (int) $offerId]);
				$insertSpecialist = $pdo->prepare(
					'INSERT INTO offer_specialists (offer_id, specialist_id)
					 VALUES (:offer_id, :specialist_id)'
				);

				foreach ($specialistIds as $specialistId) {
					$insertSpecialist->execute([
						'offer_id' => (int) $offerId,
						'specialist_id' => $specialistId,
					]);
				}

				$pdo->commit();
				deleteOfferImageFile($imageToDeleteAfterCommit);
				$message = $action === 'create_offer' ? 'Oferta a fost creata.' : 'Oferta a fost actualizata.';
			}
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			deleteOfferImageFile($savedOfferImagePath);

			error_log('Farmecul Tau admin offer save failed: ' . $exception->getMessage());
			$errors[] = 'Oferta nu a putut fi salvata.';
		}
	}
}

$offerStatement = $pdo->prepare(
	'SELECT id, title, slug, description, image_path, price, duration_minutes, start_date, end_date, active, created_at, updated_at
	 FROM offers
	 ORDER BY active DESC, start_date DESC, created_at DESC'
);
$offerStatement->execute();
$offers = $offerStatement->fetchAll();
$offerIds = array_map('intval', array_column($offers, 'id'));
$servicesByOffer = loadOfferServicesByOffer($pdo, $offerIds);
$specialistsByOffer = loadOfferSpecialistsByOffer($pdo, $offerIds);
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Oferte | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260831-1">
</head>
<body>
	<?php renderAdminHeader('Oferte', 'offers.php', $csrfToken, $dashboardUser, $currentSpecialist); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">OFERTE SALON</p>
					<h2 class="admin-section-title">Oferte bookable</h2>
				</div>
			</div>

			<?php if ($message !== ''): ?>
				<p class="admin-alert admin-alert-success"><?php echo adminEscape($message); ?></p>
			<?php endif; ?>
			<?php if ($errors !== []): ?>
				<div class="admin-alert admin-alert-error">
					<?php foreach ($errors as $formError): ?>
						<p><?php echo adminEscape($formError); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ($offers === []): ?>
				<p class="admin-empty">Nu exista oferte create.</p>
			<?php else: ?>
				<div class="admin-table-wrap">
					<table class="admin-table">
						<thead>
							<tr>
								<th>Titlu</th>
								<th>Pret</th>
								<th>Durata</th>
								<th>Valabilitate</th>
								<th>Imagine</th>
								<th>Servicii</th>
								<th>Specialisti</th>
								<th>Status</th>
								<th>Actiuni</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($offers as $offer): ?>
								<?php
									$offerId = (int) $offer['id'];
									$offerServiceIds = array_map('intval', array_column($servicesByOffer[$offerId] ?? [], 'id'));
									$offerSpecialistIds = array_map('intval', array_column($specialistsByOffer[$offerId] ?? [], 'id'));
									$editPanelId = 'edit-offer-' . $offerId;
								?>
								<tr>
									<td data-label="Titlu"><?php echo adminEscape((string) $offer['title']); ?></td>
									<td data-label="Pret"><?php echo adminEscape(number_format((float) $offer['price'], 2, '.', '') . ' lei'); ?></td>
									<td data-label="Durata"><?php echo (int) $offer['duration_minutes']; ?> min</td>
									<td data-label="Valabilitate">
										<?php echo adminEscape(adminFormatDate((string) $offer['start_date'], 'd.m.Y')); ?>
										-
										<?php echo adminEscape(adminFormatDate((string) $offer['end_date'], 'd.m.Y')); ?>
									</td>
									<td data-label="Imagine"><?php echo $offer['image_path'] !== null ? 'Setata' : '-'; ?></td>
									<td data-label="Servicii"><?php echo adminEscape(implode(', ', array_column($servicesByOffer[$offerId] ?? [], 'name')) ?: '-'); ?></td>
									<td data-label="Specialisti"><?php echo adminEscape(implode(', ', array_column($specialistsByOffer[$offerId] ?? [], 'name')) ?: '-'); ?></td>
									<td data-label="Status">
										<span class="admin-status admin-status-<?php echo (int) $offer['active'] === 1 ? 'approved' : 'cancelled'; ?>">
											<?php echo (int) $offer['active'] === 1 ? 'ACTIVA' : 'INACTIVA'; ?>
										</span>
									</td>
									<td data-label="Actiuni">
										<button class="admin-small-button" type="button" data-toggle-panel="<?php echo adminEscape($editPanelId); ?>">EDITEAZA</button>
									</td>
								</tr>
								<tr>
									<td colspan="9">
										<form class="admin-form admin-form-grid" id="<?php echo adminEscape($editPanelId); ?>" method="post" action="offers.php" enctype="multipart/form-data" data-offer-form hidden>
											<label>
												<span>Titlu</span>
												<input type="text" name="title" maxlength="180" value="<?php echo adminEscape((string) $offer['title']); ?>" required>
											</label>
											<label>
												<span>Pret oferta</span>
												<input type="number" name="price" min="0" max="99999.99" step="0.01" value="<?php echo adminEscape((string) $offer['price']); ?>" required>
											</label>
											<label>
												<span>Durata totala</span>
												<input type="number" name="duration_minutes" min="5" max="480" step="5" value="<?php echo (int) $offer['duration_minutes']; ?>" required>
											</label>
											<label>
												<span>Data inceput</span>
												<input type="date" name="start_date" value="<?php echo adminEscape((string) $offer['start_date']); ?>" required>
											</label>
											<label>
												<span>Data sfarsit</span>
												<input type="date" name="end_date" value="<?php echo adminEscape((string) $offer['end_date']); ?>" required>
											</label>
											<label class="admin-checkbox-label">
												<input type="checkbox" name="active" value="1" <?php echo (int) $offer['active'] === 1 ? 'checked' : ''; ?>>
												<span>Activa</span>
											</label>
											<label class="admin-form-wide">
												<span>Descriere</span>
												<textarea name="description" maxlength="3000" rows="3"><?php echo adminEscape((string) ($offer['description'] ?? '')); ?></textarea>
											</label>
											<label class="admin-form-wide">
												<span>Imagine oferta</span>
												<input type="file" name="offer_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
											</label>
											<fieldset class="admin-form-wide" data-offer-services>
												<legend>Servicii incluse</legend>
												<div class="admin-checkbox-grid">
													<?php foreach ($services as $service): ?>
														<label class="admin-checkbox-label">
															<input type="checkbox" name="service_ids[]" value="<?php echo (int) $service['id']; ?>" data-offer-service-checkbox <?php echo in_array((int) $service['id'], $offerServiceIds, true) ? 'checked' : ''; ?>>
															<span><?php echo adminEscape((string) $service['name']); ?></span>
														</label>
													<?php endforeach; ?>
												</div>
											</fieldset>
											<fieldset class="admin-form-wide" data-offer-specialists-fieldset data-selected-specialist-ids="<?php echo adminEscape(json_encode($offerSpecialistIds)); ?>">
												<legend>Specialisti disponibili</legend>
												<p class="admin-form-message" data-offer-specialists-message>Selecteaza mai intai serviciile incluse in oferta.</p>
												<div class="admin-checkbox-grid" data-offer-specialists-list></div>
											</fieldset>
											<input type="hidden" name="action" value="update_offer">
											<input type="hidden" name="offer_id" value="<?php echo $offerId; ?>">
											<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
											<div class="admin-inline-actions admin-form-wide">
												<button class="admin-button" type="submit">Salveaza oferta</button>
												<button class="admin-reset-link" type="button" data-close-panel="<?php echo adminEscape($editPanelId); ?>">Anuleaza</button>
											</div>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<div class="admin-add-service-toggle">
				<button class="admin-button" type="button" data-toggle-panel="add-offer-panel">+ ADAUGA OFERTA</button>
			</div>
			<form class="admin-form admin-form-grid" id="add-offer-panel" method="post" action="offers.php" enctype="multipart/form-data" data-offer-form hidden>
				<label>
					<span>Titlu</span>
					<input type="text" name="title" maxlength="180" required>
				</label>
				<label>
					<span>Pret oferta</span>
					<input type="number" name="price" min="0" max="99999.99" step="0.01" required>
				</label>
				<label>
					<span>Durata totala</span>
					<input type="number" name="duration_minutes" min="5" max="480" step="5" required>
				</label>
				<label>
					<span>Data inceput</span>
					<input type="date" name="start_date" required>
				</label>
				<label>
					<span>Data sfarsit</span>
					<input type="date" name="end_date" required>
				</label>
				<label class="admin-checkbox-label">
					<input type="checkbox" name="active" value="1" checked>
					<span>Activa</span>
				</label>
				<label class="admin-form-wide">
					<span>Descriere</span>
					<textarea name="description" maxlength="3000" rows="3"></textarea>
				</label>
				<label class="admin-form-wide">
					<span>Imagine oferta</span>
					<input type="file" name="offer_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
				</label>
				<fieldset class="admin-form-wide" data-offer-services>
					<legend>Servicii incluse</legend>
					<div class="admin-checkbox-grid">
						<?php foreach ($services as $service): ?>
							<label class="admin-checkbox-label">
								<input type="checkbox" name="service_ids[]" value="<?php echo (int) $service['id']; ?>" data-offer-service-checkbox>
								<span><?php echo adminEscape((string) $service['name']); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</fieldset>
				<fieldset class="admin-form-wide" data-offer-specialists-fieldset data-selected-specialist-ids="[]">
					<legend>Specialisti disponibili</legend>
					<p class="admin-form-message" data-offer-specialists-message>Selecteaza mai intai serviciile incluse in oferta.</p>
					<div class="admin-checkbox-grid" data-offer-specialists-list></div>
				</fieldset>
				<input type="hidden" name="action" value="create_offer">
				<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
				<div class="admin-inline-actions admin-form-wide">
					<button class="admin-button" type="submit">Creeaza oferta</button>
					<button class="admin-reset-link" type="button" data-close-panel="add-offer-panel">Anuleaza</button>
				</div>
			</form>
		</section>
	</main>

	<script src="../js/admin-services.js?v=20260828-1"></script>
	<script src="../js/admin-offers.js?v=20260831-1"></script>
</body>
</html>
