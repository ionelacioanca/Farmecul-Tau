<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Farmecul Tău</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="css/style.css?v=20260828-4">
</head>
<body>
	<?php require_once __DIR__ . '/includes/header.php'; ?>
	<?php require_once __DIR__ . '/includes/hero.php'; ?>
	<?php require_once __DIR__ . '/includes/site-map.php'; ?>

	<section class="beauty-game" id="beauty-quote-surpriza" aria-labelledby="beauty-game-title">
		<div class="beauty-game-shell">
			<div class="beauty-game-heading">
				<h2 class="site-map-kicker beauty-game-title" id="beauty-game-title">SURPRIZE PROMOȚIONALE</h2>
				<p class="beauty-game-instructions">Alege autorul corect al citatului și poți descoperi o surpriză promoțională pentru următoarea ta vizită. Ai o singură alegere pentru fiecare citat.</p>
			</div>

			<div class="beauty-game-panel" data-beauty-game>
				<p class="beauty-game-quote" data-quote-text aria-live="polite">Se încarcă un citat...</p>
				<div class="beauty-game-options" data-quote-options role="group" aria-label="Alege autorul citatului"></div>
				<p class="beauty-game-error" data-quote-error role="alert" hidden>Nu am putut încărca un citat. Încearcă din nou.</p>
				<div class="beauty-game-reward" data-quote-reward hidden></div>
				<button class="beauty-game-refresh" type="button" data-quote-refresh>ALT CITAT</button>
			</div>
		</div>
	</section>

	<div class="beauty-auth-modal" data-auth-modal hidden>
		<div class="beauty-auth-backdrop" data-auth-close></div>
		<div class="beauty-auth-dialog" role="dialog" aria-modal="true" aria-labelledby="beauty-auth-title">
			<button class="beauty-auth-close" type="button" data-auth-close aria-label="Închide">×</button>
			<p class="beauty-auth-kicker">SURPRIZA TA TE AȘTEAPTĂ</p>
			<h2 class="beauty-auth-title" id="beauty-auth-title">Intră în cont pentru revendicare</h2>
			<p class="beauty-auth-text">Intră în cont sau creează unul pentru a primi codul promoțional.</p>

			<div class="beauty-auth-tabs" role="tablist" aria-label="Autentificare pentru surpriză">
				<button class="beauty-auth-tab is-active" type="button" data-auth-tab="login" role="tab" aria-selected="true">AM DEJA CONT</button>
				<button class="beauty-auth-tab" type="button" data-auth-tab="register" role="tab" aria-selected="false">CREEAZĂ CONT</button>
			</div>

			<form class="beauty-auth-form" data-auth-form="login">
				<label>
					<span>Email</span>
					<input type="email" name="email" autocomplete="email" required>
				</label>
				<label>
					<span>Parolă</span>
					<input type="password" name="password" autocomplete="current-password" required>
				</label>
				<p class="beauty-auth-message" data-auth-message="login" role="alert" hidden></p>
				<button class="beauty-auth-submit" type="submit">INTRĂ ÎN CONT</button>
			</form>

			<form class="beauty-auth-form" data-auth-form="register" hidden>
				<label>
					<span>Nume</span>
					<input type="text" name="name" autocomplete="name" required>
				</label>
				<label>
					<span>Email</span>
					<input type="email" name="email" autocomplete="email" required>
				</label>
				<label>
					<span>Parolă</span>
					<input type="password" name="password" autocomplete="new-password" minlength="8" required>
				</label>
				<label>
					<span>Confirmă parola</span>
					<input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required>
				</label>
				<p class="beauty-auth-message" data-auth-message="register" role="alert" hidden></p>
				<button class="beauty-auth-submit" type="submit">CREEAZĂ CONT</button>
			</form>
		</div>
	</div>

	<script src="js/script.js?v=20260828-3"></script>
	<script src="js/beauty-game.js?v=20260826-3"></script>
</body>
</html>
