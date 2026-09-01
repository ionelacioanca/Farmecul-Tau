<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/promo-eligibility.php';

$user = getCurrentUser($pdo);
$activePromo = null;
$promoHistory = [];
$appointments = [];

if ($user !== null) {
	$activePromo = getActivePromoCodeForUser($pdo, (int) $user['id']);
	$promoHistory = getPromoCodesForUser($pdo, (int) $user['id']);

	$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest'));
	$appointmentStatement = $pdo->prepare(
		"SELECT
			a.id,
			a.booking_type,
			a.start_datetime,
			a.end_datetime,
			a.price_at_booking,
			a.duration_minutes_at_booking,
			a.status,
			a.source,
			a.notes,
			sv.name AS service_name,
			ofr.title AS offer_title,
			sp.name AS specialist_name
		 FROM appointments a
		 LEFT JOIN services sv ON sv.id = a.service_id
		 LEFT JOIN offers ofr ON ofr.id = a.offer_id
		 INNER JOIN specialists sp ON sp.id = a.specialist_id
		 WHERE a.customer_user_id = :customer_user_id
		 ORDER BY
			CASE WHEN a.start_datetime >= :now_active AND a.status IN ('pending', 'approved') THEN 0 ELSE 1 END ASC,
			CASE WHEN a.start_datetime >= :now_future THEN a.start_datetime END ASC,
			a.start_datetime DESC,
			a.id DESC"
	);
	$appointmentStatement->execute([
		'customer_user_id' => (int) $user['id'],
		'now_active' => $now->format('Y-m-d H:i:s'),
		'now_future' => $now->format('Y-m-d H:i:s'),
	]);
	$appointments = $appointmentStatement->fetchAll();
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
		'pending' => 'ÎN AȘTEPTARE',
		'approved' => 'APROBATĂ',
		'rejected' => 'RESPINSĂ',
		'cancelled' => 'ANULATĂ',
		default => strtoupper($status),
	};
}

function formatAccountTime(?string $dateValue): string
{
	if ($dateValue === null || $dateValue === '') {
		return '-';
	}

	try {
		$date = new DateTimeImmutable($dateValue);
	} catch (Exception $exception) {
		return $dateValue;
	}

	return $date->format('H:i');
}

function formatAccountDateInput(?string $dateValue): string
{
	if ($dateValue === null || $dateValue === '') {
		return '';
	}

	try {
		$date = new DateTimeImmutable($dateValue);
	} catch (Exception $exception) {
		return '';
	}

	return $date->format('Y-m-d');
}

function formatAccountPrice(?string $price): string
{
	if ($price === null || $price === '') {
		return '-';
	}

	return number_format((float) $price, 2, ',', '.') . ' lei';
}

