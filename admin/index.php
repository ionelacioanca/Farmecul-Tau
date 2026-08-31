<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';
require_once __DIR__ . '/../includes/admin-ui.php';

$dashboardUser = requireDashboardUser($pdo);
$currentSpecialist = getCurrentSpecialist($pdo, $dashboardUser);

if ($dashboardUser['role'] === 'specialist') {
	header('Location: my-appointments.php');
	exit;
}

$csrfToken = getAdminCsrfToken();

$summaryStatement = $pdo->prepare(
	"SELECT
		COALESCE(SUM(status = 'pending'), 0) AS pending_count,
		COALESCE(SUM(status = 'approved' AND DATE(start_datetime) = CURDATE()), 0) AS approved_today_count,
		COALESCE(SUM(status IN ('pending', 'approved') AND start_datetime >= NOW()), 0) AS upcoming_count
	 FROM appointments"
);
$summaryStatement->execute();
$appointmentSummary = $summaryStatement->fetch() ?: [
	'pending_count' => 0,
	'approved_today_count' => 0,
	'upcoming_count' => 0,
];
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Administrare | Farmecul Tău</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-7">
</head>
<body>
	<?php renderAdminHeader('Farmecul Tău - Administrare', 'index.php', $csrfToken); ?>

	<main class="admin-page">
		<section class="admin-panel">
			<p class="admin-welcome">Bun venit, <?php echo adminEscape($dashboardUser['name']); ?>.</p>
			<div class="admin-summary-grid" aria-label="Rezumat programări">
				<div class="admin-summary-card">
					<span>În așteptare</span>
					<strong><?php echo (int) $appointmentSummary['pending_count']; ?></strong>
				</div>
				<div class="admin-summary-card">
					<span>Aprobate astăzi</span>
					<strong><?php echo (int) $appointmentSummary['approved_today_count']; ?></strong>
				</div>
				<div class="admin-summary-card">
					<span>Viitoare</span>
					<strong><?php echo (int) $appointmentSummary['upcoming_count']; ?></strong>
				</div>
			</div>
			<div class="admin-actions">
				<a class="admin-card-link" href="appointments.php">
					<span>Programări</span>
					<strong>Vezi cererile și aprobă sau respinge programările</strong>
				</a>
				<a class="admin-card-link" href="create-appointment.php">
					<span>Programare manuală</span>
					<strong>Adaugă o programare confirmată din telefon, Instagram sau direct</strong>
				</a>
				<a class="admin-card-link" href="offers.php">
					<span>Oferte</span>
					<strong>Creeaza oferte bookable cu durata, pret si specialisti eligibili</strong>
				</a>
				<a class="admin-card-link" href="blocked-slots.php">
					<span>Timp blocat</span>
					<strong>Blochează intervale fără programare de client</strong>
				</a>
				<a class="admin-card-link" href="promo-codes.php">
					<span>Coduri promoționale</span>
					<strong>Vezi și gestionează codurile revendicate</strong>
				</a>
			</div>
		</section>
	</main>
</body>
</html>
