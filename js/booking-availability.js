const bookingAvailability = document.querySelector('[data-booking-availability]');

if (bookingAvailability) {
	const serviceSelect = bookingAvailability.querySelector('[data-booking-service]');
	const specialistSelect = bookingAvailability.querySelector('[data-booking-specialist]');
	const dateInput = bookingAvailability.querySelector('[data-booking-date]');
	const statusText = bookingAvailability.querySelector('[data-booking-status]');
	const slotsGrid = bookingAvailability.querySelector('[data-booking-slots]');
	const detailsSection = bookingAvailability.querySelector('[data-booking-details]');
	const detailsForm = bookingAvailability.querySelector('[data-booking-details-form]');
	const formMessage = bookingAvailability.querySelector('[data-booking-form-message]');
	const summaryService = bookingAvailability.querySelector('[data-booking-summary-service]');
	const summarySpecialist = bookingAvailability.querySelector('[data-booking-summary-specialist]');
	const summaryDate = bookingAvailability.querySelector('[data-booking-summary-date]');
	const summaryTime = bookingAvailability.querySelector('[data-booking-summary-time]');
	const selectedDetails = bookingAvailability.querySelector('[data-booking-selected-details]');
	const selectedDuration = bookingAvailability.querySelector('[data-booking-selected-duration]');
	const selectedPrice = bookingAvailability.querySelector('[data-booking-selected-price]');

	const services = new Map();
	const specialists = new Map();
	let selectedSlot = '';
	let authenticatedUser = null;
	const initialParams = new URLSearchParams(window.location.search);
	const initialServiceId = initialParams.get('service_id') || '';
	const initialSpecialistId = initialParams.get('specialist_id') || '';

	const today = new Date();
	const todayValue = new Date(today.getTime() - today.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
	dateInput.min = todayValue;
	dateInput.value = todayValue;

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
		renderSelectedDetails(null);
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
			const error = new Error(data.error || data.message || 'Cererea nu a putut fi finalizată.');
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
			const error = new Error(data.message || data.error || 'Cererea nu a putut fi finalizată.');
			error.data = data;
			error.status = response.status;
			throw error;
		}

		return data;
	};

	const resetSpecialists = (message = 'Alege întâi serviciul') => {
		specialists.clear();
		specialistSelect.replaceChildren(new Option(message, ''));
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

	const showDetails = (slot) => {
		const selectedService = services.get(String(serviceSelect.value));
		const selectedSpecialist = specialists.get(String(specialistSelect.value));
		const durationMinutes = selectedSpecialist?.duration_minutes;

		selectedSlot = slot;
		summaryService.textContent = selectedService
			? `${selectedService.name}${durationMinutes ? ` (${durationMinutes} min)` : ''}`
			: serviceSelect.options[serviceSelect.selectedIndex]?.textContent || '-';
		summarySpecialist.textContent = selectedSpecialist
			? selectedSpecialist.name
			: specialistSelect.options[specialistSelect.selectedIndex]?.textContent || '-';
		summaryDate.textContent = formatDate(dateInput.value);
		summaryTime.textContent = slot;
		renderSelectedDetails(selectedSpecialist || null);

		removeConfirmation();
		detailsForm.hidden = false;
		detailsSection.hidden = false;
		setFormMessage('');
		prefillAuthenticatedUser();
		setStatus(`Ai selectat ora ${slot}. Completează datele și trimite cererea.`);
	};

	const renderSlots = (slots) => {
		clearSlots();

		if (!slots.length) {
			setStatus('Nu există sloturi disponibile pentru selecția curentă.');
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

			setStatus('Alege un serviciu, un specialist și o dată.');
		} catch (error) {
			serviceSelect.replaceChildren(new Option('Serviciile nu au putut fi încărcate', ''));
			setStatus(error.message);
		}
	};

	const loadSpecialists = async () => {
		clearSlots();
		hideDetails();

		if (!serviceSelect.value) {
			resetSpecialists();
			setStatus('Alege un serviciu, un specialist și o dată.');
			return;
		}

		resetSpecialists('Se încarcă specialiștii...');

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
			setStatus(data.specialists.length ? 'Alege specialistul și data.' : 'Nu există specialiști disponibili pentru acest serviciu.');
		} catch (error) {
			resetSpecialists('Specialiștii nu au putut fi încărcați');
			renderSelectedDetails(null);
			setStatus(error.message);
		}
	};

	const loadAvailability = async ({ preserveDetails = false } = {}) => {
		clearSlots();

		if (!preserveDetails) {
			hideDetails();
		}

		if (!serviceSelect.value || !specialistSelect.value || !dateInput.value) {
			setStatus('Alege un serviciu, un specialist și o dată.');
			return;
		}

		setStatus('Se verifică disponibilitatea...');

		const params = new URLSearchParams({
			service_id: serviceSelect.value,
			specialist_id: specialistSelect.value,
			date: dateInput.value,
		});

		try {
			const data = await apiGet(`../api/get-availability.php?${params.toString()}`);
			if (data.specialist?.id) {
				specialists.set(String(data.specialist.id), {
					...data.specialist,
					price: data.price,
					duration_minutes: data.duration_minutes,
				});
			}
			renderSelectedDetails({
				price: data.price,
				duration_minutes: data.duration_minutes,
			});
			renderSlots(data.slots || []);
		} catch (error) {
			renderSelectedDetails(null);
			setStatus(error.message);
		}
	};

	const showConfirmation = (appointment, message) => {
		const confirmation = document.createElement('div');
		confirmation.className = 'booking-confirmation';
		confirmation.dataset.bookingConfirmation = 'true';
		confirmation.innerHTML = `
			<p class="booking-confirmation-kicker">CERERE TRIMISĂ</p>
			<h2>Programarea ta este în așteptare.</h2>
			<p>${message || 'Te vom contacta pentru aprobare.'}</p>
			<p class="booking-confirmation-meta">Status: ${appointment.status || 'pending'}</p>
		`;

		detailsForm.hidden = true;
		removeConfirmation();
		detailsSection.append(confirmation);
		setStatus('Cererea a fost trimisă. Poți alege un alt interval pentru o altă programare.');
	};

	detailsForm.addEventListener('submit', async (event) => {
		event.preventDefault();

		if (!selectedSlot) {
			setFormMessage('Te rugăm să alegi o oră disponibilă.');
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
			service_id: serviceSelect.value,
			specialist_id: specialistSelect.value,
			date: dateInput.value,
			time: selectedSlot,
			customer_name: formData.get('customer_name'),
			customer_email: formData.get('customer_email'),
			customer_phone: formData.get('customer_phone'),
			notes: formData.get('notes'),
		};

		try {
			const data = await apiPost('../api/create-appointment.php', payload);
			showConfirmation(data.appointment || {}, data.message);
			selectedSlot = '';
			await loadAvailability({ preserveDetails: true });
			setStatus('Cererea a fost trimisă. Poți alege un alt interval pentru o altă programare.');
		} catch (error) {
			const errors = error.data?.errors ? Object.values(error.data.errors) : [];
			const message = errors.length ? errors.join(' ') : error.message;

			if (error.data?.code === 'slot_unavailable') {
				await loadAvailability();
				setStatus(error.message);
				setFormMessage('Intervalul nu mai este disponibil. Alege o altă oră.', 'error');
				return;
			}

			setFormMessage(message, 'error');
		} finally {
			submitButton.disabled = false;
		}
	});

	serviceSelect.addEventListener('change', async () => {
		await loadSpecialists();

		if (specialistSelect.value) {
			await loadAvailability();
		}
	});
	specialistSelect.addEventListener('change', loadAvailability);
	dateInput.addEventListener('change', loadAvailability);

	resetSpecialists();
	const initBookingForm = async () => {
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
