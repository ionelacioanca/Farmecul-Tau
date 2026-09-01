const menuToggle = document.querySelector('.menu-toggle');
const siteNavigation = document.querySelector('.site-nav');
const accountMenus = document.querySelectorAll('[data-account-menu]');

if (menuToggle && siteNavigation) {
	const closeMenu = () => {
		menuToggle.setAttribute('aria-expanded', 'false');
		siteNavigation.classList.remove('is-open');
		siteNavigation.querySelectorAll('.has-submenu.is-open').forEach((listItem) => {
			listItem.classList.remove('is-open');
			listItem.querySelectorAll('[aria-expanded="true"]').forEach((control) => {
				control.setAttribute('aria-expanded', 'false');
			});
		});
	};

	menuToggle.addEventListener('click', () => {
		const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
		menuToggle.setAttribute('aria-expanded', String(!isOpen));
		siteNavigation.classList.toggle('is-open', !isOpen);
	});

	siteNavigation.querySelectorAll('.nav-submenu-toggle').forEach((button) => {
		button.addEventListener('click', () => {
			const listItem = button.closest('.has-submenu');
			const isOpen = button.getAttribute('aria-expanded') === 'true';

			button.setAttribute('aria-expanded', String(!isOpen));
			listItem?.classList.toggle('is-open', !isOpen);
		});
	});

	siteNavigation.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));
	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') closeMenu();
	});
}

accountMenus.forEach((menu) => {
	const trigger = menu.querySelector('[data-account-menu-button]');
	const authenticatedPanel = menu.querySelector('[data-account-menu-authenticated]');
	const guestPanel = menu.querySelector('[data-account-menu-guest]');
	const logoutButton = menu.querySelector('[data-header-logout]');
	const authStatusUrl = menu.dataset.authStatusUrl || 'api/auth-status.php';
	const logoutUrl = menu.dataset.logoutUrl || 'api/logout.php';
	const homeUrl = menu.dataset.homeUrl || 'index.php';
	const initiallyAuthenticated = menu.dataset.authenticated === 'true';

	const setMenuOpen = (isOpen) => {
		menu.classList.toggle('is-open', isOpen);
		trigger?.setAttribute('aria-expanded', String(isOpen));
		menu.querySelector('.account-menu-toggle')?.setAttribute('aria-expanded', String(isOpen));
	};

	const setAuthenticatedState = (isAuthenticated) => {
		if (authenticatedPanel) {
			authenticatedPanel.hidden = !isAuthenticated;
		}

		if (guestPanel) {
			guestPanel.hidden = isAuthenticated;
		}
	};

	trigger?.addEventListener('click', (event) => {
		event.stopPropagation();
		setMenuOpen(!menu.classList.contains('is-open'));
	});

	logoutButton?.addEventListener('click', async () => {
		logoutButton.disabled = true;

		try {
			await fetch(logoutUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			});
		} finally {
			window.location.href = homeUrl;
		}
	});

	fetch(authStatusUrl, {
		credentials: 'same-origin',
		headers: { Accept: 'application/json' },
	})
		.then((response) => response.json())
		.then((data) => {
			if (data && data.success === true && typeof data.authenticated === 'boolean') {
				setAuthenticatedState(data.authenticated);
				return;
			}

			setAuthenticatedState(initiallyAuthenticated);
		})
		.catch(() => {
			setAuthenticatedState(initiallyAuthenticated);
		});

	document.addEventListener('click', (event) => {
		if (!menu.contains(event.target)) {
			setMenuOpen(false);
		}
	});
});
