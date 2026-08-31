document.querySelectorAll('form[data-offer-form]').forEach((form) => {
	const serviceCheckboxes = Array.from(form.querySelectorAll('[data-offer-service-checkbox]'));
	const specialistsFieldset = form.querySelector('[data-offer-specialists-fieldset]');
	const specialistsList = form.querySelector('[data-offer-specialists-list]');
	const specialistsMessage = form.querySelector('[data-offer-specialists-message]');
	const submitButton = form.querySelector('button[type="submit"]');
	let selectedSpecialistIds = [];

	try {
		selectedSpecialistIds = JSON.parse(specialistsFieldset?.dataset.selectedSpecialistIds || '[]').map(String);
	} catch (error) {
		selectedSpecialistIds = [];
	}

	const setMessage = (message, type = 'info') => {
		if (!specialistsMessage) {
			return;
		}

		specialistsMessage.textContent = message;
		specialistsMessage.dataset.type = type;
		specialistsMessage.hidden = message === '';
	};

	const setSubmitEnabled = (enabled) => {
		if (submitButton) {
			submitButton.disabled = !enabled;
		}
	};

	const getSelectedServiceIds = () => serviceCheckboxes
		.filter((checkbox) => checkbox.checked)
		.map((checkbox) => checkbox.value);

	const renderSpecialists = (specialists) => {
		specialistsList.replaceChildren();

		specialists.forEach((specialist) => {
			const id = String(specialist.id);
			const label = document.createElement('label');
			label.className = 'admin-checkbox-label';

			const input = document.createElement('input');
			input.type = 'checkbox';
			input.name = 'specialist_ids[]';
			input.value = id;
			input.checked = selectedSpecialistIds.includes(id);
			input.addEventListener('change', () => {
				selectedSpecialistIds = Array.from(form.querySelectorAll('input[name="specialist_ids[]"]:checked'))
					.map((checkbox) => checkbox.value);
				setSubmitEnabled(selectedSpecialistIds.length > 0);
			});

			const span = document.createElement('span');
			span.textContent = specialist.name;

			label.append(input, span);
			specialistsList.append(label);
		});

		selectedSpecialistIds = selectedSpecialistIds.filter((id) => specialists.some((specialist) => String(specialist.id) === id));

		if (specialistsFieldset) {
			specialistsFieldset.dataset.selectedSpecialistIds = JSON.stringify(selectedSpecialistIds);
		}
	};

	const loadEligibleSpecialists = async () => {
		const serviceIds = getSelectedServiceIds();
		specialistsList.replaceChildren();

		if (serviceIds.length === 0) {
			selectedSpecialistIds = [];
			setMessage('Selecteaza mai intai serviciile incluse in oferta.');
			setSubmitEnabled(false);
			return;
		}

		setMessage('Se incarca specialistii disponibili...');
		setSubmitEnabled(false);
		const previouslySelectedIds = [...selectedSpecialistIds];

		const params = new URLSearchParams();
		serviceIds.forEach((serviceId) => params.append('service_ids[]', serviceId));

		try {
			const response = await fetch(`api/get-eligible-offer-specialists.php?${params.toString()}`, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			});
			const data = await response.json();

			if (!response.ok || !data.success) {
				throw new Error(data.error || 'Specialistii disponibili nu au putut fi incarcati.');
			}

			const specialists = data.specialists || [];
			renderSpecialists(specialists);
			const eligibleIds = specialists.map((specialist) => String(specialist.id));
			const removedSelectedIds = previouslySelectedIds.filter((id) => !eligibleIds.includes(id));

			if (specialists.length === 0) {
				setMessage('Niciun specialist nu ofera toate serviciile selectate.', 'error');
				setSubmitEnabled(false);
				return;
			}

			if (removedSelectedIds.length > 0) {
				setMessage('Specialistii salvati anterior nu mai sunt eligibili. Alege specialistii disponibili si salveaza oferta.', 'error');
				setSubmitEnabled(false);
				return;
			}

			setMessage('Alege specialistii care participa la oferta.');
			setSubmitEnabled(selectedSpecialistIds.length > 0);
		} catch (error) {
			setMessage(error.message, 'error');
			setSubmitEnabled(false);
		}
	};

	serviceCheckboxes.forEach((checkbox) => {
		checkbox.addEventListener('change', () => {
			selectedSpecialistIds = Array.from(form.querySelectorAll('input[name="specialist_ids[]"]:checked'))
				.map((specialistCheckbox) => specialistCheckbox.value);
			loadEligibleSpecialists();
		});
	});

	loadEligibleSpecialists();
});
