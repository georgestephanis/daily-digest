import { __ } from '@wordpress/i18n';

const SETTINGS_FORM_ID = 'daily-digest-settings-form';

const getConfig = ( form ) => {
	if ( ! form ) {
		return null;
	}

	const restRoot = form.getAttribute( 'data-rest-root' ) || '';
	const restNonce = form.getAttribute( 'data-rest-nonce' ) || '';

	if ( ! restRoot || ! restNonce ) {
		return null;
	}

	return {
		restRoot,
		restNonce,
	};
};

export const initSettingsPage = () => {
	const form = document.getElementById( SETTINGS_FORM_ID );
	const config = getConfig( form );

	if ( ! form || ! config ) {
		return;
	}

	const buttons = form.querySelectorAll( '.daily-digest-test-credentials' );
	const providerToggles = form.querySelectorAll(
		'.daily-digest-provider-toggle'
	);
	const disconnectButtons = form.querySelectorAll(
		'.daily-digest-disconnect-provider'
	);
	const messageTimers = new WeakMap();

	const replaceMessage = ( el, text, ok ) => {
		if ( ! el ) {
			return;
		}

		const existingTimer = messageTimers.get( el );
		if ( existingTimer ) {
			window.clearTimeout( existingTimer );
			messageTimers.delete( el );
		}

		const applyNewMessage = () => {
			el.textContent = text;
			el.style.color = ok ? '#0a7d18' : '#b32d2e';
			el.style.transition = 'opacity 140ms ease';
			el.style.opacity = '0';
			window.requestAnimationFrame( () => {
				el.style.opacity = '1';
			} );
		};

		if ( ( el.textContent || '' ).trim() !== '' ) {
			el.style.transition = 'opacity 110ms ease';
			el.style.opacity = '0';
			const timer = window.setTimeout( () => {
				applyNewMessage();
				messageTimers.delete( el );
			}, 120 );
			messageTimers.set( el, timer );
			return;
		}

		applyNewMessage();
	};

	const setResult = ( provider, text, ok ) => {
		const el = document.getElementById(
			`daily-digest-test-result-${ provider }`
		);
		replaceMessage( el, text, ok );
	};

	const setSaveResult = ( provider, text, ok ) => {
		const el = document.getElementById(
			`daily-digest-save-result-${ provider }`
		);
		replaceMessage( el, text, ok );
	};

	const collectProviderFields = ( provider ) => {
		const fields = {};
		form.querySelectorAll(
			`input[name^="daily_digest_settings[${ provider }][fields]"]`
		).forEach( ( input ) => {
			const match = input.name.match( /\[fields\]\[([^\]]+)\]/ );
			if ( match && match[ 1 ] ) {
				fields[ match[ 1 ] ] = input.value;
			}
		} );
		return fields;
	};

	const collectProviderEnabled = ( provider ) => {
		const input = form.querySelector(
			`input[name="daily_digest_settings[${ provider }][enabled]"]`
		);
		return !! ( input && input.checked );
	};

	const requestJson = async ( url, body ) => {
		const response = await window.fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.restNonce,
			},
			body: JSON.stringify( body ),
		} );

		let payload = null;
		try {
			payload = await response.json();
		} catch ( error ) {}

		return { response, payload };
	};

	const saveProviderState = async ( provider ) => {
		if ( ! provider ) {
			return;
		}

		setSaveResult( provider, __( 'Saving…', 'daily-digest' ), true );

		const { response, payload } = await requestJson(
			`${ config.restRoot }/providers/${ window.encodeURIComponent(
				provider
			) }/credentials`,
			{
				fields: collectProviderFields( provider ),
				enabled: collectProviderEnabled( provider ),
			}
		);

		if ( ! response.ok || ! payload || ! payload.success ) {
			const message =
				payload && payload.message
					? payload.message
					: __(
							'Unable to save provider configuration.',
							'daily-digest'
					  );
			setSaveResult( provider, message, false );
			return;
		}

		setSaveResult(
			provider,
			payload.message ||
				__( 'Provider configuration saved.', 'daily-digest' ),
			true
		);
	};

	const disconnectProvider = async ( provider, button ) => {
		if ( ! provider ) {
			return;
		}

		// eslint-disable-next-line no-alert -- Explicit AYS confirmation is required before disconnecting provider tokens.
		const confirmed = window.confirm(
			__(
				'Are you sure you want to disconnect this provider?',
				'daily-digest'
			)
		);

		if ( ! confirmed ) {
			return;
		}

		if ( button ) {
			button.disabled = true;
		}

		setSaveResult( provider, __( 'Disconnecting…', 'daily-digest' ), true );

		const { response, payload } = await requestJson(
			`${ config.restRoot }/providers/${ window.encodeURIComponent(
				provider
			) }/disconnect`,
			{}
		);

		if ( ! response.ok || ! payload || ! payload.success ) {
			const message =
				payload && payload.message
					? payload.message
					: __( 'Unable to disconnect provider.', 'daily-digest' );
			setSaveResult( provider, message, false );

			if ( button ) {
				button.disabled = false;
			}

			return;
		}

		setSaveResult(
			provider,
			payload.message || __( 'Provider disconnected.', 'daily-digest' ),
			true
		);

		window.location.reload();
	};

	buttons.forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			if (
				button.disabled ||
				button.getAttribute( 'data-keyring-configured' ) !== '1'
			) {
				return;
			}

			const provider = button.getAttribute( 'data-provider' ) || '';
			if ( ! provider ) {
				return;
			}

			setResult( provider, __( 'Testing…', 'daily-digest' ), true );

			const { response, payload } = await requestJson(
				`${ config.restRoot }/providers/${ window.encodeURIComponent(
					provider
				) }/test-credentials`,
				{ fields: collectProviderFields( provider ) }
			);

			if ( ! response.ok || ! payload || ! payload.success ) {
				const message =
					payload && payload.message
						? payload.message
						: __( 'Credential test failed.', 'daily-digest' );
				setResult( provider, message, false );
				return;
			}

			setResult(
				provider,
				payload.message ||
					__( 'Credentials are valid.', 'daily-digest' ),
				true
			);
		} );
	} );

	providerToggles.forEach( ( toggle ) => {
		toggle.addEventListener( 'change', async () => {
			const row = toggle.closest( 'tr[data-provider]' );
			const provider = row
				? row.getAttribute( 'data-provider' ) || ''
				: '';
			await saveProviderState( provider );
		} );
	} );

	disconnectButtons.forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			const provider = button.getAttribute( 'data-provider' ) || '';
			await disconnectProvider( provider, button );
		} );
	} );
};
