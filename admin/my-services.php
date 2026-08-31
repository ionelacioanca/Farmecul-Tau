<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/service-helpers.php';

setSalonTimezone();
ensureAllServiceSlugs($pdo);

$dashboardUser = requireDashboardUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);
$csrfToken = getAdminCsrfToken();
$message = '';
$errors = [];
$category = $currentSpecialist !== null ? getServiceCategoryForSpecialization($currentSpecialist['specialization'] ?? null) : null;
$maxUploadBytes = 5 * 1024 * 1024;
$allowedMimeTypes = [
	'image/jpeg' => 'jpg',
	'image/png' => 'png',
	'image/webp' => 'webp',
];

function specialistOwnsService(PDO $pdo, int $specialistId, int $serviceId, string $category, bool $lock = false): bool
{
	$statement = $pdo->prepare(
		'SELECT ss.service_id
		 FROM specialist_services ss
		 INNER JOIN services sv ON sv.id = ss.service_id
		 WHERE ss.specialist_id = :specialist_id
			AND ss.service_id = :service_id
			AND sv.category = :category
		 LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
	);
	$statement->execute([
		'specialist_id' => $specialistId,
		'service_id' => $serviceId,
		'category' => $category,
	]);

	return $statement->fetch() !== false;
}

function ensureServiceUploadDirectory(int $specialistId, int $serviceId): string
{
	$directory = dirname(__DIR__) . '/uploads/services/' . $specialistId . '/' . $serviceId;

	if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
		throw new RuntimeException('Upload directory could not be created.');
	}

	return $directory;
}

function deleteSpecialistServiceImageFile(string $relativePath): void
{
	$baseDirectory = realpath(dirname(__DIR__) . '/uploads/services');
	$fullPath = realpath(dirname(__DIR__) . '/' . ltrim($relativePath, '/\\'));

	if ($baseDirectory === false || $fullPath === false || !str_starts_with($fullPath, $baseDirectory)) {
		return;
	}

	if (is_file($fullPath)) {
		unlink($fullPath);
	}
}

if ($currentSpecialist === null) {
	$errors[] = 'Contul tau nu este legat de un specialist activ.';
} elseif ($category === null) {
	$errors[] = 'Specializarea contului tau nu are o categorie de servicii configurata.';
}

