<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';

$dashboardUser = requireAdmin($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);
$csrfToken = getAdminCsrfToken();
$message = '';
$errors = [];
$allowedSpecializations = ['hairstylist', 'nails'];
$categoryBySpecialization = [
	'hairstylist' => 'hairstyle',
	'nails' => 'nails',
];

function ensureServiceCategoryColumn(PDO $pdo): void
{
	$columnStatement = $pdo->prepare(
		'SELECT COUNT(*) AS column_exists
		 FROM INFORMATION_SCHEMA.COLUMNS
		 WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = :table_name
			AND COLUMN_NAME = :column_name'
	);
	$columnStatement->execute([
		'table_name' => 'services',
		'column_name' => 'category',
	]);

	if ((int) ($columnStatement->fetch()['column_exists'] ?? 0) > 0) {
		return;
	}

	$pdo->exec("ALTER TABLE services ADD COLUMN category ENUM('hairstyle', 'nails') NULL AFTER description");
	$pdo->exec("UPDATE services SET category = 'hairstyle' WHERE name IN ('[DEV] Tuns', '[DEV] Coafat', '[DEV] Vopsit')");
	$pdo->exec("UPDATE services SET category = 'nails' WHERE id IN (4, 5) AND (category IS NULL OR category = '')");
}

ensureServiceCategoryColumn($pdo);
$values = [
	'name' => '',
	'email' => '',
	'specialization' => '',
	'service_ids' => [],
];

$serviceStatement = $pdo->prepare(
	'SELECT id, name, category
	 FROM services
	 WHERE active = 1
	 ORDER BY name ASC'
);
$serviceStatement->execute();
$services = $serviceStatement->fetchAll();
$activeServiceIds = array_map(static fn (array $service): int => (int) $service['id'], $services);
$serviceCategoriesById = [];

foreach ($services as $service) {
	$serviceCategoriesById[(int) $service['id']] = (string) $service['category'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedServiceIds = isset($_POST['service_ids']) && is_array($_POST['service_ids'])
		? $_POST['service_ids']
		: [];

	$values = [
		'name' => isset($_POST['name']) ? trim((string) $_POST['name']) : '',
		'email' => isset($_POST['email']) ? strtolower(trim((string) $_POST['email'])) : '',
		'specialization' => isset($_POST['specialization']) ? strtolower(trim((string) $_POST['specialization'])) : '',
		'service_ids' => [],
	];
	$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
	$passwordConfirmation = isset($_POST['password_confirmation']) ? (string) $_POST['password_confirmation'] : '';

	foreach ($postedServiceIds as $serviceIdValue) {
		$serviceId = filter_var($serviceIdValue, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1],
		]);

		if ($serviceId !== false) {
			$values['service_ids'][] = $serviceId;
		}
	}

	$values['service_ids'] = array_values(array_unique($values['service_ids']));

	if (!verifyAdminCsrfToken(isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : null)) {
		$errors[] = 'Sesiunea a expirat. Reincarca pagina si incearca din nou.';
	}

	if ($values['name'] === '' || strlen($values['name']) > 150) {
		$errors[] = 'Introdu numele specialistului.';
	}

	if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || strlen($values['email']) > 255) {
		$errors[] = 'Introdu o adresa de email valida.';
	}

	if (strlen($password) < 8) {
		$errors[] = 'Parola temporara trebuie sa aiba cel putin 8 caractere.';
	}

	if ($password !== $passwordConfirmation) {
		$errors[] = 'Confirmarea parolei nu se potriveste.';
	}

	if (!in_array($values['specialization'], $allowedSpecializations, true)) {
		$errors[] = 'Alege o specializare valida.';
	}

	$requiredServiceCategory = $categoryBySpecialization[$values['specialization']] ?? null;

	foreach ($values['service_ids'] as $serviceId) {
		if (
			!in_array($serviceId, $activeServiceIds, true)
			|| $requiredServiceCategory === null
			|| ($serviceCategoriesById[$serviceId] ?? null) !== $requiredServiceCategory
		) {
			$errors[] = 'Selectia de servicii nu este valida.';
			break;
		}
	}

	if ($errors === []) {
		try {
			$pdo->beginTransaction();

			$existingStatement = $pdo->prepare(
				'SELECT id
				 FROM users
				 WHERE email = :email
				 LIMIT 1
				 FOR UPDATE'
			);
			$existingStatement->execute(['email' => $values['email']]);

			if ($existingStatement->fetch() !== false) {
				$pdo->rollBack();
				$errors[] = 'Exista deja un cont cu aceasta adresa de email.';
			} else {
				$userStatement = $pdo->prepare(
					"INSERT INTO users (name, email, password_hash, role)
					 VALUES (:name, :email, :password_hash, 'specialist')"
				);
				$userStatement->execute([
					'name' => $values['name'],
					'email' => $values['email'],
					'password_hash' => password_hash($password, PASSWORD_DEFAULT),
				]);
				$userId = (int) $pdo->lastInsertId();

				$specialistStatement = $pdo->prepare(
					'INSERT INTO specialists (user_id, name, email, specialization, active)
					 VALUES (:user_id, :name, :email, :specialization, 1)'
				);
				$specialistStatement->execute([
					'user_id' => $userId,
					'name' => $values['name'],
					'email' => $values['email'],
					'specialization' => $values['specialization'],
				]);
				$specialistId = (int) $pdo->lastInsertId();

				if ($values['service_ids'] !== []) {
					$serviceInsertStatement = $pdo->prepare(
						'INSERT INTO specialist_services (specialist_id, service_id)
						 VALUES (:specialist_id, :service_id)'
					);

					foreach ($values['service_ids'] as $serviceId) {
						$serviceInsertStatement->execute([
							'specialist_id' => $specialistId,
							'service_id' => $serviceId,
						]);
					}
				}

				$pdo->commit();
				$message = 'Specialistul a fost adaugat.';
				$values = [
					'name' => '',
					'email' => '',
					'specialization' => '',
					'service_ids' => [],
				];
			}
		} catch (Throwable $exception) {
			if ($pdo->inTransaction()) {
				$pdo->rollBack();
			}

			error_log('Farmecul Tau add specialist failed: ' . $exception->getMessage());
			$errors[] = 'Specialistul nu a putut fi adaugat.';
		}
	}
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Adauga specialist | Farmecul Tau</title>
	<link rel="stylesheet" href="../css/style.css?v=20260827-3">
