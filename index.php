<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Farmecul Tău</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="css/style.css">
</head>
<body>
	<?php require_once __DIR__ . '/includes/header.php'; ?>
	<?php require_once __DIR__ . '/includes/hero.php'; ?>
	<?php require_once __DIR__ . '/includes/site-map.php'; ?>

	<section class="beauty-game" aria-labelledby="beauty-game-title">
		<div class="beauty-game-shell">
			<div class="beauty-game-heading">
				<h2 class="beauty-game-title" id="beauty-game-title">SURPRIZE PROMOȚIONALE</h2>
				<p class="beauty-game-instructions">Alege autorul corect al citatului și poți primi un cod promoțional pentru următoarea ta vizită. Ai o singură alegere pentru fiecare citat.</p>
			</div>

			<div class="beauty-game-panel" data-beauty-game>
				<p class="beauty-game-quote" data-quote-text aria-live="polite">Se încarcă un citat...</p>
				<div class="beauty-game-options" data-quote-options role="group" aria-label="Alege autorul citatului"></div>
				<p class="beauty-game-error" data-quote-error role="alert" hidden>Nu am putut încărca un citat. Încearcă din nou.</p>
				<button class="beauty-game-refresh" type="button" data-quote-refresh>ALT CITAT</button>
			</div>
		</div>
	</section>

	<script src="js/script.js"></script>
	<script src="js/beauty-game.js"></script>
</body>
</html>
