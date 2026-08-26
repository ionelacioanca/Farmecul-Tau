const beautyGame = document.querySelector('[data-beauty-game]');

if (beautyGame) {
	const quoteText = beautyGame.querySelector('[data-quote-text]');
	const quoteOptions = beautyGame.querySelector('[data-quote-options]');
	const quoteError = beautyGame.querySelector('[data-quote-error]');
	const refreshButton = beautyGame.querySelector('[data-quote-refresh]');

	const showError = () => {
		quoteText.textContent = 'Nu am putut încărca un citat. Încearcă din nou.';
		quoteOptions.replaceChildren();
		quoteError.hidden = true;
	};

	const loadQuote = async () => {
		refreshButton.disabled = true;
		quoteText.textContent = 'Se încarcă un citat...';
		quoteOptions.replaceChildren();
		quoteError.hidden = true;

		try {
			const response = await fetch('api/get-quote.php', {
				headers: { Accept: 'application/json' },
			});
			if (!response.ok) throw new Error('Quote request failed');

			const data = await response.json();
			if (!data.success || !data.quote || !Array.isArray(data.quote.options)) {
				throw new Error('Invalid quote response');
			}

			quoteText.textContent = `“${data.quote.text}”`;
			data.quote.options.forEach((author) => {
				const option = document.createElement('button');
				option.className = 'beauty-game-option';
				option.type = 'button';
				option.textContent = author;
				option.addEventListener('click', () => {
					beautyGame.querySelectorAll('.beauty-game-option').forEach((button) => {
						button.classList.remove('is-selected');
					});
					option.classList.add('is-selected');
				});
				quoteOptions.append(option);
			});
		} catch (error) {
			showError();
		} finally {
			refreshButton.disabled = false;
		}
	};

	refreshButton.addEventListener('click', loadQuote);
	loadQuote();
}