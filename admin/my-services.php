<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/booking.php';

setSalonTimezone();
$dashboardUser = requireDashboardUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);
$csrfToken = getAdminCsrfToken();
$message = '';
$errors = [];
$category = $currentSpecialist !== null ? getServiceCategoryForSpecialization($currentSpecialist['specialization'] ?? null) : null;

if ($currentSpecialist === null) {
	$errors[] = 'Contul tau nu este legat de un specialist activ.';
} elseif ($category === null) {
	$errors[] = 'Specializarea contului tau nu are o categorie de servicii configurata.';
}

if ($currentSpecialist !== null && $category !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = isset($_POST['action']) ? (string) $_POST['action'] : '';

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
	}

	if ($action === 'update_service') {
		$serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1],
		]);
		$priceInput = isset($_POST['price']) ? trim((string) $_POST['price']) : '';
		$durationInput = isset($_POST['duration_minutes']) ? trim((string) $_POST['duration_minutes']) : '';
		$isActive = isset($_POST['active']) ? 1 : 0;
		$price = parseAdminDecimal($priceInput);
		$duration = filter_var($durationInput, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 5, 'max_range' => 480],
		]);

		if ($serviceId === false) {
			$errors[] = 'Serviciul nu a putut fi identificat.';
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
				$ownershipStatement = $pdo->prepare(
					'SELECT ss.service_id
					 FROM specialist_services ss
					 INNER JOIN services sv ON sv.id = ss.service_id
					 WHERE ss.specialist_id = :specialist_id
						AND ss.service_id = :service_id
						AND sv.category = :category
					 LIMIT 1
					 FOR UPDATE'
				);
				$ownershipStatement->execute([
					'specialist_id' => (int) $currentSpecialist['id'],
					'service_id' => $serviceId,
					'category' => $category,
				]);

				if ($ownershipStatement->fetch() === false) {
					$pdo->rollBack();
					$errors[] = 'Nu poti modifica acest serviciu.';
				} else {
					$updateStatement = $pdo->prepare(
						'UPDATE specialist_services
						 SET price = :price,
							duration_minutes = :duration_minutes,
							active = :active
						 WHERE specialist_id = :specialist_id
							AND service_id = :service_id'
					);
					$updateStatement->execute([
						'price' => number_format((float) $price, 2, '.', ''),
						'duration_minutes' => (int) $duration,
						'active' => $isActive,
						'specialist_id' => (int) $currentSpecialist['id'],
						'service_id' => $serviceId,
					]);
					$pdo->commit();
					$message = 'Serviciul a fost actualizat.';
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
		$priceInput = isset($_POST['new_price']) ? trim((string) $_POST['new_price']) : '';
		$durationInput = isset($_POST['new_duration_minutes']) ? trim((string) $_POST['new_duration_minutes']) : '';
		$price = parseAdminDecimal($priceInput);
		$duration = filter_var($durationInput, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 5, 'max_range' => 480],
		]);

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
					$insertServiceStatement = $pdo->prepare(
						'INSERT INTO services (name, description, category, duration_minutes, price, active)
						 VALUES (:name, :description, :category, :duration_minutes, :price, 1)'
					);
					$insertServiceStatement->execute([
						'name' => $name,
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
					'specialist_id' => (int) $currentSpecialist['id'],
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
						'specialist_id' => (int) $currentSpecialist['id'],
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
	} elseif ($errors === []) {
		$errors[] = 'Actiunea nu este valida.';
	}
}

$services = [];

if ($currentSpecialist !== null) {
	$serviceStatement = $pdo->prepare(
		'SELECT
			sv.id,
			sv.name,
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
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Serviciile mele | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260827-4">
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
				<form class="admin-form admin-form-grid" method="post" action="my-services.php">
					<h3 class="admin-form-wide">+ ADAUGA SERVICIU</h3>
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
					<button class="admin-button admin-form-wide" type="submit">+ ADAUGA SERVICIU</button>
				</form>

				<?php if ($services === []): ?>
					<p class="admin-empty">Nu ai servicii asignate inca.</p>
				<?php else: ?>
					<div class="admin-table-wrap">
						<table class="admin-table">
							<thead>
								<tr>
									<th>Serviciu</th>
									<th>Categorie</th>
									<th>Pret</th>
									<th>Durata</th>
									<th>Activ</th>
									<th>Actiune</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($services as $service): ?>
									<?php $rowFormId = 'service-form-' . (int) $service['id']; ?>
									<tr>
										<td data-label="Serviciu">
											<strong><?php echo adminEscape((string) $service['name']); ?></strong>
										</td>
										<td data-label="Categorie"><?php echo adminEscape((string) $service['category']); ?></td>
										<td data-label="Pret">
											<input form="<?php echo adminEscape($rowFormId); ?>" type="number" name="price" min="0" max="99999.99" step="0.01" value="<?php echo adminEscape($service['price'] !== null ? (string) $service['price'] : ''); ?>" required>
										</td>
										<td data-label="Durata">
											<input form="<?php echo adminEscape($rowFormId); ?>" type="number" name="duration_minutes" min="5" max="480" step="5" value="<?php echo adminEscape($service['duration_minutes'] !== null ? (string) $service['duration_minutes'] : ''); ?>" required>
										</td>
										<td data-label="Activ">
											<label class="admin-checkbox-label">
												<input form="<?php echo adminEscape($rowFormId); ?>" type="checkbox" name="active" value="1" <?php echo (int) $service['active'] === 1 ? 'checked' : ''; ?>>
												<span>Activ</span>
											</label>
										</td>
										<td data-label="Actiune">
											<form id="<?php echo adminEscape($rowFormId); ?>" method="post" action="my-services.php">
												<input type="hidden" name="action" value="update_service">
												<input type="hidden" name="service_id" value="<?php echo (int) $service['id']; ?>">
												<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
												<button class="admin-small-button" type="submit">SALVEAZA</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
	</main>
</body>
</html>
