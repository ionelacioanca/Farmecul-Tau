<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin-auth.php';

$adminUser = requireAdminUser($pdo);
$csrfToken = getAdminCsrfToken();

function adminEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Administrare | Farmecul Tău</title>
	<link rel="stylesheet" href="../css/style.css?v=20260826-4">
</head>
<body>
	<header class="admin-header">
		<div>
			<p class="admin-kicker">ADMINISTRARE</p>
			<h1 class="admin-title">Farmecul Tău — Administrare</h1>
		</div>
		<nav class="admin-nav" aria-label="Navigare administrare">
			<a class="is-active" href="index.php">Dashboard</a>
			<a href="promo-codes.php">Coduri promoționale</a>
			<form class="admin-nav-form" method="post" action="logout.php">
				<input type="hidden" name="csrf_token" value="<?php echo adminEscape($csrfToken); ?>">
				<button type="submit">Deconectare</button>
			</form>
		</nav>
	</header>

	<main class="admin-page">
		<section class="admin-panel">
			<p class="admin-welcome">Bun venit, <?php echo adminEscape($adminUser['name']); ?>.</p>
			<div class="admin-actions">
				<a class="admin-card-link" href="promo-codes.php">
					<span>Coduri promoționale</span>
					<strong>Vezi și gestionează codurile revendicate</strong>
				</a>
			</div>
		</section>
	</main>
</body>
</html>