</head>
<body>
	<?php renderAdminHeader('Adauga specialist', 'add-specialist.php', $csrfToken, $dashboardUser, $currentSpecialist); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<div class="admin-panel-head">
				<div>
					<p class="admin-kicker">ADMIN</p>
					<h2 class="admin-section-title">Adauga specialist</h2>
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

			<form class="admin-form admin-form-grid" method="post" action="add-specialist.php" data-specialist-form>
				<label>
					<span>Nume</span>
					<input type="text" name="name" maxlength="150" value="<?php echo adminEscape($values['name']); ?>" required>
				</label>
				<label>
					<span>Email</span>
					<input type="email" name="email" maxlength="255" value="<?php echo adminEscape($values['email']); ?>" required>
				</label>
				<label>
					<span>Parola temporara</span>
					<input type="password" name="password" minlength="8" autocomplete="new-password" required>
				</label>
				<label>
					<span>Confirma parola</span>
					<input type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required>
				</label>
				<label>
					<span>Specializare</span>
					<select name="specialization" required data-specialization-select>
						<option value="">Alege specializarea</option>
						<?php foreach ($allowedSpecializations as $specialization): ?>
							<option value="<?php echo adminEscape($specialization); ?>" <?php echo $values['specialization'] === $specialization ? 'selected' : ''; ?>>
								<?php echo adminEscape($specialization); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<fieldset class="admin-form-wide" data-services-fieldset <?php echo $values['specialization'] === '' ? 'hidden' : ''; ?>>
					<legend>Servicii</legend>
					<?php if ($services === []): ?>
						<p class="admin-empty">Nu exista servicii active.</p>
					<?php else: ?>
						<div class="admin-checkbox-grid">
							<?php foreach ($services as $service): ?>
								<?php
									$serviceCategory = (string) $service['category'];
									$isCompatibleService = $values['specialization'] !== ''
										&& ($categoryBySpecialization[$values['specialization']] ?? null) === $serviceCategory;
								?>
								<label class="admin-checkbox-label" data-service-category="<?php echo adminEscape($serviceCategory); ?>" <?php echo $isCompatibleService ? '' : 'hidden'; ?>>
									<input
										type="checkbox"
										name="service_ids[]"
										value="<?php echo (int) $service['id']; ?>"
										data-service-checkbox
										<?php echo $isCompatibleService && in_array((int) $service['id'], $values['service_ids'], true) ? 'checked' : ''; ?>
									>
									<span><?php echo adminEscape((string) $service['name']); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</fieldset>
				<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
				<button class="admin-button admin-form-wide" type="submit">Adauga specialistul</button>
			</form>
		</section>
	</main>
	<script>
		const specialistForm = document.querySelector('[data-specialist-form]');

		if (specialistForm) {
			const categoryBySpecialization = {
				hairstylist: 'hairstyle',
				nails: 'nails',
			};
			const specializationSelect = specialistForm.querySelector('[data-specialization-select]');
			const servicesFieldset = specialistForm.querySelector('[data-services-fieldset]');
			const serviceLabels = Array.from(specialistForm.querySelectorAll('[data-service-category]'));

			const filterServices = () => {
				const requiredCategory = categoryBySpecialization[specializationSelect.value] || '';

				if (servicesFieldset) {
					servicesFieldset.hidden = requiredCategory === '';
				}

				serviceLabels.forEach((label) => {
					const checkbox = label.querySelector('[data-service-checkbox]');
					const isVisible = requiredCategory !== '' && label.dataset.serviceCategory === requiredCategory;

					label.hidden = !isVisible;

					if (!isVisible && checkbox) {
						checkbox.checked = false;
					}
				});
			};

			specializationSelect.addEventListener('change', filterServices);
			filterServices();
		}
	</script>
</body>
</html>