if ($currentSpecialist !== null && $category !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
	$specialistId = (int) $currentSpecialist['id'];

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
	}

	if ($action === 'update_service') {
		$serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		$serviceName = isset($_POST['service_name']) ? trim((string) $_POST['service_name']) : '';
		$price = parseAdminDecimal(isset($_POST['price']) ? trim((string) $_POST['price']) : '');
		$duration = filter_var($_POST['duration_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 480]]);
		$isActive = isset($_POST['active']) ? 1 : 0;

		if ($serviceId === false || !specialistOwnsService($pdo, $specialistId, (int) $serviceId, $category)) {
			$errors[] = 'Nu poti modifica acest serviciu.';
		}

		if ($serviceName === '' || strlen($serviceName) > 150) {
			$errors[] = 'Introdu numele serviciului.';
		}

		if ($price === null || $price < 0 || $price > 99999.99) {
			$errors[] = 'Pretul trebuie sa fie intre 0 si 99999.99.';
		}

		if ($duration === false) {
			$errors[] = 'Durata trebuie sa fie intre 5 si 480 minute.';
		}

		if ($errors === []) {
			try {
				$pdo->beginTransaction();

				if (!specialistOwnsService($pdo, $specialistId, (int) $serviceId, $category, true)) {
					$pdo->rollBack();
					$errors[] = 'Nu poti modifica acest serviciu.';
				} else {
					$existingStatement = $pdo->prepare(
						'SELECT id, name
						 FROM services
						 WHERE category = :category
							AND active = 1
							AND id <> :service_id
						 FOR UPDATE'
					);
					$existingStatement->execute([
						'category' => $category,
						'service_id' => (int) $serviceId,
					]);
					$normalizedName = normalizeServiceName($serviceName);
					$duplicateName = false;

					foreach ($existingStatement->fetchAll() as $service) {
						if (normalizeServiceName((string) $service['name']) === $normalizedName) {
							$duplicateName = true;
							break;
						}
					}

					if ($duplicateName) {
						$pdo->rollBack();
						$errors[] = 'Exista deja un serviciu activ cu acest nume in categoria ta.';
					} else {
						$slug = buildUniqueServiceSlug($pdo, $serviceName, (int) $serviceId);
						$serviceStatement = $pdo->prepare(
							'UPDATE services
							 SET name = :name,
								slug = :slug
							 WHERE id = :service_id
								AND category = :category'
						);
						$serviceStatement->execute([
							'name' => $serviceName,
							'slug' => $slug,
							'service_id' => (int) $serviceId,
							'category' => $category,
						]);

						$statement = $pdo->prepare(
							'UPDATE specialist_services
							 SET price = :price,
								duration_minutes = :duration_minutes,
								active = :active
							 WHERE specialist_id = :specialist_id
								AND service_id = :service_id'
						);
						$statement->execute([
							'price' => number_format((float) $price, 2, '.', ''),
							'duration_minutes' => (int) $duration,
							'active' => $isActive,
							'specialist_id' => $specialistId,
							'service_id' => (int) $serviceId,
						]);
						$pdo->commit();
						$message = 'Serviciul a fost actualizat.';
					}
				}
			} catch (Throwable $exception) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}

				error_log('Farmecul Tau my service update failed: ' . $exception->getMessage());
				$errors[] = 'Serviciul nu a putut fi actualizat.';
			}
		}
	} elseif ($action === 'create_service') {
		$name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
		$description = isset($_POST['description']) ? trim((string) $_POST['description']) : '';
		$price = parseAdminDecimal(isset($_POST['new_price']) ? trim((string) $_POST['new_price']) : '');
		$duration = filter_var($_POST['new_duration_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 480]]);

		if ($name === '' || strlen($name) > 150) {
			$errors[] = 'Introdu numele serviciului.';
		}

		if (strlen($description) > 1000) {
			$errors[] = 'Descrierea poate avea maximum 1000 de caractere.';
		}

		if ($price === null || $price < 0 || $price > 99999.99) {
			$errors[] = 'Pretul trebuie sa fie intre 0 si 99999.99.';
		}

		if ($duration === false) {
			$errors[] = 'Durata trebuie sa fie intre 5 si 480 minute.';
		}

		if ($errors === []) {
			try {
				$pdo->beginTransaction();
				$existingStatement = $pdo->prepare(
					'SELECT id, name
					 FROM services
					 WHERE category = :category
						AND active = 1
					 FOR UPDATE'
				);
				$existingStatement->execute(['category' => $category]);
				$normalizedName = normalizeServiceName($name);
				$serviceId = null;

				foreach ($existingStatement->fetchAll() as $service) {
					if (normalizeServiceName((string) $service['name']) === $normalizedName) {
						$serviceId = (int) $service['id'];
						break;
					}
				}

				if ($serviceId === null) {
					$slug = buildUniqueServiceSlug($pdo, $name);
					$insertServiceStatement = $pdo->prepare(
						'INSERT INTO services (name, slug, description, category, duration_minutes, price, active)
						 VALUES (:name, :slug, :description, :category, :duration_minutes, :price, 1)'
					);
					$insertServiceStatement->execute([
						'name' => $name,
						'slug' => $slug,
						'description' => $description !== '' ? $description : null,
						'category' => $category,
						'duration_minutes' => (int) $duration,
						'price' => number_format((float) $price, 2, '.', ''),
					]);
					$serviceId = (int) $pdo->lastInsertId();
				}

				$linkStatement = $pdo->prepare(
					'SELECT service_id
					 FROM specialist_services
					 WHERE specialist_id = :specialist_id
						AND service_id = :service_id
					 LIMIT 1
					 FOR UPDATE'
				);
				$linkStatement->execute([
					'specialist_id' => $specialistId,
					'service_id' => $serviceId,
				]);

				if ($linkStatement->fetch() !== false) {
					$pdo->rollBack();
					$errors[] = 'Serviciul exista deja in lista ta.';
				} else {
					$insertLinkStatement = $pdo->prepare(
						'INSERT INTO specialist_services (specialist_id, service_id, price, duration_minutes, active)
						 VALUES (:specialist_id, :service_id, :price, :duration_minutes, 1)'
					);
					$insertLinkStatement->execute([
						'specialist_id' => $specialistId,
						'service_id' => $serviceId,
						'price' => number_format((float) $price, 2, '.', ''),
						'duration_minutes' => (int) $duration,
					]);
					$pdo->commit();
					$message = 'Serviciul a fost adaugat in lista ta.';
				}
			} catch (Throwable $exception) {
				if ($pdo->inTransaction()) {
					$pdo->rollBack();
				}

				error_log('Farmecul Tau my service create failed: ' . $exception->getMessage());
				$errors[] = 'Serviciul nu a putut fi adaugat.';
			}
		}
	} elseif ($action === 'upload_images') {
		$serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		$altText = isset($_POST['alt_text']) ? trim((string) $_POST['alt_text']) : '';

		if ($serviceId === false || !specialistOwnsService($pdo, $specialistId, (int) $serviceId, $category)) {
			$errors[] = 'Nu poti incarca imagini pentru acest serviciu.';
		}

		if (strlen($altText) > 255) {
			$errors[] = 'Textul alternativ poate avea maximum 255 de caractere.';
		}

		$uploadedFiles = $_FILES['images'] ?? null;

		if (!is_array($uploadedFiles) || !isset($uploadedFiles['name']) || !is_array($uploadedFiles['name'])) {
			$errors[] = 'Alege cel putin o imagine.';
		}

		if ($errors === []) {
			try {
				$directory = ensureServiceUploadDirectory($specialistId, (int) $serviceId);
				$finfo = new finfo(FILEINFO_MIME_TYPE);
				$orderStatement = $pdo->prepare(
					'SELECT COALESCE(MAX(sort_order), 0) AS max_sort_order
					 FROM specialist_service_images
					 WHERE specialist_id = :specialist_id AND service_id = :service_id'
				);
				$orderStatement->execute([
					'specialist_id' => $specialistId,
					'service_id' => (int) $serviceId,
				]);
				$sortOrder = (int) ($orderStatement->fetch()['max_sort_order'] ?? 0);
				$insertStatement = $pdo->prepare(
					'INSERT INTO specialist_service_images (specialist_id, service_id, image_path, alt_text, sort_order, active)
					 VALUES (:specialist_id, :service_id, :image_path, :alt_text, :sort_order, 1)'
				);
				$uploadedCount = 0;

				foreach ($uploadedFiles['name'] as $index => $_name) {
					$errorCode = (int) ($uploadedFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE);

					if ($errorCode === UPLOAD_ERR_NO_FILE) {
						continue;
					}

					if ($errorCode !== UPLOAD_ERR_OK) {
						$errors[] = 'Una dintre imagini nu a putut fi incarcata.';
						continue;
					}

					$tmpName = (string) ($uploadedFiles['tmp_name'][$index] ?? '');
					$fileSize = (int) ($uploadedFiles['size'][$index] ?? 0);

					if ($fileSize <= 0 || $fileSize > $maxUploadBytes || !is_uploaded_file($tmpName)) {
						$errors[] = 'Imaginea depaseste limita sau nu este valida.';
						continue;
					}

					$mimeType = $finfo->file($tmpName);
					$extension = is_string($mimeType) && isset($allowedMimeTypes[$mimeType]) ? $allowedMimeTypes[$mimeType] : null;

					if ($extension === null || getimagesize($tmpName) === false) {
						$errors[] = 'Format acceptat: jpg, jpeg, png sau webp.';
						continue;
					}

					$filename = bin2hex(random_bytes(16)) . '.' . $extension;
					$targetPath = $directory . '/' . $filename;

					if (!move_uploaded_file($tmpName, $targetPath)) {
						$errors[] = 'Imaginea nu a putut fi salvata.';
						continue;
					}

					$sortOrder++;
					$relativePath = 'uploads/services/' . $specialistId . '/' . (int) $serviceId . '/' . $filename;
					$insertStatement->execute([
						'specialist_id' => $specialistId,
						'service_id' => (int) $serviceId,
						'image_path' => $relativePath,
						'alt_text' => $altText !== '' ? $altText : null,
						'sort_order' => $sortOrder,
					]);
					$uploadedCount++;
				}

				if ($uploadedCount > 0 && $errors === []) {
					$message = $uploadedCount === 1 ? 'Imaginea a fost incarcata.' : 'Imaginile au fost incarcate.';
				} elseif ($uploadedCount === 0 && $errors === []) {
					$errors[] = 'Alege cel putin o imagine valida.';
				}
			} catch (Throwable $exception) {
				error_log('Farmecul Tau service image upload failed: ' . $exception->getMessage());
				$errors[] = 'Imaginile nu au putut fi incarcate.';
			}
		}
	} elseif ($action === 'delete_image') {
		$imageId = filter_var($_POST['image_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if ($imageId === false) {
			$errors[] = 'Imaginea nu a putut fi identificata.';
		}

		if ($errors === []) {
			$statement = $pdo->prepare(
				'SELECT image_path
				 FROM specialist_service_images
				 WHERE id = :image_id
					AND specialist_id = :specialist_id
				 LIMIT 1'
			);
			$statement->execute([
				'image_id' => (int) $imageId,
				'specialist_id' => $specialistId,
			]);
			$image = $statement->fetch();

			if ($image === false) {
				$errors[] = 'Nu poti sterge aceasta imagine.';
			} else {
				$deleteStatement = $pdo->prepare(
					'DELETE FROM specialist_service_images
					 WHERE id = :image_id
						AND specialist_id = :specialist_id'
				);
				$deleteStatement->execute([
					'image_id' => (int) $imageId,
					'specialist_id' => $specialistId,
				]);
				deleteSpecialistServiceImageFile((string) $image['image_path']);
				$message = 'Imaginea a fost stearsa.';
			}
		}
	} elseif ($action === 'update_gallery') {
		$imageIds = isset($_POST['image_ids']) && is_array($_POST['image_ids']) ? $_POST['image_ids'] : [];
		$sortOrders = isset($_POST['sort_order']) && is_array($_POST['sort_order']) ? $_POST['sort_order'] : [];
		$altTexts = isset($_POST['image_alt_text']) && is_array($_POST['image_alt_text']) ? $_POST['image_alt_text'] : [];
		$updateStatement = $pdo->prepare(
			'UPDATE specialist_service_images
			 SET sort_order = :sort_order,
				alt_text = :alt_text
			 WHERE id = :image_id
				AND specialist_id = :specialist_id'
		);

		foreach ($imageIds as $imageIdValue) {
			$imageId = filter_var($imageIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

			if ($imageId === false) {
				continue;
			}

			$sortOrder = filter_var($sortOrders[$imageIdValue] ?? 0, FILTER_VALIDATE_INT);
			$altText = isset($altTexts[$imageIdValue]) ? trim((string) $altTexts[$imageIdValue]) : '';

			if ($sortOrder === false || strlen($altText) > 255) {
				$errors[] = 'Ordinea sau textul alternativ nu este valid.';
				break;
			}

			$updateStatement->execute([
				'image_id' => $imageId,
				'specialist_id' => $specialistId,
				'sort_order' => (int) $sortOrder,
				'alt_text' => $altText !== '' ? $altText : null,
			]);
		}

		if ($errors === []) {
			$message = 'Galeria a fost actualizata.';
		}
	} elseif ($errors === []) {
		$errors[] = 'Actiunea nu este valida.';
	}
}

$services = [];
$imagesByService = [];

if ($currentSpecialist !== null) {
	$serviceStatement = $pdo->prepare(
		'SELECT
			sv.id,
			sv.name,
			sv.slug,
			sv.description,
			sv.category,
			ss.price,
			ss.duration_minutes,
			ss.active
		 FROM services sv
		 INNER JOIN specialist_services ss ON ss.service_id = sv.id
		 WHERE ss.specialist_id = :specialist_id
		 ORDER BY sv.name ASC'
	);
	$serviceStatement->execute(['specialist_id' => (int) $currentSpecialist['id']]);
	$services = $serviceStatement->fetchAll();

	if ($services !== []) {
		$imageStatement = $pdo->prepare(
			'SELECT id, service_id, image_path, alt_text, sort_order
			 FROM specialist_service_images
			 WHERE specialist_id = :specialist_id
			 ORDER BY service_id ASC, sort_order ASC, id ASC'
		);
		$imageStatement->execute(['specialist_id' => (int) $currentSpecialist['id']]);

		foreach ($imageStatement->fetchAll() as $image) {
			$imagesByService[(int) $image['service_id']][] = $image;
		}
	}
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Serviciile mele | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260828-3">
</head>
<body>
	<?php renderAdminHeader('Serviciile mele', 'my-services.php', $csrfToken, $dashboardUser, $currentSpecialist); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">CONFIGURARE</p>
					<h2 class="admin-section-title">Serviciile mele</h2>
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

			<?php if ($currentSpecialist !== null && $category !== null): ?>
				<?php if ($services === []): ?>
					<p class="admin-empty">Nu ai servicii asignate inca.</p>
				<?php else: ?>
					<div class="admin-service-grid">
						<?php foreach ($services as $service): ?>
							<?php
								$serviceId = (int) $service['id'];
								$editPanelId = 'edit-service-' . $serviceId;
								$galleryPanelId = 'gallery-service-' . $serviceId;
								$images = $imagesByService[$serviceId] ?? [];
							?>
							<article class="admin-service-card">
								<div class="admin-service-card-head">
									<div>
										<p class="admin-kicker"><?php echo adminEscape(serviceCategoryLabel((string) $service['category'])); ?></p>
										<h3><?php echo adminEscape((string) $service['name']); ?></h3>
									</div>
									<span class="admin-status admin-status-<?php echo (int) $service['active'] === 1 ? 'approved' : 'cancelled'; ?>">
										<?php echo (int) $service['active'] === 1 ? 'ACTIV' : 'INACTIV'; ?>
									</span>
								</div>
								<dl class="admin-service-meta">
									<div>
										<dt>Pret</dt>
										<dd><?php echo $service['price'] !== null ? adminEscape(number_format((float) $service['price'], 2, '.', '') . ' lei') : '-'; ?></dd>
									</div>
									<div>
										<dt>Durata</dt>
										<dd><?php echo $service['duration_minutes'] !== null ? (int) $service['duration_minutes'] . ' min' : '-'; ?></dd>
									</div>
									<div>
										<dt>Galerie</dt>
										<dd><?php echo count($images); ?> imagini</dd>
									</div>
								</dl>
								<div class="admin-inline-actions">
									<button class="admin-small-button" type="button" data-toggle-panel="<?php echo adminEscape($editPanelId); ?>">EDITEAZA</button>
									<button class="admin-small-button" type="button" data-toggle-panel="<?php echo adminEscape($galleryPanelId); ?>">GALERIE FOTO</button>
								</div>

								<div class="admin-service-panel" id="<?php echo adminEscape($editPanelId); ?>" hidden>
									<form class="admin-form admin-form-grid" method="post" action="my-services.php">
										<label class="admin-form-wide">
											<span>Nume serviciu</span>
											<input type="text" name="service_name" maxlength="150" value="<?php echo adminEscape((string) $service['name']); ?>" required>
										</label>
										<label>
											<span>Pret</span>
											<input type="number" name="price" min="0" max="99999.99" step="0.01" value="<?php echo adminEscape($service['price'] !== null ? (string) $service['price'] : ''); ?>" required>
										</label>
										<label>
											<span>Durata</span>
											<input type="number" name="duration_minutes" min="5" max="480" step="5" value="<?php echo adminEscape($service['duration_minutes'] !== null ? (string) $service['duration_minutes'] : ''); ?>" required>
										</label>
										<label class="admin-checkbox-label admin-form-wide">
											<input type="checkbox" name="active" value="1" <?php echo (int) $service['active'] === 1 ? 'checked' : ''; ?>>
											<span>Activ</span>
										</label>
										<input type="hidden" name="action" value="update_service">
										<input type="hidden" name="service_id" value="<?php echo $serviceId; ?>">
										<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
										<div class="admin-inline-actions admin-form-wide">
											<button class="admin-button" type="submit">Salveaza</button>
											<button class="admin-reset-link" type="button" data-close-panel="<?php echo adminEscape($editPanelId); ?>">Anuleaza</button>
										</div>
									</form>
								</div>

								<div class="admin-service-panel" id="<?php echo adminEscape($galleryPanelId); ?>" hidden>
									<?php if ($images === []): ?>
										<p class="admin-empty">Nu exista imagini in galerie.</p>
									<?php else: ?>
										<form method="post" action="my-services.php">
											<div class="admin-gallery-grid">
												<?php foreach ($images as $image): ?>
													<figure class="admin-gallery-item">
														<img src="../<?php echo adminEscape((string) $image['image_path']); ?>" alt="<?php echo adminEscape((string) ($image['alt_text'] ?? $service['name'])); ?>">
														<label>
															<span>Alt text</span>
															<input type="text" name="image_alt_text[<?php echo (int) $image['id']; ?>]" maxlength="255" value="<?php echo adminEscape((string) ($image['alt_text'] ?? '')); ?>">
														</label>
														<label>
															<span>Ordine</span>
															<input type="number" name="sort_order[<?php echo (int) $image['id']; ?>]" value="<?php echo (int) $image['sort_order']; ?>">
														</label>
														<input type="hidden" name="image_ids[]" value="<?php echo (int) $image['id']; ?>">
														<button class="admin-small-button admin-danger-button" type="submit" form="delete-image-<?php echo (int) $image['id']; ?>" onclick="return confirm('Stergi aceasta imagine?');">STERGE</button>
													</figure>
												<?php endforeach; ?>
											</div>
											<input type="hidden" name="action" value="update_gallery">
											<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
											<button class="admin-button" type="submit">Salveaza galeria</button>
										</form>
										<?php foreach ($images as $image): ?>
											<form id="delete-image-<?php echo (int) $image['id']; ?>" method="post" action="my-services.php">
												<input type="hidden" name="action" value="delete_image">
												<input type="hidden" name="image_id" value="<?php echo (int) $image['id']; ?>">
												<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
											</form>
										<?php endforeach; ?>
									<?php endif; ?>

									<button class="admin-small-button" type="button" data-toggle-panel="upload-service-<?php echo $serviceId; ?>">+ ADAUGA IMAGINI</button>
									<form class="admin-form admin-form-grid" id="upload-service-<?php echo $serviceId; ?>" method="post" action="my-services.php" enctype="multipart/form-data" hidden>
										<label class="admin-form-wide">
											<span>Imagini</span>
											<input type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" multiple required>
										</label>
										<label class="admin-form-wide">
											<span>Alt text optional</span>
											<input type="text" name="alt_text" maxlength="255">
										</label>
										<input type="hidden" name="action" value="upload_images">
										<input type="hidden" name="service_id" value="<?php echo $serviceId; ?>">
										<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
										<div class="admin-inline-actions admin-form-wide">
											<button class="admin-button" type="submit">Incarca imagini</button>
											<button class="admin-reset-link" type="button" data-close-panel="upload-service-<?php echo $serviceId; ?>">Anuleaza</button>
										</div>
									</form>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="admin-add-service-toggle">
					<button class="admin-button" type="button" data-toggle-panel="add-service-panel">+ ADAUGA SERVICIU</button>
				</div>
				<form class="admin-form admin-form-grid" id="add-service-panel" method="post" action="my-services.php" hidden>
					<label>
						<span>Nume serviciu</span>
						<input type="text" name="name" maxlength="150" required>
					</label>
					<label>
						<span>Pret</span>
						<input type="number" name="new_price" min="0" max="99999.99" step="0.01" required>
					</label>
					<label>
						<span>Durata estimata in minute</span>
						<input type="number" name="new_duration_minutes" min="5" max="480" step="5" required>
					</label>
					<label class="admin-form-wide">
						<span>Descriere</span>
						<textarea name="description" maxlength="1000" rows="3"></textarea>
					</label>
					<input type="hidden" name="action" value="create_service">
					<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
					<div class="admin-inline-actions admin-form-wide">
						<button class="admin-button" type="submit">Adauga serviciu</button>
						<button class="admin-reset-link" type="button" data-close-panel="add-service-panel">Anuleaza</button>
					</div>
				</form>
			<?php endif; ?>
		</section>
	</main>
	<script src="../js/admin-services.js?v=20260828-1"></script>
</body>
</html>
