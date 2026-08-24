<?php
$current_page = basename($_SERVER['PHP_SELF']);
$path_prefix = strpos($_SERVER['SCRIPT_NAME'], '/pages/') !== false ? '../' : '';
$navigation = [
	'Acasă' => 'index.php',
	'Servicii' => 'pages/servicii.php',
	'Oferte' => 'pages/oferte.php',
	'Produse' => 'pages/produse.php',
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
		<nav class="site-nav" id="site-navigation" aria-label="Navigare principală">
			<ul>
				<?php foreach ($navigation as $label => $url): ?>
					<?php $is_current = $current_page === basename($url); ?>
					<li>
						<a class="<?php echo $label === 'Programări' ? 'nav-cta' : ''; ?><?php echo $is_current ? ' is-active' : ''; ?>"
						   href="<?php echo $path_prefix . $url; ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo $label; ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>
	</div>
</header>
