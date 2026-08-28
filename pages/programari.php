<!DOCTYPE html>
<html lang="ro">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Programări | Farmecul Tău</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
	<link rel="stylesheet" href="../css/style.css?v=20260826-6">
</head>
<body>
	<?php require_once __DIR__ . '/../includes/header.php'; ?>

	<main class="booking-page" aria-labelledby="booking-title">
		<section class="booking-shell">
			<div class="booking-heading">
				<p class="booking-kicker">PROGRAMĂRI</p>
				<h1 class="booking-title" id="booking-title">Alege momentul potrivit pentru tine</h1>
				<p class="booking-text">Alege serviciul, specialistul și intervalul dorit, apoi trimite cererea de programare.</p>
			</div>

			<div class="booking-panel" data-booking-availability>
				<form class="booking-form" data-booking-form>
					<label>
						<span>Serviciu</span>
						<select name="service_id" data-booking-service required>
							<option value="">Se încarcă serviciile...</option>
						</select>
					</label>

					<label>
						<span>Specialist</span>
						<select name="specialist_id" data-booking-specialist required disabled>
							<option value="">Alege întâi serviciul</option>
						</select>
					</label>

					<label>
						<span>Data</span>
						<input type="date" name="date" data-booking-date required>
					</label>
				</form>

				<div class="booking-selected-details" data-booking-selected-details hidden>
					<p data-booking-selected-duration></p>
					<p data-booking-selected-price></p>
				</div>

				<div class="booking-slots" aria-live="polite">
					<p class="booking-status" data-booking-status>Alege un serviciu, un specialist și o dată.</p>
					<div class="booking-slot-grid" data-booking-slots></div>
				</div>

				<section class="booking-details" data-booking-details hidden aria-live="polite">
					<div class="booking-summary">
						<p class="booking-summary-label">Rezumat</p>
						<dl class="booking-summary-list">
							<div>
								<dt>Serviciu</dt>
								<dd data-booking-summary-service>-</dd>
							</div>
							<div>
								<dt>Specialist</dt>
								<dd data-booking-summary-specialist>-</dd>
							</div>
							<div>
								<dt>Data</dt>
								<dd data-booking-summary-date>-</dd>
							</div>
							<div>
								<dt>Ora</dt>
								<dd data-booking-summary-time>-</dd>
							</div>
						</dl>
					</div>

					<form class="booking-details-form" data-booking-details-form>
						<label>
							<span>Nume</span>
							<input type="text" name="customer_name" autocomplete="name" maxlength="150" required>
						</label>

						<label>
							<span>Email</span>
							<input type="email" name="customer_email" autocomplete="email" maxlength="255" required>
						</label>

						<label>
							<span>Telefon</span>
							<input type="tel" name="customer_phone" autocomplete="tel" maxlength="50" required>
						</label>

						<label class="booking-full-field">
							<span>Observații</span>
							<textarea name="notes" maxlength="1000" rows="4"></textarea>
						</label>

						<p class="booking-form-message" data-booking-form-message></p>
						<button type="submit" class="booking-submit">Trimite cererea</button>
					</form>
				</section>
			</div>
		</section>
	</main>

	<script src="../js/script.js"></script>
	<script src="../js/booking-availability.js?v=20260827-1"></script>
</body>
</html>
