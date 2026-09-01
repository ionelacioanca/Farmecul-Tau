<?php
$current_page = basename($_SERVER['PHP_SELF']);
$path_prefix = strpos($_SERVER['SCRIPT_NAME'], '/pages/') !== false ? '../' : '';
$headerServiceCategories = [
	'hairstyle' => 'Par',
	'nails' => 'Unghii',
];

$navigation = [
	'Acasa' => 'index.php',
	'Servicii' => 'pages/servicii.php',
	'Oferte' => 'pages/oferte.php',
	'Produse' => 'pages/produse.php',
	'Despre' => 'pages/despre.php',
	'Cont' => 'pages/contul-meu.php',
	'Programari' => 'pages/programari.php',
];
?>

<header class="site-header">
	<div class="header-inner">
		<a class="site-logo" href="<?php echo $path_prefix; ?>index.php" aria-label="Farmecul Tau - Acasa">
			<img src="<?php echo $path_prefix; ?>images/logo-farmecul-tau.png" alt="Farmecul Tau">
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
							<a class="<?php echo $label === 'Programari' ? 'nav-cta' : ''; ?><?php echo $is_current ? ' is-active' : ''; ?>"
							   href="<?php echo $path_prefix . $url; ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</div>
</header>
