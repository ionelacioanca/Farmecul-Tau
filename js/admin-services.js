document.querySelectorAll('[data-toggle-panel]').forEach((button) => {
	button.addEventListener('click', () => {
		const target = document.getElementById(button.dataset.togglePanel || '');

		if (target) {
			target.hidden = !target.hidden;
		}
	});
});

document.querySelectorAll('[data-close-panel]').forEach((button) => {
	button.addEventListener('click', () => {
		const target = document.getElementById(button.dataset.closePanel || '');

		if (target) {
			target.hidden = true;
		}
	});
});