function formatAccountAppointmentState(array $appointment): array
{
	$status = (string) ($appointment['status'] ?? '');

	if ($status === 'rejected') {
		return ['label' => 'RESPINSĂ', 'class' => 'rejected'];
	}

	if ($status === 'cancelled') {
		return ['label' => 'ANULATĂ', 'class' => 'cancelled'];
	}

	try {
		$start = new DateTimeImmutable((string) $appointment['start_datetime']);
		$now = new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest'));
	} catch (Exception $exception) {
		return ['label' => strtoupper($status), 'class' => $status];
	}

	if ($start >= $now && in_array($status, ['pending', 'approved'], true)) {
		return ['label' => 'ACTIVĂ', 'class' => 'active'];
	}

	return ['label' => 'FINALIZATĂ', 'class' => 'completed'];
}
?>
<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Contul meu | Farmecul Tău</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="../css/style.css?v=20260828-4">
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
						<?php if (!empty($user['phone'])): ?>
							<p class="account-email"><?php echo escapeHtml((string) $user['phone']); ?></p>
						<?php endif; ?>
					</div>
					<button class="account-logout" type="button" data-account-logout>Deconectare</button>
				</section>

				<section class="account-panel account-profile" aria-labelledby="account-profile-title">
					<h2 class="account-section-title" id="account-profile-title">DATELE CONTULUI</h2>
					<form class="account-profile-form" data-account-profile-form>
						<label>
							<span>Nume</span>
							<input type="text" name="name" autocomplete="name" maxlength="150" value="<?php echo escapeHtml($user['name']); ?>" required>
						</label>

						<label>
							<span>Email</span>
							<input type="email" name="email" autocomplete="email" maxlength="255" value="<?php echo escapeHtml($user['email']); ?>" required>
						</label>

						<label>
							<span>Telefon</span>
							<input type="tel" name="phone" autocomplete="tel" maxlength="50" value="<?php echo escapeHtml((string) ($user['phone'] ?? '')); ?>">
						</label>

						<p class="account-auth-message" data-account-profile-message role="alert" hidden></p>
						<button class="account-button" type="submit">Salveaza datele</button>
					</form>
				</section>

				<section class="account-panel account-danger" aria-labelledby="account-danger-title">
					<h2 class="account-section-title" id="account-danger-title">STERGERE CONT</h2>
					<p class="account-text">Stergerea contului elimina accesul la profil. Programarile vechi raman in istoricul salonului fara legatura cu acest cont.</p>
					<p class="account-auth-message" data-account-delete-message role="alert" hidden></p>
					<button class="account-button account-danger-button" type="button" data-account-delete>STERGE CONTUL</button>
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
					<h2 class="account-section-title" id="booking-history-title">PROGRAMĂRILE MELE</h2>

					<?php if ($appointments === []): ?>
						<p class="account-text">Nu ai încă programări în cont.</p>
					<?php else: ?>
						<div class="account-history-list account-appointment-list">
							<?php foreach ($appointments as $appointment): ?>
								<?php
									$appointmentState = formatAccountAppointmentState($appointment);
									$isOffer = (string) ($appointment['booking_type'] ?? 'service') === 'offer';
									$appointmentTitle = $isOffer
										? (string) ($appointment['offer_title'] ?? 'Oferta')
										: (string) ($appointment['service_name'] ?? 'Serviciu');
									$appointmentDateValue = formatAccountDateInput((string) $appointment['start_datetime']);
									$appointmentTimeValue = formatAccountTime((string) $appointment['start_datetime']);
									$canManageAppointment = $appointmentState['class'] === 'active';
								?>
								<article
									class="account-history-item account-appointment-item"
									data-account-appointment
									data-appointment-id="<?php echo (int) $appointment['id']; ?>"
									data-appointment-current-date="<?php echo escapeHtml($appointmentDateValue); ?>"
									data-appointment-current-time="<?php echo escapeHtml($appointmentTimeValue); ?>"
								>
									<div class="account-appointment-head">
										<div>
											<p class="account-appointment-type"><?php echo $isOffer ? 'Ofertă' : 'Serviciu'; ?></p>
											<h3><?php echo escapeHtml($appointmentTitle); ?></h3>
										</div>
										<span class="account-appointment-status account-appointment-status-<?php echo escapeHtml($appointmentState['class']); ?>">
											<?php echo escapeHtml($appointmentState['label']); ?>
										</span>
									</div>
									<dl>
										<div>
											<dt>Specialist</dt>
											<dd><?php echo escapeHtml((string) $appointment['specialist_name']); ?></dd>
										</div>
										<div>
											<dt>Data</dt>
											<dd><?php echo escapeHtml(formatAccountDate((string) $appointment['start_datetime'])); ?></dd>
										</div>
										<div>
											<dt>Ora</dt>
											<dd><?php echo escapeHtml(formatAccountTime((string) $appointment['start_datetime'])); ?></dd>
										</div>
										<div>
											<dt>Durată</dt>
											<dd><?php echo (int) $appointment['duration_minutes_at_booking']; ?> min</dd>
										</div>
										<div>
											<dt>Preț</dt>
											<dd><?php echo escapeHtml(formatAccountPrice($appointment['price_at_booking'] !== null ? (string) $appointment['price_at_booking'] : null)); ?></dd>
										</div>
										<div>
											<dt>Status salon</dt>
											<dd><?php echo escapeHtml(formatPromoStatus((string) $appointment['status'])); ?></dd>
										</div>
									</dl>
									<?php if ($appointment['notes'] !== null && trim((string) $appointment['notes']) !== ''): ?>
										<p class="account-appointment-note"><?php echo escapeHtml((string) $appointment['notes']); ?></p>
									<?php endif; ?>
									<?php if ($canManageAppointment): ?>
										<div class="account-appointment-actions">
											<button class="account-action-button" type="button" data-appointment-reschedule-toggle>Reprogramează</button>
											<button class="account-action-button account-action-danger" type="button" data-appointment-cancel>Anulează</button>
										</div>
										<form class="account-reschedule-form" data-appointment-reschedule-form hidden>
											<div class="account-reschedule-grid">
												<label>
													<span>Zi</span>
													<input type="date" name="date" min="<?php echo escapeHtml((new DateTimeImmutable('today', new DateTimeZone('Europe/Bucharest')))->format('Y-m-d')); ?>" value="<?php echo escapeHtml($appointmentDateValue); ?>" required>
												</label>
												<label>
													<span>Ora</span>
													<select name="time" required>
														<option value="<?php echo escapeHtml($appointmentTimeValue); ?>"><?php echo escapeHtml($appointmentTimeValue); ?></option>
													</select>
												</label>
											</div>
											<p class="account-auth-message" data-appointment-message role="alert" hidden></p>
											<div class="account-reschedule-actions">
												<button class="account-button" type="submit">Salvează</button>
												<button class="account-action-button" type="button" data-appointment-reschedule-close>Închide</button>
											</div>
										</form>
									<?php endif; ?>
								</article>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>
			<?php endif; ?>
		</div>
	</main>

	<script src="../js/script.js?v=20260828-3"></script>
	<script>
		const logoutButton = document.querySelector('[data-account-logout]');
		const accountAuth = document.querySelector('[data-account-auth]');
		const accountProfileForm = document.querySelector('[data-account-profile-form]');
		const accountDeleteButton = document.querySelector('[data-account-delete]');

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

		if (accountProfileForm) {
			const profileMessage = document.querySelector('[data-account-profile-message]');

			accountProfileForm.addEventListener('submit', async (event) => {
				event.preventDefault();

				const submitButton = accountProfileForm.querySelector('button[type="submit"]');
				const payload = Object.fromEntries(new FormData(accountProfileForm).entries());

				if (profileMessage) {
					profileMessage.textContent = '';
					profileMessage.hidden = true;
				}
				if (submitButton) submitButton.disabled = true;

				try {
					const response = await fetch('../api/update-profile.php', {
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
						const message = data.errors
							? Object.values(data.errors).filter(Boolean).join(' ')
							: data.error || 'Datele contului nu au putut fi actualizate.';
						if (profileMessage) {
							profileMessage.textContent = message;
							profileMessage.hidden = false;
						}
						return;
					}

					if (profileMessage) {
						profileMessage.textContent = data.message || 'Datele contului au fost actualizate.';
						profileMessage.hidden = false;
					}
				} catch (error) {
					if (profileMessage) {
						profileMessage.textContent = 'Datele contului nu au putut fi actualizate.';
						profileMessage.hidden = false;
					}
				} finally {
					if (submitButton) submitButton.disabled = false;
				}
			});
		}

		if (accountDeleteButton) {
			const deleteMessage = document.querySelector('[data-account-delete-message]');

			const showDeleteMessage = (message) => {
				if (!deleteMessage) return;

				deleteMessage.textContent = message;
				deleteMessage.hidden = false;
			};

			const requestAccountDelete = async (cancelActiveAppointments = false) => {
				const response = await fetch('../api/delete-account.php', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						cancel_active_appointments: cancelActiveAppointments,
					}),
				});
				const data = await response.json();

				return { response, data };
			};

			accountDeleteButton.addEventListener('click', async () => {
				if (!confirm('Sigur vrei sa stergi contul?')) {
					return;
				}

				accountDeleteButton.disabled = true;
				if (deleteMessage) {
					deleteMessage.textContent = '';
					deleteMessage.hidden = true;
				}

				try {
					let result = await requestAccountDelete(false);

					if (result.response.status === 409 && result.data.requires_confirmation) {
						const count = Number(result.data.active_appointment_count || 0);
						const prompt = count === 1
							? 'Ai o programare activa. Vrei sa o anulezi si sa stergi contul?'
							: `Ai ${count} programari active. Vrei sa le anulezi si sa stergi contul?`;

						if (!confirm(prompt)) {
							showDeleteMessage('Contul nu a fost sters.');
							return;
						}

						result = await requestAccountDelete(true);
					}

					if (!result.response.ok || !result.data.success) {
						showDeleteMessage(result.data.error || result.data.message || 'Contul nu a putut fi sters.');
						return;
					}

					window.location.href = '../index.php';
				} catch (error) {
					showDeleteMessage('Contul nu a putut fi sters.');
				} finally {
					accountDeleteButton.disabled = false;
				}
			});
		}

		document.querySelectorAll('[data-account-appointment]').forEach((appointmentCard) => {
			const appointmentId = appointmentCard.dataset.appointmentId;
			const toggleButton = appointmentCard.querySelector('[data-appointment-reschedule-toggle]');
			const closeButton = appointmentCard.querySelector('[data-appointment-reschedule-close]');
			const cancelButton = appointmentCard.querySelector('[data-appointment-cancel]');
			const form = appointmentCard.querySelector('[data-appointment-reschedule-form]');
			const message = appointmentCard.querySelector('[data-appointment-message]');
			const dateInput = form ? form.querySelector('input[name="date"]') : null;
			const timeSelect = form ? form.querySelector('select[name="time"]') : null;

			const showAppointmentMessage = (text) => {
				if (!message) return;

				message.textContent = text;
				message.hidden = false;
			};

			const hideAppointmentMessage = () => {
				if (!message) return;

				message.textContent = '';
				message.hidden = true;
			};

			const getApiMessage = (data, fallback) => {
				if (data.errors) {
					return Object.values(data.errors).filter(Boolean).join(' ');
				}

				return data.error || data.message || fallback;
			};

			const setTimeOptions = (slots, preferredTime) => {
				if (!timeSelect) return;

				timeSelect.innerHTML = '';

				if (!Array.isArray(slots) || slots.length === 0) {
					const option = document.createElement('option');
					option.value = '';
					option.textContent = 'Nu există ore disponibile';
					timeSelect.append(option);
					timeSelect.disabled = true;
					return;
				}

				const placeholder = document.createElement('option');
				placeholder.value = '';
				placeholder.textContent = 'Alege ora';
				timeSelect.append(placeholder);

				slots.forEach((slot) => {
					const option = document.createElement('option');
					option.value = slot;
					option.textContent = slot;
					timeSelect.append(option);
				});

				timeSelect.disabled = false;

				if (slots.includes(preferredTime)) {
					timeSelect.value = preferredTime;
				}
			};

			const loadAvailableTimes = async () => {
				if (!appointmentId || !dateInput || !timeSelect) return;

				const selectedDate = dateInput.value;
				const preferredTime = selectedDate === appointmentCard.dataset.appointmentCurrentDate
					? appointmentCard.dataset.appointmentCurrentTime
					: '';

				hideAppointmentMessage();
				timeSelect.disabled = true;
				timeSelect.innerHTML = '<option value="">Se încarcă...</option>';

				try {
					const params = new URLSearchParams({
						appointment_id: appointmentId,
						date: selectedDate,
					});
					const response = await fetch(`../api/get-account-appointment-availability.php?${params.toString()}`, {
						credentials: 'same-origin',
						headers: { Accept: 'application/json' },
					});
					const data = await response.json();

					if (!response.ok || !data.success) {
						setTimeOptions([], '');
						showAppointmentMessage(getApiMessage(data, 'Orele disponibile nu au putut fi incarcate.'));
						return;
					}

					setTimeOptions(data.slots || [], preferredTime);
				} catch (error) {
					setTimeOptions([], '');
					showAppointmentMessage('Orele disponibile nu au putut fi incarcate.');
				}
			};

			if (toggleButton && form) {
				toggleButton.addEventListener('click', async () => {
					form.hidden = !form.hidden;

					if (!form.hidden) {
						await loadAvailableTimes();
					}
				});
			}

			if (closeButton && form) {
				closeButton.addEventListener('click', () => {
					form.hidden = true;
					hideAppointmentMessage();
				});
			}

			if (dateInput) {
				dateInput.addEventListener('change', loadAvailableTimes);
			}

			if (cancelButton) {
				cancelButton.addEventListener('click', async () => {
					if (!appointmentId || !confirm('Sigur vrei sa anulezi aceasta programare?')) {
						return;
					}

					cancelButton.disabled = true;
					hideAppointmentMessage();

					try {
						const response = await fetch('../api/cancel-appointment.php', {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								Accept: 'application/json',
								'Content-Type': 'application/json',
							},
							body: JSON.stringify({ appointment_id: appointmentId }),
						});
						const data = await response.json();

						if (!response.ok || !data.success) {
							showAppointmentMessage(getApiMessage(data, 'Programarea nu a putut fi anulata.'));
							return;
						}

						window.location.reload();
					} catch (error) {
						showAppointmentMessage('Programarea nu a putut fi anulata.');
					} finally {
						cancelButton.disabled = false;
					}
				});
			}

			if (form) {
				form.addEventListener('submit', async (event) => {
					event.preventDefault();

					if (!appointmentId || !dateInput || !timeSelect || !dateInput.value || !timeSelect.value) {
						showAppointmentMessage('Alege ziua si ora pentru reprogramare.');
						return;
					}

					const submitButton = form.querySelector('button[type="submit"]');
					hideAppointmentMessage();
					if (submitButton) submitButton.disabled = true;

					try {
						const response = await fetch('../api/reschedule-appointment.php', {
							method: 'POST',
							credentials: 'same-origin',
							headers: {
								Accept: 'application/json',
								'Content-Type': 'application/json',
							},
							body: JSON.stringify({
								appointment_id: appointmentId,
								date: dateInput.value,
								time: timeSelect.value,
							}),
						});
						const data = await response.json();

						if (!response.ok || !data.success) {
							showAppointmentMessage(getApiMessage(data, 'Programarea nu a putut fi reprogramata.'));
							return;
						}

						window.location.reload();
					} catch (error) {
						showAppointmentMessage('Programarea nu a putut fi reprogramata.');
					} finally {
						if (submitButton) submitButton.disabled = false;
					}
				});
			}
		});
	</script>
</body>
</html>
