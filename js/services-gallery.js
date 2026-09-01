const serviceLightbox = document.querySelector('[data-service-lightbox]');
const lightboxImage = serviceLightbox?.querySelector('[data-lightbox-image]');
const lightboxClose = serviceLightbox?.querySelector('[data-lightbox-close]');
const lightboxPrev = serviceLightbox?.querySelector('[data-lightbox-prev]');
const lightboxNext = serviceLightbox?.querySelector('[data-lightbox-next]');
let activeGalleryState = null;

const formatServicePrice = (price) => new Intl.NumberFormat('ro-RO', {
	minimumFractionDigits: 0,
	maximumFractionDigits: 2,
}).format(Number(price));

const updateLightboxImage = () => {
	if (!activeGalleryState || !lightboxImage) {
		return;
	}

	const image = activeGalleryState.images[activeGalleryState.index];
	lightboxImage.src = image.path;
	lightboxImage.alt = image.alt || activeGalleryState.fallbackAlt;
};

const openLightbox = (state) => {
	if (!serviceLightbox) {
		return;
	}

	activeGalleryState = state;
	updateLightboxImage();
	serviceLightbox.hidden = false;
	serviceLightbox.classList.add('is-open');
	lightboxClose?.focus();
};

const closeLightbox = () => {
	if (!serviceLightbox) {
		return;
	}

	serviceLightbox.hidden = true;
	serviceLightbox.classList.remove('is-open');
	activeGalleryState = null;
};

const moveGallery = (state, direction) => {
	if (state.images.length === 0) {
		return;
	}

	state.index = (state.index + direction + state.images.length) % state.images.length;
	state.render();

	if (activeGalleryState === state) {
		updateLightboxImage();
	}
};

document.querySelectorAll('[data-service-card]').forEach((card) => {
	const dataNode = card.querySelector('[data-service-gallery-data]');
	const image = card.querySelector('[data-gallery-image]');
	const previousButton = card.querySelector('[data-gallery-prev]');
	const nextButton = card.querySelector('[data-gallery-next]');
	const dots = card.querySelector('[data-gallery-dots]');
	const specialistName = card.querySelector('[data-specialist-name]');
	const specialistPrice = card.querySelector('[data-specialist-price]');
	const specialistDuration = card.querySelector('[data-specialist-duration]');
	const bookingLink = card.querySelector('[data-booking-link]');
	const tabs = Array.from(card.querySelectorAll('[data-specialist-tab]'));

	if (!dataNode || !image) {
		return;
	}

	let serviceData;

	try {
		serviceData = JSON.parse(dataNode.textContent || '{}');
	} catch (error) {
		return;
	}

	const state = {
		index: 0,
		images: [],
		fallbackAlt: '',
		render() {
			const currentImage = this.images[this.index];

			if (!currentImage) {
				return;
			}

			image.src = currentImage.path;
			image.alt = currentImage.alt || this.fallbackAlt;
			dots?.querySelectorAll('button').forEach((dot, dotIndex) => {
				dot.classList.toggle('is-active', dotIndex === this.index);
				dot.setAttribute('aria-current', dotIndex === this.index ? 'true' : 'false');
			});
		},
	};

	const renderDots = () => {
		if (!dots) {
			return;
		}

		dots.replaceChildren();

		state.images.forEach((_galleryImage, index) => {
			const dot = document.createElement('button');
			dot.type = 'button';
			dot.setAttribute('aria-label', `Imaginea ${index + 1}`);
			dot.addEventListener('click', () => {
				state.index = index;
				state.render();
			});
			dots.append(dot);
		});
	};

	const selectSpecialist = (specialistId) => {
		const specialist = (serviceData.specialists || []).find((item) => String(item.id) === String(specialistId));

		if (!specialist) {
			return;
		}

		tabs.forEach((tab) => {
			const isActive = tab.dataset.specialistId === String(specialist.id);
			tab.classList.toggle('is-active', isActive);
			tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
		});

		state.index = 0;
		state.images = specialist.images || [];
		state.fallbackAlt = specialist.name;
		renderDots();
		state.render();

		if (specialistName) specialistName.textContent = specialist.name;
		if (specialistPrice) specialistPrice.textContent = specialist.price == null ? '-' : `${formatServicePrice(specialist.price)} lei`;
		if (specialistDuration) specialistDuration.textContent = specialist.duration_minutes == null ? '-' : `${specialist.duration_minutes} min`;
		if (bookingLink) {
			bookingLink.classList.toggle('is-disabled', !specialist.bookable);
			bookingLink.textContent = specialist.bookable ? 'PROGRAMEAZA-TE' : 'PROGRAMARE INDISPONIBILA';

			if (specialist.bookable) {
				bookingLink.href = `programari.php?service_id=${encodeURIComponent(serviceData.serviceId)}&specialist_id=${encodeURIComponent(specialist.id)}`;
				bookingLink.removeAttribute('aria-disabled');
				bookingLink.removeAttribute('tabindex');
			} else {
				bookingLink.removeAttribute('href');
				bookingLink.setAttribute('aria-disabled', 'true');
				bookingLink.setAttribute('tabindex', '-1');
			}
		}
	};

	tabs.forEach((tab) => {
		tab.addEventListener('click', () => selectSpecialist(tab.dataset.specialistId));
	});

	previousButton?.addEventListener('click', () => moveGallery(state, -1));
	nextButton?.addEventListener('click', () => moveGallery(state, 1));
	card.querySelector('[data-lightbox-open]')?.addEventListener('click', () => openLightbox(state));
	bookingLink?.addEventListener('click', (event) => {
		if (bookingLink.classList.contains('is-disabled')) {
			event.preventDefault();
		}
	});

	if (tabs[0]) {
		selectSpecialist(tabs[0].dataset.specialistId);
	}
});

lightboxClose?.addEventListener('click', closeLightbox);
lightboxPrev?.addEventListener('click', () => {
	if (activeGalleryState) moveGallery(activeGalleryState, -1);
});
lightboxNext?.addEventListener('click', () => {
	if (activeGalleryState) moveGallery(activeGalleryState, 1);
});
serviceLightbox?.addEventListener('click', (event) => {
	if (event.target === serviceLightbox) {
		closeLightbox();
	}
});

document.addEventListener('keydown', (event) => {
	if (!activeGalleryState) {
		return;
	}

	if (event.key === 'Escape') closeLightbox();
	if (event.key === 'ArrowLeft') moveGallery(activeGalleryState, -1);
	if (event.key === 'ArrowRight') moveGallery(activeGalleryState, 1);
});
