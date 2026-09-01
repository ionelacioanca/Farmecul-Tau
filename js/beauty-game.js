const beautyGame = document.querySelector('[data-beauty-game]');
const authModal = document.querySelector('[data-auth-modal]');

if (beautyGame) {
	const quoteText = beautyGame.querySelector('[data-quote-text]');
	const quoteOptions = beautyGame.querySelector('[data-quote-options]');
	const quoteError = beautyGame.querySelector('[data-quote-error]');
	const quoteReward = beautyGame.querySelector('[data-quote-reward]');
	const refreshButton = beautyGame.querySelector('[data-quote-refresh]');
	const authDialog = authModal?.querySelector('.beauty-auth-dialog');
	const authTabs = authModal ? Array.from(authModal.querySelectorAll('[data-auth-tab]')) : [];
	const authForms = authModal ? Array.from(authModal.querySelectorAll('[data-auth-form]')) : [];
	const authCloseButtons = authModal ? Array.from(authModal.querySelectorAll('[data-auth-close]')) : [];
	let currentQuoteId = null;
	let lastFocusedElement = null;
	let isClaimingReward = false;

	const apiRequest = async (url, options = {}) => {
		const headers = {
			Accept: 'application/json',
			...(options.body ? { 'Content-Type': 'application/json' } : {}),
			...(options.headers || {}),
		};
		const response = await fetch(url, {
			credentials: 'same-origin',
			...options,
			headers,
		});
		const data = await response.json();

		return { response, data };
	};

	const setOptionButtonsDisabled = (disabled) => {
		beautyGame.querySelectorAll('.beauty-game-option').forEach((button) => {
			button.disabled = disabled;
		});
	};

	const hideMessage = () => {
		quoteError.hidden = true;
		quoteError.textContent = '';
	};

	const showMessage = (message) => {
		quoteError.textContent = message;
		quoteError.hidden = false;
	};

	const hideReward = () => {
		quoteReward.hidden = true;
		quoteReward.replaceChildren();
	};

	const formatExpirationDate = (expiresAt) => {
		const normalizedDate = new Date(String(expiresAt).replace(' ', 'T'));

		if (Number.isNaN(normalizedDate.getTime())) {
			return expiresAt;
		}

		return new Intl.DateTimeFormat('ro-RO', {
			day: 'numeric',
			month: 'long',
			year: 'numeric',
		}).format(normalizedDate);
	};

	const createParagraph = (className, textContent) => {
		const paragraph = document.createElement('p');
		paragraph.className = className;
		paragraph.textContent = textContent;

		return paragraph;
	};

	const showPromoCode = (promo, alreadyActive = false) => {
		const success = createParagraph(
			'beauty-game-reward-success',
			alreadyActive ? 'SURPRIZĂ ACTIVĂ' : 'SURPRIZA ESTE A TA'
		);

		const title = createParagraph('beauty-game-reward-title', promo.reward.name);

		const codeLabel = createParagraph('beauty-game-code-label', 'Codul tău promoțional');

		const code = document.createElement('p');
		code.className = 'beauty-game-code';
		code.textContent = promo.code;

		const validity = createParagraph(
			'beauty-game-reward-validity',
			`Valabil până la ${formatExpirationDate(promo.expires_at)}`
		);

		const note = createParagraph(
			'beauty-game-claim-note',
			alreadyActive
				? 'Mai ai deja o surpriză activă în cont.'
				: 'Păstrează acest cod. Îl vei putea găsi și în contul tău.'
		);

		quoteReward.replaceChildren(success, title, codeLabel, code, validity, note);
		quoteReward.hidden = false;
	};

	const claimReward = async () => {
		if (isClaimingReward) return;

		isClaimingReward = true;
		hideMessage();

		const claimButton = quoteReward.querySelector('.beauty-game-claim');
		if (claimButton) claimButton.disabled = true;

		try {
			const { response, data } = await apiRequest('api/claim-reward.php', {
				method: 'POST',
			});

			if (response.status === 401 && data.requires_auth) {
				openAuthModal('login');
				return;
			}

			if (!response.ok || !data.success) {
				throw new Error(data.message || data.error || 'Claim request failed');
			}

			if (data.promo) {
				showPromoCode(data.promo, data.claimed === false);
				return;
			}

			showMessage(data.message || 'Surpriza nu a putut fi revendicată.');
		} catch (error) {
			showMessage(error.message || 'Surpriza nu a putut fi revendicată.');
		} finally {
			isClaimingReward = false;
			if (claimButton) claimButton.disabled = false;
		}
	};

	const showReward = (reward) => {
		if (!reward) {
			showMessage('Ai ghicit, dar surpriza nu este disponibilă momentan.');
			return;
		}

		const success = createParagraph('beauty-game-reward-success', 'FELICITĂRI!');
		const title = createParagraph('beauty-game-reward-title', 'SURPRIZA TA');

		const name = document.createElement('h3');
		name.className = 'beauty-game-reward-name';
		name.textContent = reward.name;

		const description = createParagraph('beauty-game-reward-description', reward.description);
		const validity = createParagraph('beauty-game-reward-validity', `Valabilitate: ${reward.validity_days} zile`);

		const claimButton = document.createElement('button');
		claimButton.className = 'beauty-game-claim';
		claimButton.type = 'button';
		claimButton.textContent = 'REVENDICĂ SURPRIZA';
		claimButton.addEventListener('click', claimReward);

		quoteReward.replaceChildren(success, title, name, description, validity, claimButton);
		quoteReward.hidden = false;
	};

	const showError = () => {
		quoteText.textContent = 'Nu am putut încărca un citat. Încearcă din nou.';
		quoteOptions.replaceChildren();
		hideMessage();
		hideReward();
		currentQuoteId = null;
	};

	const checkAnswer = async (selectedAuthor, selectedButton) => {
		setOptionButtonsDisabled(true);
		hideMessage();
		hideReward();

		beautyGame.querySelectorAll('.beauty-game-option').forEach((button) => {
			button.classList.remove('is-selected');
		});
		selectedButton.classList.add('is-selected');

		try {
			const { response, data } = await apiRequest('api/check-answer.php', {
				method: 'POST',
				body: JSON.stringify({
					quote_id: currentQuoteId,
					selected_author: selectedAuthor,
				}),
			});

			if (!response.ok || !data.success) {
				throw new Error('Answer request failed');
			}

			if (!data.correct) {
				showMessage(data.message || 'Nu de data aceasta. Încearcă un alt citat.');
				return;
			}

			showReward(data.reward);
		} catch (error) {
			showMessage('Nu am putut verifica răspunsul. Încearcă din nou.');
			setOptionButtonsDisabled(false);
		}
	};

	const loadQuote = async () => {
		refreshButton.disabled = true;
		quoteText.textContent = 'Se încarcă un citat...';
		quoteOptions.replaceChildren();
		hideMessage();
		hideReward();
		currentQuoteId = null;

		try {
			const { response, data } = await apiRequest('api/get-quote.php');

			if (!response.ok || !data.success || !data.quote || !Array.isArray(data.quote.options)) {
				throw new Error('Invalid quote response');
			}

			currentQuoteId = data.quote.id;
			quoteText.textContent = `“${data.quote.text}”`;
			data.quote.options.forEach((author) => {
				const option = document.createElement('button');
				option.className = 'beauty-game-option';
				option.type = 'button';
				option.textContent = author;
				option.addEventListener('click', () => checkAnswer(author, option));
				quoteOptions.append(option);
			});
		} catch (error) {
			showError();
		} finally {
			refreshButton.disabled = false;
		}
	};

	const showAuthMessage = (view, message) => {
		const messageElement = authModal?.querySelector(`[data-auth-message="${view}"]`);
		if (!messageElement) return;

		messageElement.textContent = message;
		messageElement.hidden = false;
	};

	const hideAuthMessages = () => {
		authModal?.querySelectorAll('[data-auth-message]').forEach((messageElement) => {
			messageElement.textContent = '';
			messageElement.hidden = true;
		});
	};

	const switchAuthView = (view) => {
		authTabs.forEach((tab) => {
			const isActive = tab.dataset.authTab === view;
			tab.classList.toggle('is-active', isActive);
			tab.setAttribute('aria-selected', String(isActive));
		});

		authForms.forEach((form) => {
			form.hidden = form.dataset.authForm !== view;
		});

		hideAuthMessages();
		authModal?.querySelector(`[data-auth-form="${view}"] input`)?.focus();
	};

	function openAuthModal(view = 'login') {
		if (!authModal || !authDialog) {
			showMessage('Intră în cont sau creează unul pentru a revendica surpriza.');
			return;
		}

		lastFocusedElement = document.activeElement;
		authModal.hidden = false;
		switchAuthView(view);
	}

	const closeAuthModal = () => {
		if (!authModal) return;

		authModal.hidden = true;
		hideAuthMessages();

		if (lastFocusedElement instanceof HTMLElement) {
			lastFocusedElement.focus();
		}
	};

	const getAuthPayload = (form) => Object.fromEntries(new FormData(form).entries());

	const getAuthErrorMessage = (data) => {
		if (data.errors) {
			return Object.values(data.errors).filter(Boolean).join(' ');
		}

		return data.error || 'Autentificarea nu a putut fi finalizată.';
	};

	const handleAuthSubmit = async (event) => {
		event.preventDefault();

		const form = event.currentTarget;
		const view = form.dataset.authForm;
		const submitButton = form.querySelector('button[type="submit"]');
		const endpoint = view === 'register' ? 'api/register.php' : 'api/login.php';

		hideAuthMessages();
		if (submitButton) submitButton.disabled = true;

		try {
			const { response, data } = await apiRequest(endpoint, {
				method: 'POST',
				body: JSON.stringify(getAuthPayload(form)),
			});

			if (!response.ok || !data.success) {
				showAuthMessage(view, getAuthErrorMessage(data));
				return;
			}

			closeAuthModal();
			await claimReward();
		} catch (error) {
			showAuthMessage(view, 'Autentificarea nu a putut fi finalizată.');
		} finally {
			if (submitButton) submitButton.disabled = false;
		}
	};

	if (authModal) {
		authTabs.forEach((tab) => {
			tab.addEventListener('click', () => switchAuthView(tab.dataset.authTab));
		});

		authForms.forEach((form) => {
			form.addEventListener('submit', handleAuthSubmit);
		});

		authCloseButtons.forEach((button) => {
			button.addEventListener('click', closeAuthModal);
		});

		document.addEventListener('keydown', (event) => {
			if (authModal.hidden) return;

			if (event.key === 'Escape') {
				closeAuthModal();
				return;
			}

			if (event.key !== 'Tab' || !authDialog) return;

			const focusableElements = Array.from(
				authDialog.querySelectorAll('button:not(:disabled), input:not(:disabled)')
			).filter((element) => element.offsetParent !== null);

			if (focusableElements.length === 0) return;

			const firstElement = focusableElements[0];
			const lastElement = focusableElements[focusableElements.length - 1];

			if (event.shiftKey && document.activeElement === firstElement) {
				event.preventDefault();
				lastElement.focus();
			} else if (!event.shiftKey && document.activeElement === lastElement) {
				event.preventDefault();
				firstElement.focus();
			}
		});
	}

	refreshButton.addEventListener('click', loadQuote);
	loadQuote();
}
