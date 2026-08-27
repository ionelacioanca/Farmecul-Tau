const adminManualBooking = document.querySelector('[data-admin-manual-booking]');

if (adminManualBooking) {
	const serviceSelect = adminManualBooking.querySelector('[data-admin-service]');
	const specialistSelect = adminManualBooking.querySelector('[data-admin-specialist]');
	const dateInput = adminManualBooking.querySelector('[data-admin-date]');
	const timeSelect = adminManualBooking.querySelector('[data-admin-time]');
	const statusText = adminManualBooking.querySelector('[data-admin-booking-status]');

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

	const requestJson = async (url) => {
		const response = await fetch(url, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		});
		const data = await response.json();

		if (!response.ok || !data.success) {
			throw new Error(data.error || data.message || 'Datele nu au putut fi încărcate.');
		}

		return data;
	};

	const resetSpecialists = (message = 'Alege întâi serviciul') => {
		specialistSelect.replaceChildren(new Option(message, ''));
		specialistSelect.disabled = true;
		specialistSelect.dataset.selectedValue = '';
	};

	const resetTimes = (message = 'Alege serviciu, specialist și dată') => {
		timeSelect.replaceChildren(new Option(message, ''));
		timeSelect.disabled = true;
	};

	const loadAvailability = async () => {
		resetTimes();

		if (!serviceSelect.value || !specialistSelect.value || !dateInput.value) {
			setStatus('Alege serviciul, specialistul și data.');
			return;
		}

		setStatus('Se verifică disponibilitatea...');

		const params = new URLSearchParams({
			service_id: serviceSelect.value,
			specialist_id: specialistSelect.value,
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
			setStatus(timeSelect.disabled ? 'Nu există sloturi disponibile pentru selecția curentă.' : 'Alege ora disponibilă.');
			timeSelect.dataset.selectedValue = '';
		} catch (error) {
			resetTimes('Orele nu au putut fi încărcate');
			setStatus(error.message, 'error');
		}
	};

	const loadSpecialists = async () => {
		resetSpecialists('Se încarcă specialiștii...');
		resetTimes();

		if (!serviceSelect.value) {
			resetSpecialists();
			setStatus('Alege serviciul.');
			return;
		}

		try {
			const data = await requestJson(`../api/get-specialists.php?service_id=${encodeURIComponent(serviceSelect.value)}`);
			const selectedSpecialist = specialistSelect.dataset.selectedValue || '';
			specialistSelect.replaceChildren(new Option('Alege specialistul', ''));

			data.specialists.forEach((specialist) => {
				const option = new Option(specialist.name, specialist.id);
				option.selected = selectedSpecialist === String(specialist.id);
				specialistSelect.append(option);
			});

			specialistSelect.disabled = data.specialists.length === 0;
			specialistSelect.dataset.selectedValue = '';
			setStatus(data.specialists.length ? 'Alege data și ora.' : 'Nu există specialiști pentru serviciul ales.');

			if (specialistSelect.value) {
				await loadAvailability();
			}
		} catch (error) {
			resetSpecialists('Specialiștii nu au putut fi încărcați');
			setStatus(error.message, 'error');
		}
	};

	serviceSelect.addEventListener('change', loadSpecialists);
	specialistSelect.addEventListener('change', loadAvailability);
	dateInput.addEventListener('change', loadAvailability);
	timeSelect.addEventListener('change', () => {
		timeSelect.dataset.selectedValue = timeSelect.value;
	});

	if (serviceSelect.value) {
		loadSpecialists();
	} else {
		resetSpecialists();
		resetTimes();
		setStatus('Alege serviciul.');
	}
}
