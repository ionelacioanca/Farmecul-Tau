<?php
$current_page = basename($_SERVER['PHP_SELF']);
$path_prefix = strpos($_SERVER['SCRIPT_NAME'], '/pages/') !== false ? '../' : '';
$headerServiceCategories = [
	'hairstyle' => 'Par',
	'nails' => 'Unghii',
];

$headerHasCustomerSession = isset($headerHasCustomerSession) ? (bool) $headerHasCustomerSession : false;

if ($headerHasCustomerSession) {
	$headerHasCustomerSession = true;
} elseif (isset($user) && is_array($user)) {
	$headerHasCustomerSession = true;
} elseif (session_status() === PHP_SESSION_ACTIVE) {
	$sessionCustomerUserId = $_SESSION['customer_user_id'] ?? null;
	$headerHasCustomerSession = is_int($sessionCustomerUserId) && $sessionCustomerUserId > 0;
}

$navigation = [
	'Acasă' => 'index.php',
	'Servicii' => 'pages/servicii.php',
	'Oferte' => 'pages/oferte.php',
	'Despre' => 'pages/despre.php',
	'Programări' => 'pages/programari.php',
];
?>

<header class="site-header">
	<div class="header-inner">
		<a class="site-logo" href="<?php echo $path_prefix; ?>index.php" aria-label="Farmecul Tău - Acasă">
			<img src="<?php echo $path_prefix; ?>images/logo-farmecul-tau.png" alt="Farmecul Tău">
		</a>
		<button class="menu-toggle" type="button" aria-label="Deschide meniul" aria-expanded="false" aria-controls="site-navigation">
			<span class="menu-toggle-label">Meniu</span>
			<span class="menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>
		</button>
		<nav class="site-nav" id="site-navigation" aria-label="Navigare principala">
			<ul>
				<?php foreach ($navigation as $label => $url): ?>
					<?php $is_current = $current_page === basename($url); ?>
					<li class="<?php echo $label === 'Servicii' ? 'has-submenu' : ''; ?>">
						<?php if ($label === 'Servicii'): ?>
							<div class="nav-submenu-control">
								<a class="nav-submenu-main<?php echo $is_current ? ' is-active' : ''; ?>" href="<?php echo $path_prefix . $url; ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>>
									<?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
								</a>
								<button class="nav-submenu-toggle" type="button" aria-label="Extinde serviciile" aria-expanded="false"></button>
							</div>
							<div class="nav-submenu">
								<div class="nav-service-filters" aria-label="Filtre servicii">
									<?php foreach ($headerServiceCategories as $categoryKey => $categoryLabel): ?>
										<a href="<?php echo $path_prefix; ?>pages/servicii.php?category=<?php echo htmlspecialchars($categoryKey, ENT_QUOTES, 'UTF-8'); ?>">
											<?php echo htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8'); ?>
										</a>
									<?php endforeach; ?>
								</div>
							</div>
						<?php else: ?>
							<a class="<?php echo $is_current ? ' is-active' : ''; ?>"
							   href="<?php echo $path_prefix . $url; ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
				<li
					class="has-submenu account-menu-item"
					data-account-menu
					data-authenticated="<?php echo $headerHasCustomerSession ? 'true' : 'false'; ?>"
					data-auth-status-url="<?php echo $path_prefix; ?>api/auth-status.php"
					data-logout-url="<?php echo $path_prefix; ?>api/logout.php"
					data-home-url="<?php echo $path_prefix; ?>index.php"
				>
					<div class="nav-submenu-control">
						<button class="account-menu-button<?php echo $current_page === 'contul-meu.php' ? ' is-active' : ''; ?>" type="button" aria-label="Contul meu" aria-haspopup="true" aria-expanded="false" data-account-menu-button>
							<span>Contul meu</span>
						</button>
						<button class="nav-submenu-toggle account-menu-toggle" type="button" aria-label="Extinde meniul contului" aria-expanded="false"></button>
					</div>
					<div class="nav-submenu account-menu-dropdown">
						<div class="account-menu-panel" data-account-menu-authenticated <?php echo $headerHasCustomerSession ? '' : 'hidden'; ?>>
							<a href="<?php echo $path_prefix; ?>pages/contul-meu.php#programarile-mele">Programările mele</a>
							<a href="<?php echo $path_prefix; ?>pages/contul-meu.php#istoric-recompense">Istoric recompense</a>
							<a href="<?php echo $path_prefix; ?>pages/contul-meu.php#editeaza-profil">Editează profil</a>
							<a href="<?php echo $path_prefix; ?>pages/contul-meu.php#stergere-cont">Ștergere cont</a>
							<button type="button" data-header-logout>Deconectare</button>
						</div>
						<div class="account-menu-panel" data-account-menu-guest <?php echo $headerHasCustomerSession ? 'hidden' : ''; ?>>
							<a href="<?php echo $path_prefix; ?>pages/contul-meu.php#creaza-cont">Creează cont</a>
							<a href="<?php echo $path_prefix; ?>pages/contul-meu.php#conecteaza-te">Conectează-te</a>
						</div>
					</div>
				</li>
			</ul>
		</nav>
	</div>
</header>
