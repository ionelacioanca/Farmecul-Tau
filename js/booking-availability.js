const bookingAvailability = document.querySelector('[data-booking-availability]');

if (bookingAvailability) {
	const serviceField = bookingAvailability.querySelector('[data-booking-service-field]');
	const serviceSelect = bookingAvailability.querySelector('[data-booking-service]');
	const specialistSelect = bookingAvailability.querySelector('[data-booking-specialist]');
	const dateInput = bookingAvailability.querySelector('[data-booking-date]');
	const statusText = bookingAvailability.querySelector('[data-booking-status]');
	const slotsGrid = bookingAvailability.querySelector('[data-booking-slots]');
	const detailsSection = bookingAvailability.querySelector('[data-booking-details]');
	const detailsForm = bookingAvailability.querySelector('[data-booking-details-form]');
	const formMessage = bookingAvailability.querySelector('[data-booking-form-message]');
	const summaryBookableLabel = bookingAvailability.querySelector('[data-booking-summary-bookable-label]');
	const summaryService = bookingAvailability.querySelector('[data-booking-summary-service]');
	const summarySpecialist = bookingAvailability.querySelector('[data-booking-summary-specialist]');
	const summaryDate = bookingAvailability.querySelector('[data-booking-summary-date]');
	const summaryTime = bookingAvailability.querySelector('[data-booking-summary-time]');
	const selectedDetails = bookingAvailability.querySelector('[data-booking-selected-details]');
	const selectedDuration = bookingAvailability.querySelector('[data-booking-selected-duration]');
	const selectedPrice = bookingAvailability.querySelector('[data-booking-selected-price]');
	const offerSummary = bookingAvailability.querySelector('[data-booking-offer-summary]');
	const offerTitle = bookingAvailability.querySelector('[data-booking-offer-title]');

	const services = new Map();
	const specialists = new Map();
	let selectedSlot = '';
	let authenticatedUser = null;
	let offerState = null;
	const initialParams = new URLSearchParams(window.location.search);
	const initialServiceId = initialParams.get('service_id') || '';
	const initialSpecialistId = initialParams.get('specialist_id') || '';
	const initialOfferId = Number(bookingAvailability.dataset.initialOfferId || 0) > 0
		? String(bookingAvailability.dataset.initialOfferId)
		: '';
	const bookingMode = initialOfferId ? 'offer' : 'service';

	const today = new Date();
	const todayValue = new Date(today.getTime() - today.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
	dateInput.min = todayValue;
	dateInput.value = todayValue;

	if (bookingMode === 'offer') {
		if (serviceField) {
			serviceField.hidden = true;
		}

		if (serviceSelect) {
			serviceSelect.required = false;
			serviceSelect.disabled = true;
		}

		if (summaryBookableLabel) {
			summaryBookableLabel.textContent = 'Oferta';
		}
	}

	const setStatus = (message) => {
		statusText.textContent = message;
	};

	const setFormMessage = (message, type = 'error') => {
		formMessage.textContent = message;
		formMessage.dataset.type = type;
	};

	const formatPrice = (price) => new Intl.NumberFormat('ro-RO', {
		minimumFractionDigits: 0,
		maximumFractionDigits: 2,
	}).format(Number(price));

	const renderOfferSummary = () => {
		if (!offerSummary || !offerTitle) {
			return;
		}

		if (!offerState) {
			offerSummary.hidden = true;
			offerTitle.textContent = '';
			return;
		}

		offerTitle.textContent = offerState.title;
		offerSummary.hidden = false;
	};

	const renderSelectedDetails = (details) => {
		if (!selectedDetails || !selectedDuration || !selectedPrice) {
			return;
		}

		if (!details || details.duration_minutes == null || details.price == null) {
			selectedDetails.hidden = true;
			selectedDuration.textContent = '';
			selectedPrice.textContent = '';
			return;
		}

		selectedDuration.textContent = `Durata estimata: ${details.duration_minutes} min`;
		selectedPrice.textContent = `Pret: ${formatPrice(details.price)} lei`;
		selectedDetails.hidden = false;
	};

	const clearSlots = () => {
		slotsGrid.replaceChildren();
	};

	const removeConfirmation = () => {
		const confirmation = bookingAvailability.querySelector('[data-booking-confirmation]');

		if (confirmation) {
			confirmation.remove();
		}
	};

	const hideDetails = () => {
		selectedSlot = '';
		detailsSection.hidden = true;
		detailsForm.hidden = false;
		setFormMessage('');
		removeConfirmation();
		renderSelectedDetails(bookingMode === 'offer' ? offerState : null);
		bookingAvailability.querySelectorAll('.booking-slot').forEach((button) => {
			button.classList.remove('is-selected');
		});
	};

	const formatDate = (dateValue) => {
		const [year, month, day] = dateValue.split('-').map(Number);
		const date = new Date(year, month - 1, day);

		return new Intl.DateTimeFormat('ro-RO', {
			day: '2-digit',
			month: 'long',
			year: 'numeric',
		}).format(date);
	};

	const apiGet = async (url) => {
		const response = await fetch(url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		});
		const data = await response.json();

		if (!response.ok || !data.success) {
			const error = new Error(data.error || data.message || 'Cererea nu a putut fi finalizata.');
			error.data = data;
			error.status = response.status;
			throw error;
		}

		return data;
	};

	const apiPost = async (url, payload) => {
		const response = await fetch(url, {
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
			const error = new Error(data.message || data.error || 'Cererea nu a putut fi finalizata.');
			error.data = data;
			error.status = response.status;
			throw error;
		}

		return data;
	};

	const resetSpecialists = (message = null) => {
		specialists.clear();
		specialistSelect.replaceChildren(new Option(message || (bookingMode === 'offer' ? 'Se incarca specialistii...' : 'Alege intai serviciul'), ''));
		specialistSelect.disabled = true;
	};

	const prefillAuthenticatedUser = () => {
		if (!authenticatedUser) {
			return;
		}

		const nameField = detailsForm.elements.customer_name;
		const emailField = detailsForm.elements.customer_email;

		if (!nameField.value) {
			nameField.value = authenticatedUser.name || '';
		}

		if (!emailField.value) {
			emailField.value = authenticatedUser.email || '';
		}
	};

	const getSelectedBookableDetails = () => {
		if (bookingMode === 'offer') {
			return offerState;
		}

		return services.get(String(serviceSelect.value)) || null;
	};

	const showDetails = (slot) => {
		const selectedBookable = getSelectedBookableDetails();
		const selectedSpecialist = specialists.get(String(specialistSelect.value));
		const durationMinutes = bookingMode === 'offer'
			? offerState?.duration_minutes
			: selectedSpecialist?.duration_minutes;
		const bookableName = bookingMode === 'offer'
			? offerState?.title
			: selectedBookable?.name;

		selectedSlot = slot;
		summaryService.textContent = bookableName
			? `${bookableName}${durationMinutes ? ` (${durationMinutes} min)` : ''}`
			: '-';
		summarySpecialist.textContent = selectedSpecialist
			? selectedSpecialist.name
			: specialistSelect.options[specialistSelect.selectedIndex]?.textContent || '-';
		summaryDate.textContent = formatDate(dateInput.value);
		summaryTime.textContent = slot;
		renderSelectedDetails(bookingMode === 'offer' ? offerState : selectedSpecialist || null);

		removeConfirmation();
		detailsForm.hidden = false;
		detailsSection.hidden = false;
		setFormMessage('');
		prefillAuthenticatedUser();
		setStatus(`Ai selectat ora ${slot}. Completeaza datele si trimite cererea.`);
	};

	const renderSlots = (slots) => {
		clearSlots();

		if (!slots.length) {
			setStatus('Nu exista sloturi disponibile pentru selectia curenta.');
			return;
		}

		setStatus('Sloturi disponibile');

		slots.forEach((slot) => {
			const button = document.createElement('button');
			button.className = 'booking-slot';
			button.type = 'button';
			button.textContent = slot;
			button.addEventListener('click', () => {
				bookingAvailability.querySelectorAll('.booking-slot').forEach((slotButton) => {
					slotButton.classList.remove('is-selected');
				});
				button.classList.add('is-selected');
				showDetails(slot);
			});
			slotsGrid.append(button);
		});
	};

	const loadAuthStatus = async () => {
		try {
			const data = await apiGet('../api/auth-status.php');
			authenticatedUser = data.authenticated ? data.user : null;
			prefillAuthenticatedUser();
		} catch (error) {
			authenticatedUser = null;
		}
	};

	const loadServices = async () => {
		try {
			const data = await apiGet('../api/get-services.php');
			services.clear();
			serviceSelect.replaceChildren(new Option('Alege serviciul', ''));

			data.services.forEach((service) => {
				services.set(String(service.id), service);
				serviceSelect.append(new Option(service.name, service.id));
			});

			if (initialServiceId && services.has(initialServiceId)) {
				serviceSelect.value = initialServiceId;
			}

			setStatus('Alege un serviciu, un specialist si o data.');
		} catch (error) {
			serviceSelect.replaceChildren(new Option('Serviciile nu au putut fi incarcate', ''));
			setStatus(error.message);
		}
	};

	const loadSpecialists = async () => {
		clearSlots();
		hideDetails();

		if (!serviceSelect.value) {
			resetSpecialists();
			setStatus('Alege un serviciu, un specialist si o data.');
			return;
		}

		resetSpecialists('Se incarca specialistii...');

		try {
			const data = await apiGet(`../api/get-specialists.php?service_id=${encodeURIComponent(serviceSelect.value)}`);
			specialistSelect.replaceChildren(new Option('Alege specialistul', ''));

			data.specialists.forEach((specialist) => {
				specialists.set(String(specialist.id), specialist);
				const label = specialist.duration_minutes && specialist.price != null
					? `${specialist.name} - ${specialist.duration_minutes} min, ${formatPrice(specialist.price)} lei`
					: specialist.name;
				specialistSelect.append(new Option(label, specialist.id));
			});

			if (initialSpecialistId && data.specialists.some((specialist) => String(specialist.id) === initialSpecialistId)) {
				specialistSelect.value = initialSpecialistId;
			} else if (data.specialists.length === 1) {
				specialistSelect.value = String(data.specialists[0].id);
			}

			specialistSelect.disabled = data.specialists.length <= 1;
			if (data.specialists.length === 0) {
				renderSelectedDetails(null);
				setStatus('Momentan nu exista specialisti disponibili pentru acest serviciu.');
				return;
			} else if (data.specialists.length === 1) {
				renderSelectedDetails(data.specialists[0]);
				setStatus('Specialistul a fost selectat automat. Alege data.');
				return;
			}
			renderSelectedDetails(null);
			setStatus('Alege specialistul si data.');
		} catch (error) {
			resetSpecialists('Specialistii nu au putut fi incarcati');
			renderSelectedDetails(null);
			setStatus(error.message);
		}
	};

	const loadOfferSpecialists = async () => {
		clearSlots();
		hideDetails();
		resetSpecialists('Se incarca specialistii...');
		setStatus('Se incarca oferta...');

		const params = new URLSearchParams({
			offer_id: initialOfferId,
			date: dateInput.value,
		});

		try {
			const data = await apiGet(`../api/get-offer-specialists.php?${params.toString()}`);
			offerState = data.offer;
			renderOfferSummary();
			renderSelectedDetails(offerState);

			const effectiveMin = offerState.start_date && offerState.start_date > todayValue ? offerState.start_date : todayValue;
			dateInput.min = effectiveMin;
			dateInput.max = offerState.end_date || '';

			if (dateInput.value < effectiveMin) {
				dateInput.value = effectiveMin;
			}

			if (offerState.end_date && dateInput.value > offerState.end_date) {
				dateInput.value = offerState.end_date;
			}

			specialistSelect.replaceChildren(new Option('Alege specialistul', ''));
			data.specialists.forEach((specialist) => {
				specialists.set(String(specialist.id), specialist);
				const label = `${specialist.name} - ${offerState.duration_minutes} min, ${formatPrice(offerState.price)} lei`;
				specialistSelect.append(new Option(label, specialist.id));
			});

			if (initialSpecialistId && data.specialists.some((specialist) => String(specialist.id) === initialSpecialistId)) {
				specialistSelect.value = initialSpecialistId;
			} else if (data.specialists.length === 1) {
				specialistSelect.value = String(data.specialists[0].id);
			}

			specialistSelect.disabled = data.specialists.length <= 1;

			if (data.specialists.length === 0) {
				setStatus('Momentan nu exista specialisti eligibili pentru aceasta oferta.');
				return;
			}

			setStatus(data.specialists.length === 1 ? 'Specialistul a fost selectat automat. Alege data.' : 'Alege specialistul si data.');
		} catch (error) {
			offerState = null;
			renderOfferSummary();
			renderSelectedDetails(null);
			resetSpecialists('Specialistii nu au putut fi incarcati');
			setStatus(error.message);
		}
	};

	const loadAvailability = async ({ preserveDetails = false } = {}) => {
		clearSlots();

		if (!preserveDetails) {
			hideDetails();
		}

		const missingServiceSelection = bookingMode === 'service' && !serviceSelect.value;
		const missingOfferSelection = bookingMode === 'offer' && !initialOfferId;

		if (missingServiceSelection || missingOfferSelection || !specialistSelect.value || !dateInput.value) {
			setStatus(bookingMode === 'offer' ? 'Alege specialistul si data.' : 'Alege un serviciu, un specialist si o data.');
			return;
		}

		setStatus('Se verifica disponibilitatea...');

		const params = new URLSearchParams({
			specialist_id: specialistSelect.value,
			date: dateInput.value,
		});

		if (bookingMode === 'offer') {
			params.set('offer_id', initialOfferId);
		} else {
			params.set('service_id', serviceSelect.value);
		}

		const endpoint = bookingMode === 'offer' ? '../api/get-offer-availability.php' : '../api/get-availability.php';

		try {
			const data = await apiGet(`${endpoint}?${params.toString()}`);
			if (data.specialist?.id) {
				specialists.set(String(data.specialist.id), {
					...data.specialist,
					price: data.price,
					duration_minutes: data.duration_minutes,
				});
			}

			if (bookingMode === 'offer' && data.offer) {
				offerState = data.offer;
				renderOfferSummary();
			}

			renderSelectedDetails({
				price: data.price,
				duration_minutes: data.duration_minutes,
			});
			renderSlots(data.slots || []);
		} catch (error) {
			renderSelectedDetails(bookingMode === 'offer' ? offerState : null);
			setStatus(error.message);
		}
	};

	const showConfirmation = (appointment, message) => {
		const confirmation = document.createElement('div');
		confirmation.className = 'booking-confirmation';
		confirmation.dataset.bookingConfirmation = 'true';
		confirmation.innerHTML = `
			<p class="booking-confirmation-kicker">CERERE TRIMISA</p>
			<h2>Programarea ta este in asteptare.</h2>
			<p>${message || 'Te vom contacta pentru aprobare.'}</p>
			<p class="booking-confirmation-meta">Status: ${appointment.status || 'pending'}</p>
		`;

		detailsForm.hidden = true;
		removeConfirmation();
		detailsSection.append(confirmation);
		setStatus('Cererea a fost trimisa. Poti alege un alt interval pentru o alta programare.');
	};

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
			booking_type: bookingMode,
			specialist_id: specialistSelect.value,
			date: dateInput.value,
			time: selectedSlot,
			customer_name: formData.get('customer_name'),
			customer_email: formData.get('customer_email'),
			customer_phone: formData.get('customer_phone'),
			notes: formData.get('notes'),
		};

		if (bookingMode === 'offer') {
			payload.offer_id = initialOfferId;
		} else {
			payload.service_id = serviceSelect.value;
		}

		try {
			const data = await apiPost('../api/create-appointment.php', payload);
			showConfirmation(data.appointment || {}, data.message);
			selectedSlot = '';
			await loadAvailability({ preserveDetails: true });
			setStatus('Cererea a fost trimisa. Poti alege un alt interval pentru o alta programare.');
		} catch (error) {
			const errors = error.data?.errors ? Object.values(error.data.errors) : [];
			const message = errors.length ? errors.join(' ') : error.message;

			if (error.data?.code === 'slot_unavailable') {
				await loadAvailability();
				setStatus(error.message);
				setFormMessage('Intervalul nu mai este disponibil. Alege o alta ora.', 'error');
				return;
			}

			setFormMessage(message, 'error');
		} finally {
			submitButton.disabled = false;
		}
	});

	if (bookingMode === 'service') {
		serviceSelect.addEventListener('change', async () => {
			await loadSpecialists();

			if (specialistSelect.value) {
				await loadAvailability();
			}
		});
		dateInput.addEventListener('change', loadAvailability);
	} else {
		dateInput.addEventListener('change', async () => {
			await loadOfferSpecialists();

			if (specialistSelect.value) {
				await loadAvailability();
			}
		});
	}

	specialistSelect.addEventListener('change', loadAvailability);

	resetSpecialists();
	const initBookingForm = async () => {
		if (bookingMode === 'offer') {
			await loadAuthStatus();
			await loadOfferSpecialists();

			if (specialistSelect.value) {
				await loadAvailability();
			}
			return;
		}

		await Promise.all([loadAuthStatus(), loadServices()]);

		if (serviceSelect.value) {
			await loadSpecialists();

			if (specialistSelect.value) {
				await loadAvailability();
			}
		}
	};

	initBookingForm();
}
