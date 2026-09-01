document.querySelectorAll('[data-offer-booking]').forEach((panel) => {
	const card = panel.closest('.offer-card');
	const toggle = card?.querySelector('[data-offer-booking-toggle]');
	const specialistField = panel.querySelector('[data-offer-specialist-field]');
	const specialistSelect = panel.querySelector('[data-offer-specialist]');
	const dateField = panel.querySelector('[data-offer-date-field]');
	const dateInput = panel.querySelector('[data-offer-date]');
	const slotsSection = panel.querySelector('[data-offer-slots-section]');
	const statusText = panel.querySelector('[data-offer-status]');
	const slotsGrid = panel.querySelector('[data-offer-slots]');
	const detailsSection = panel.querySelector('[data-offer-details]');
	const detailsForm = panel.querySelector('[data-offer-details-form]');
	const formMessage = panel.querySelector('[data-offer-form-message]');
	const summarySpecialist = panel.querySelector('[data-offer-summary-specialist]');
	const summaryDate = panel.querySelector('[data-offer-summary-date]');
	const summaryTime = panel.querySelector('[data-offer-summary-time]');
	const summaryPrice = panel.querySelector('[data-offer-summary-price]');
	const summaryDuration = panel.querySelector('[data-offer-summary-duration]');
	const contactFields = {
		name: panel.querySelector('[data-offer-contact-field="name"]'),
		email: panel.querySelector('[data-offer-contact-field="email"]'),
		phone: panel.querySelector('[data-offer-contact-field="phone"]'),
		notes: panel.querySelector('[data-offer-contact-field="notes"]'),
	};

	if (
		!toggle
		|| !specialistField
		|| !specialistSelect
		|| !dateField
		|| !dateInput
		|| !slotsSection
		|| !statusText
		|| !slotsGrid
		|| !detailsSection
		|| !detailsForm
		|| !formMessage
	) {
		return;
	}

	const offerId = panel.dataset.offerId;
	const specialists = new Map();
	let offerState = {
		id: Number(offerId),
		title: panel.dataset.offerTitle || '',
		price: Number(panel.dataset.offerPrice || 0),
		duration_minutes: Number(panel.dataset.offerDuration || 0),
	};
	let selectedSlot = '';
	let authenticatedUser = null;
	let hasLoadedSpecialists = false;

	const today = new Date();
	const todayValue = new Date(today.getTime() - today.getTimezoneOffset() * 60000).toISOString().slice(0, 10);

	const formatPrice = (price) => new Intl.NumberFormat('ro-RO', {
		minimumFractionDigits: 0,
		maximumFractionDigits: 2,
	}).format(Number(price));

	const formatDate = (dateValue) => {
		const [year, month, day] = dateValue.split('-').map(Number);
		const date = new Date(year, month - 1, day);

		return new Intl.DateTimeFormat('ro-RO', {
			day: '2-digit',
			month: 'long',
			year: 'numeric',
		}).format(date);
	};

	const setStatus = (message) => {
		statusText.textContent = message;
	};

	const setFormMessage = (message, type = 'error') => {
		formMessage.textContent = message;
		formMessage.dataset.type = type;
	};

	const requestJson = async (url, options = {}) => {
		const response = await fetch(url, {
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				...(options.headers || {}),
			},
			...options,
		});
		const data = await response.json();

		if (!response.ok || !data.success) {
			const error = new Error(data.message || data.error || 'Cererea nu a putut fi finalizata.');
			error.data = data;
			error.status = response.status;
			throw error;
		}

		return data;
	};

	const clearSlots = () => {
		slotsGrid.replaceChildren();
	};

	const resetDetails = () => {
		selectedSlot = '';
		detailsSection.hidden = true;
		detailsForm.hidden = false;
		setFormMessage('');
		panel.querySelectorAll('.booking-slot').forEach((button) => {
			button.classList.remove('is-selected');
		});
	};

	const setFieldState = (field, hidden, required = false) => {
		if (!field) {
			return;
		}

		const control = field.querySelector('input, textarea');
		field.hidden = hidden;

		if (control) {
			control.required = required;
			control.disabled = hidden;
		}
	};

	const configureContactFields = () => {
		const isAuthenticated = Boolean(authenticatedUser);

		setFieldState(contactFields.name, isAuthenticated, !isAuthenticated);
		setFieldState(contactFields.email, isAuthenticated, !isAuthenticated);
		setFieldState(contactFields.phone, isAuthenticated, !isAuthenticated);
		setFieldState(contactFields.notes, false, false);
	};

	const loadAuthStatus = async () => {
		try {
			const data = await requestJson('../api/auth-status.php');
			authenticatedUser = data.authenticated ? data.user : null;
			configureContactFields();
		} catch (error) {
			authenticatedUser = null;
			configureContactFields();
		}
	};

	const applyOfferDateWindow = () => {
		const effectiveMin = offerState.start_date && offerState.start_date > todayValue
			? offerState.start_date
			: todayValue;

		dateInput.min = effectiveMin;
		dateInput.max = offerState.end_date || '';

		if (!dateInput.value || dateInput.value < effectiveMin) {
			dateInput.value = effectiveMin;
		}

		if (offerState.end_date && dateInput.value > offerState.end_date) {
			dateInput.value = offerState.end_date;
		}
	};

	const loadOfferSpecialists = async () => {
		clearSlots();
		resetDetails();
		slotsSection.hidden = true;
		dateField.hidden = true;
		specialistField.hidden = false;
		specialistSelect.disabled = true;
		specialistSelect.replaceChildren(new Option('Se incarca specialistii...', ''));
		setStatus('Se incarca oferta...');

		const params = new URLSearchParams({
			offer_id: offerId,
			date: todayValue,
		});

		const data = await requestJson(`../api/get-offer-specialists.php?${params.toString()}`);
		offerState = data.offer;
		specialists.clear();
		applyOfferDateWindow();

		if (summaryPrice) {
			summaryPrice.textContent = `${formatPrice(offerState.price)} lei`;
		}

		if (data.specialists.length === 0) {
			specialistSelect.replaceChildren(new Option('Niciun specialist disponibil', ''));
			setStatus('Momentan nu exista specialisti eligibili pentru aceasta oferta.');
			return;
		}

		data.specialists.forEach((specialist) => {
			specialists.set(String(specialist.id), specialist);
		});

		if (data.specialists.length === 1) {
			const [specialist] = data.specialists;
			specialistSelect.replaceChildren(new Option(specialist.name, specialist.id));
			specialistSelect.value = String(specialist.id);
			specialistField.hidden = true;
			dateField.hidden = false;
			slotsSection.hidden = false;
			setStatus('Specialistul a fost selectat automat. Alege data.');
			await loadAvailability();
			return;
		}

		specialistSelect.replaceChildren(new Option('Alege specialistul', ''));
		data.specialists.forEach((specialist) => {
			specialistSelect.append(new Option(specialist.name, specialist.id));
		});
		specialistSelect.disabled = false;
		setStatus('Alege specialistul.');
	};

	const renderSlots = (slots) => {
		clearSlots();

		if (!slots.length) {
			setStatus('Nu mai sunt intervale disponibile in aceasta zi.');
			return;
		}

		setStatus('Alege ora dorita.');

		slots.forEach((slot) => {
			const button = document.createElement('button');
			button.className = 'booking-slot';
			button.type = 'button';
			button.textContent = slot;
			button.addEventListener('click', () => {
				panel.querySelectorAll('.booking-slot').forEach((slotButton) => {
					slotButton.classList.remove('is-selected');
				});
				button.classList.add('is-selected');
				showCustomerDetails(slot);
			});
			slotsGrid.append(button);
		});
	};

	async function loadAvailability({ preserveDetails = false } = {}) {
		clearSlots();

		if (!preserveDetails) {
			resetDetails();
		}

		if (!specialistSelect.value) {
			dateField.hidden = true;
			slotsSection.hidden = true;
			setStatus('Alege specialistul.');
			return;
		}

		dateField.hidden = false;
		slotsSection.hidden = false;

		if (!dateInput.value) {
			setStatus('Alege data.');
			return;
		}

		setStatus('Se verifica disponibilitatea...');

		const params = new URLSearchParams({
			offer_id: offerId,
			specialist_id: specialistSelect.value,
			date: dateInput.value,
		});

		try {
			const data = await requestJson(`../api/get-offer-availability.php?${params.toString()}`);
			if (data.offer) {
				offerState = {
					...offerState,
					...data.offer,
				};
			}

			if (data.specialist?.id) {
				specialists.set(String(data.specialist.id), data.specialist);
			}

			renderSlots(data.slots || []);
		} catch (error) {
			const message = error.status === 404
				? 'Oferta nu este disponibila pentru specialistul sau data aleasa.'
				: error.message;
			setStatus(message);
		}
	}

	function showCustomerDetails(slot) {
		const selectedSpecialist = specialists.get(String(specialistSelect.value));
		selectedSlot = slot;

		if (summarySpecialist) {
			summarySpecialist.textContent = selectedSpecialist?.name || '-';
		}
		if (summaryDate) {
			summaryDate.textContent = formatDate(dateInput.value);
		}
		if (summaryTime) {
			summaryTime.textContent = slot;
		}
		if (summaryPrice) {
			summaryPrice.textContent = `${formatPrice(offerState.price)} lei`;
		}
		if (summaryDuration) {
			summaryDuration.textContent = `${offerState.duration_minutes} min`;
		}

		detailsForm.hidden = false;
		detailsSection.hidden = false;
		setFormMessage('');
		configureContactFields();
		setStatus(authenticatedUser
			? `Ai selectat ora ${slot}. Verifica rezumatul si trimite programarea.`
			: `Ai selectat ora ${slot}. Completeaza datele si trimite cererea.`);
	}

	const showConfirmation = (appointment) => {
		const selectedSpecialist = specialists.get(String(specialistSelect.value));
		const confirmation = document.createElement('div');
		confirmation.className = 'booking-confirmation offer-booking-confirmation';
		confirmation.dataset.offerConfirmation = 'true';

		const kicker = document.createElement('p');
		kicker.className = 'booking-confirmation-kicker';
		kicker.textContent = 'CERERE TRIMISA';

		const title = document.createElement('h2');
		title.textContent = `Programarea pentru oferta "${offerState.title}" a fost trimisa catre salon si asteapta confirmarea.`;

		const meta = document.createElement('p');
		meta.className = 'booking-confirmation-meta';
		meta.textContent = [
			selectedSpecialist?.name || 'Specialist',
			formatDate(appointment.date || dateInput.value),
			appointment.time || selectedSlot,
			`${formatPrice(appointment.price_at_booking ?? offerState.price)} lei`,
		].join(' | ');

		const status = document.createElement('p');
		status.textContent = 'Status: pending';

		confirmation.append(kicker, title, meta, status);
		detailsSection.replaceChildren(confirmation);
		detailsSection.hidden = false;
		specialistSelect.disabled = true;
		dateInput.disabled = true;
		slotsSection.hidden = true;
		setStatus('Cererea a fost trimisa.');
	};

	const openPanel = async () => {
		document.querySelectorAll('[data-offer-booking]').forEach((otherPanel) => {
			if (otherPanel !== panel) {
				otherPanel.hidden = true;
				const otherToggle = otherPanel.closest('.offer-card')?.querySelector('[data-offer-booking-toggle]');
				if (otherToggle) {
					otherToggle.setAttribute('aria-expanded', 'false');
					otherToggle.textContent = 'PROGRAMEAZA-TE';
				}
			}
		});

		panel.hidden = false;
		toggle.setAttribute('aria-expanded', 'true');
		toggle.textContent = 'INCHIDE PROGRAMAREA';

		if (!hasLoadedSpecialists) {
			try {
				await Promise.all([loadAuthStatus(), loadOfferSpecialists()]);
				hasLoadedSpecialists = true;
			} catch (error) {
				setStatus(error.message);
			}
		}

		panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	};

	const closePanel = () => {
		panel.hidden = true;
		toggle.setAttribute('aria-expanded', 'false');
		toggle.textContent = 'PROGRAMEAZA-TE';
	};

	toggle.addEventListener('click', () => {
		if (panel.hidden) {
			openPanel();
			return;
		}

		closePanel();
	});

	specialistSelect.addEventListener('change', loadAvailability);
	dateInput.addEventListener('change', loadAvailability);

	detailsForm.addEventListener('submit', async (event) => {
		event.preventDefault();

		if (!selectedSlot) {
			setFormMessage('Te rugam sa alegi o ora disponibila.');
			return;
		}

		if (!detailsForm.reportValidity()) {
			return;
		}

		const submitButton = detailsForm.querySelector('.booking-submit');
		submitButton.disabled = true;
		setFormMessage('Se trimite cererea...', 'info');

		const formData = new FormData(detailsForm);
		const payload = {
			booking_type: 'offer',
			offer_id: offerId,
			specialist_id: specialistSelect.value,
			date: dateInput.value,
			time: selectedSlot,
			notes: formData.get('notes'),
		};

		if (authenticatedUser) {
			payload.notes = formData.get('notes');
		} else {
			payload.customer_name = formData.get('customer_name');
			payload.customer_email = formData.get('customer_email');
			payload.customer_phone = formData.get('customer_phone');
		}

		try {
			const data = await requestJson('../api/create-appointment.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload),
			});
			showConfirmation(data.appointment || {});
			selectedSlot = '';
		} catch (error) {
			const errors = error.data?.errors ? Object.values(error.data.errors) : [];
			const message = errors.length ? errors.join(' ') : error.message;

			if (error.data?.code === 'slot_unavailable') {
				await loadAvailability();
				setStatus('Ora selectata nu mai este disponibila. Alege un alt interval.');
				return;
			}

			setFormMessage(message, 'error');
		} finally {
			submitButton.disabled = false;
		}
	});
});
