const ownManualBooking = document.querySelector('[data-own-manual-booking]');

if (ownManualBooking) {
	const specialistId = ownManualBooking.dataset.specialistId || '';
	const serviceSelect = ownManualBooking.querySelector('[data-own-service]');
	const dateInput = ownManualBooking.querySelector('[data-own-date]');
	const timeSelect = ownManualBooking.querySelector('[data-own-time]');
	const statusText = ownManualBooking.querySelector('[data-own-booking-status]');

	const today = new Date();
	const todayValue = new Date(today.getTime() - today.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
	dateInput.min = todayValue;

	if (!dateInput.value) {
		dateInput.value = todayValue;
	}

	const setStatus = (message, type = 'info') => {
		statusText.textContent = message;
		statusText.dataset.type = type;
	};

	const formatPrice = (price) => new Intl.NumberFormat('ro-RO', {
		minimumFractionDigits: 0,
		maximumFractionDigits: 2,
	}).format(Number(price));

	const requestJson = async (url) => {
		const response = await fetch(url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		});
		const data = await response.json();

		if (!response.ok || !data.success) {
			throw new Error(data.error || data.message || 'Datele nu au putut fi incarcate.');
		}

		return data;
	};

	const resetTimes = (message = 'Alege serviciul si data') => {
		timeSelect.replaceChildren(new Option(message, ''));
		timeSelect.disabled = true;
	};

	const loadAvailability = async () => {
		resetTimes();

		if (!specialistId || !serviceSelect.value || !dateInput.value) {
			setStatus('Alege serviciul si data.');
			return;
		}

		setStatus('Se verifica disponibilitatea...');

		const params = new URLSearchParams({
			service_id: serviceSelect.value,
			specialist_id: specialistId,
			date: dateInput.value,
		});

		try {
			const data = await requestJson(`../api/get-availability.php?${params.toString()}`);
			const selectedTime = timeSelect.dataset.selectedValue || '';
			timeSelect.replaceChildren(new Option('Alege ora', ''));

			(data.slots || []).forEach((slot) => {
				const option = new Option(slot, slot);
				option.selected = selectedTime === slot;
				timeSelect.append(option);
			});

			timeSelect.disabled = (data.slots || []).length === 0;
			const details = data.duration_minutes && data.price != null
				? `Durata: ${data.duration_minutes} min. Pret: ${formatPrice(data.price)} lei.`
				: '';
			setStatus(timeSelect.disabled ? `Nu exista sloturi disponibile pentru selectia curenta. ${details}` : `Alege ora disponibila. ${details}`);
			timeSelect.dataset.selectedValue = '';
		} catch (error) {
			resetTimes('Orele nu au putut fi incarcate');
			setStatus(error.message, 'error');
		}
	};

	serviceSelect.addEventListener('change', loadAvailability);
	dateInput.addEventListener('change', loadAvailability);
	timeSelect.addEventListener('change', () => {
		timeSelect.dataset.selectedValue = timeSelect.value;
	});

	if (serviceSelect.value) {
		loadAvailability();
	} else {
		resetTimes();
		setStatus('Alege serviciul si data.');
	}
}
