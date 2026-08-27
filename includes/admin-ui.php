<?php
declare(strict_types=1);

function adminEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function adminFormatDate(?string $dateValue, string $format = 'd.m.Y H:i'): string
{
	if ($dateValue === null || $dateValue === '') {
		return '-';
	}

	try {
		$date = new DateTimeImmutable($dateValue);
	} catch (Exception $exception) {
		return $dateValue;
	}

	return $date->format($format);
}

function adminFormatStatus(string $status): string
{
	return match ($status) {
		'active' => 'ACTIVE',
		'used' => 'USED',
		'expired' => 'EXPIRED',
		'pending' => 'IN ASTEPTARE',
		'approved' => 'APROBATA',
		'rejected' => 'RESPINSA',
		'cancelled' => 'ANULATA',
		default => strtoupper($status),
	};
}

function renderAdminHeader(string $title, string $activeRoute, string $csrfToken, ?array $dashboardUser = null, ?array $currentSpecialist = null): void
{
	if ($dashboardUser === null && isset($GLOBALS['dashboardUser']) && is_array($GLOBALS['dashboardUser'])) {
		$dashboardUser = $GLOBALS['dashboardUser'];
	}

	if ($currentSpecialist === null && isset($GLOBALS['currentSpecialist']) && is_array($GLOBALS['currentSpecialist'])) {
		$currentSpecialist = $GLOBALS['currentSpecialist'];
	}

	$sessionRole = $_SESSION['admin_role'] ?? '';
	$role = is_array($dashboardUser) ? (string) ($dashboardUser['role'] ?? '') : (is_string($sessionRole) ? $sessionRole : '');
	$navigation = [];

	if ($role === 'admin') {
		$navigation['index.php'] = 'Dashboard';
	}

	if ($currentSpecialist !== null || $role === 'specialist') {
		$navigation['my-appointments.php'] = 'Programarile mele';
	}

	if ($currentSpecialist !== null) {
		$navigation['my-create-appointment.php'] = 'Programare externa';
		$navigation['my-blocked-slots.php'] = 'Timpul meu blocat';
	}

	if ($role === 'admin') {
		$navigation += [
			'appointments.php' => 'Toate programarile',
			'blocked-slots.php' => 'Timp blocat',
			'promo-codes.php' => 'Coduri promotionale',
			'add-specialist.php' => 'Adauga specialist',
		];
	}

	$navigation['account.php'] = 'Datele contului';
	?>
	<header class="admin-header">
		<div>
			<p class="admin-kicker">DASHBOARD</p>
			<h1 class="admin-title"><?php echo adminEscape($title); ?></h1>
		</div>
		<nav class="admin-nav" aria-label="Navigare dashboard">
			<?php foreach ($navigation as $route => $label): ?>
				<a class="<?php echo $activeRoute === $route ? 'is-active' : ''; ?>" href="<?php echo adminEscape($route); ?>">
					<?php echo adminEscape($label); ?>
				</a>
			<?php endforeach; ?>
			<form class="admin-nav-form" method="post" action="logout.php">
				<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
				<button type="submit">Deconectare</button>
			</form>
		</nav>
	</header>
	<?php
}
