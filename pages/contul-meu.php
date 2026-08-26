<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/promo-eligibility.php';

$user = getCurrentUser($pdo);
$activePromo = null;
$promoHistory = [];

if ($user !== null) {
	$activePromo = getActivePromoCodeForUser($pdo, (int) $user['id']);
	$promoHistory = getPromoCodesForUser($pdo, (int) $user['id']);
}

function escapeHtml(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatAccountDate(?string $dateValue): string
{
	if ($dateValue === null || $dateValue === '') {
		return '-';
	}

	try {
		$date = new DateTimeImmutable($dateValue);
	} catch (Exception $exception) {
		return $dateValue;
	}

	$months = [
		1 => 'ianuarie',
		2 => 'februarie',
		3 => 'martie',
		4 => 'aprilie',
		5 => 'mai',
		6 => 'iunie',
		7 => 'iulie',
		8 => 'august',
		9 => 'septembrie',
		10 => 'octombrie',
		11 => 'noiembrie',
		12 => 'decembrie',
	];

	return (int) $date->format('j') . ' ' . $months[(int) $date->format('n')] . ' ' . $date->format('Y');
}

function formatPromoStatus(string $status): string
{
	return match ($status) {
		'active' => 'ACTIV',
		'used' => 'FOLOSIT',
		'expired' => 'EXPIRAT',
		default => strtoupper($status),
	};
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Contul meu | Farmecul Tău</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="../css/style.css?v=20260826-4">
</head>
<body>
	<?php require_once __DIR__ . '/../includes/header.php'; ?>

	<main class="account-page" aria-labelledby="account-title">
		<div class="account-shell">
			<?php if ($user === null): ?>
				<section class="account-panel account-login-required" data-account-auth>
					<p class="account-kicker">CONT CLIENT</p>
					<h1 class="account-title" id="account-title">Intră în contul tău</h1>
					<p class="account-text">Alege autentificarea sau creează un cont pentru a vedea surprizele tale promoționale.</p>

					<div class="account-auth-tabs" role="tablist" aria-label="Autentificare cont client">
						<button class="account-auth-tab is-active" type="button" data-account-auth-tab="login" role="tab" aria-selected="true">AM DEJA CONT</button>
						<button class="account-auth-tab" type="button" data-account-auth-tab="register" role="tab" aria-selected="false">CREEAZĂ CONT</button>
					</div>

					<form class="account-auth-form" data-account-auth-form="login">
						<label>
							<span>Email</span>
							<input type="email" name="email" autocomplete="email" required>
						</label>
						<label>
							<span>Parolă</span>
							<input type="password" name="password" autocomplete="current-password" required>
						</label>
						<p class="account-auth-message" data-account-auth-message="login" role="alert" hidden></p>
						<button class="account-button" type="submit">Intră în cont</button>
					</form>

					<form class="account-auth-form" data-account-auth-form="register" hidden>
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
						<p class="account-auth-message" data-account-auth-message="register" role="alert" hidden></p>
						<button class="account-button" type="submit">Creează cont</button>
					</form>
				</section>
			<?php else: ?>
				<section class="account-intro">
					<div>
						<p class="account-kicker">CONTUL MEU</p>
						<h1 class="account-title" id="account-title">Bun venit, <?php echo escapeHtml($user['name']); ?></h1>
						<p class="account-email"><?php echo escapeHtml($user['email']); ?></p>
					</div>
					<button class="account-logout" type="button" data-account-logout>Deconectare</button>
				</section>

				<section class="account-panel account-active-reward" aria-labelledby="active-reward-title">
					<p class="account-kicker">SURPRIZA TA ACTIVĂ</p>
					<?php if ($activePromo !== null): ?>
						<h2 class="account-reward-title" id="active-reward-title"><?php echo escapeHtml($activePromo['reward']['name']); ?></h2>
						<p class="account-reward-description"><?php echo escapeHtml($activePromo['reward']['description']); ?></p>
						<div class="account-code-grid">
							<div>
								<span>Cod</span>
								<strong><?php echo escapeHtml($activePromo['code']); ?></strong>
							</div>
							<div>
								<span>Valabil până la</span>
								<strong><?php echo escapeHtml(formatAccountDate($activePromo['expires_at'])); ?></strong>
							</div>
							<div>
								<span>Status</span>
								<strong><?php echo escapeHtml(formatPromoStatus($activePromo['status'])); ?></strong>
							</div>
						</div>
					<?php else: ?>
						<h2 class="account-reward-title" id="active-reward-title">Nu ai o surpriză activă momentan</h2>
						<p class="account-reward-description">Poți câștiga una răspunzând corect la Beauty Quote Challenge.</p>
						<a class="account-button" href="../index.php">Joacă Beauty Quote Challenge</a>
					<?php endif; ?>
				</section>

				<section class="account-panel account-history" aria-labelledby="promo-history-title">
					<h2 class="account-section-title" id="promo-history-title">ISTORIC SURPRIZE</h2>

					<?php if ($promoHistory === []): ?>
						<p class="account-text">Nu ai încă surprize revendicate.</p>
					<?php else: ?>
						<div class="account-history-list">
							<?php foreach ($promoHistory as $promo): ?>
								<article class="account-history-item">
									<h3><?php echo escapeHtml((string) $promo['reward_name']); ?></h3>
									<dl>
										<div>
											<dt>Cod</dt>
											<dd><?php echo escapeHtml((string) $promo['code']); ?></dd>
										</div>
										<div>
											<dt>Creat</dt>
											<dd><?php echo escapeHtml(formatAccountDate((string) $promo['created_at'])); ?></dd>
										</div>
										<div>
											<dt>Expiră</dt>
											<dd><?php echo escapeHtml(formatAccountDate((string) $promo['expires_at'])); ?></dd>
										</div>
										<div>
											<dt>Status</dt>
											<dd><?php echo escapeHtml(formatPromoStatus((string) $promo['status'])); ?></dd>
										</div>
									</dl>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>

				<section class="account-panel account-booking-history" aria-labelledby="booking-history-title">
					<h2 class="account-section-title" id="booking-history-title">ISTORIC PROGRAMĂRI</h2>
					<p class="account-text">Această secțiune va fi disponibilă într-un pas următor.</p>
				</section>
			<?php endif; ?>
		</div>
	</main>

	<script src="../js/script.js"></script>
	<script>
		const logoutButton = document.querySelector('[data-account-logout]');
		const accountAuth = document.querySelector('[data-account-auth]');

		if (logoutButton) {
			logoutButton.addEventListener('click', async () => {
				logoutButton.disabled = true;

				try {
					await fetch('../api/logout.php', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { Accept: 'application/json' },
					});
				} finally {
					window.location.href = '../index.php';
				}
			});
		}

		if (accountAuth) {
			const authTabs = Array.from(accountAuth.querySelectorAll('[data-account-auth-tab]'));
			const authForms = Array.from(accountAuth.querySelectorAll('[data-account-auth-form]'));

			const hideMessages = () => {
				accountAuth.querySelectorAll('[data-account-auth-message]').forEach((message) => {
					message.textContent = '';
					message.hidden = true;
				});
			};

			const showMessage = (view, text) => {
				const message = accountAuth.querySelector(`[data-account-auth-message="${view}"]`);
				if (!message) return;

				message.textContent = text;
				message.hidden = false;
			};

			const switchView = (view) => {
				authTabs.forEach((tab) => {
					const isActive = tab.dataset.accountAuthTab === view;
					tab.classList.toggle('is-active', isActive);
					tab.setAttribute('aria-selected', String(isActive));
				});

				authForms.forEach((form) => {
					form.hidden = form.dataset.accountAuthForm !== view;
				});

				hideMessages();
			};

			const getErrorMessage = (data) => {
				if (data.errors) {
					return Object.values(data.errors).filter(Boolean).join(' ');
				}

				return data.error || 'Autentificarea nu a putut fi finalizată.';
			};

			authTabs.forEach((tab) => {
				tab.addEventListener('click', () => switchView(tab.dataset.accountAuthTab));
			});

			authForms.forEach((form) => {
				form.addEventListener('submit', async (event) => {
					event.preventDefault();

					const view = form.dataset.accountAuthForm;
					const endpoint = view === 'register' ? '../api/register.php' : '../api/login.php';
					const submitButton = form.querySelector('button[type="submit"]');
					const payload = Object.fromEntries(new FormData(form).entries());

					hideMessages();
					if (submitButton) submitButton.disabled = true;

					try {
						const response = await fetch(endpoint, {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								Accept: 'application/json',
								'Content-Type': 'application/json',
							},
							body: JSON.stringify(payload),
						});
						const data = await response.json();

						if (!response.ok || !data.success) {
							showMessage(view, getErrorMessage(data));
							return;
						}

						window.location.reload();
					} catch (error) {
						showMessage(view, 'Autentificarea nu a putut fi finalizată.');
					} finally {
						if (submitButton) submitButton.disabled = false;
					}
				});
			});
		}
	</script>
</body>
</html>
