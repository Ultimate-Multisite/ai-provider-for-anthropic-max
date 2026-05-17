/**
 * AI Provider for Anthropic Max -- Connectors page integration.
 *
 * Registers connector cards on Settings > Connectors for each supported
 * subscription plan provider:
 *
 *   - Anthropic Max         (OAuth PKCE + paste-code)
 *   - OpenAI ChatGPT/Codex  (OAuth PKCE + paste-code from localhost callback)
 *   - Cursor Pro            (manual token paste — Cursor has no public OAuth)
 *   - Google AI Pro         (OAuth PKCE OOB + paste-code)
 *
 * Each card uses the same generic add/list/remove/refresh UI, configured
 * by a per-provider config object (mode, label, icon, REST namespace).
 *
 * @package AnthropicMaxAiProvider
 */

import {
	__experimentalRegisterConnector as registerConnector,
	__experimentalConnectorItem as ConnectorItem,
} from '@wordpress/connectors';

const { createElement, useState, useEffect, useCallback, Fragment } = wp.element;
const {
	Button,
	TextControl,
	TextareaControl,
	Spinner,
	Notice,
	__experimentalHStack: HStack,
	__experimentalVStack: VStack,
	__experimentalText: Text,
} = wp.components;
const { __ } = wp.i18n;
const apiFetch = wp.apiFetch;

/**
 * Lazily resolves the @wordpress/notices store dispatch.
 *
 * Returns no-op functions if the notices store is not yet loaded when a
 * dispatch fires (e.g. during early module boot), so callers never crash.
 *
 * @return {{createSuccessNotice:Function, createErrorNotice:Function}}
 */
function resolveNoticeDispatchers() {
	const store = wp.notices && wp.notices.store;
	const dispatch = wp.data && wp.data.dispatch;
	if ( ! store || ! dispatch ) {
		return {
			createSuccessNotice: () => {},
			createErrorNotice: () => {},
		};
	}
	const actions = dispatch( store );
	return {
		createSuccessNotice: actions.createSuccessNotice.bind( actions ),
		createErrorNotice: actions.createErrorNotice.bind( actions ),
	};
}

const SNACKBAR_OPTS = { type: 'snackbar' };

const REST_NS = '/anthropic-max-pool/v1';

/**
 * Extracts a human-readable message from an apiFetch rejection.
 *
 * WordPress `wp.apiFetch` rejects with a plain object shaped like
 * `{ code, message, data }` (the WP_Error JSON envelope), not an Error
 * instance. Using `err instanceof Error` therefore drops the real
 * server-side message; this helper unwraps it correctly.
 *
 * @param {unknown} err      The rejection value from apiFetch.
 * @param {string}  fallback Localised fallback when no message is present.
 * @return {string} The best available error message.
 */
function apiFetchErrorMessage( err, fallback ) {
	let raw = fallback;
	if ( err && typeof err.message === 'string' && err.message ) {
		raw = err.message;
	} else if ( err instanceof Error && err.message ) {
		raw = err.message;
	}
	return sanitizeErrorMessage( raw, fallback );
}

/**
 * Defensive guard so the UI never renders a raw HTML/Cloudflare body even if
 * a backend forgets to wrap it. If the message begins with markup, replaces
 * it with a generic operator-friendly notice; otherwise truncates very long
 * strings to keep the admin layout sane.
 *
 * @param {string} msg      Raw message to clean.
 * @param {string} fallback Localised fallback used when msg is empty.
 * @return {string}
 */
function sanitizeErrorMessage( msg, fallback ) {
	if ( typeof msg !== 'string' || msg === '' ) {
		return fallback;
	}
	const head = msg.trimStart().slice( 0, 256 ).toLowerCase();
	if (
		head.startsWith( '<!doctype' ) ||
		head.startsWith( '<html' ) ||
		head.startsWith( '<head' ) ||
		head.includes( '<title>just a moment' )
	) {
		return __(
			'The upstream provider returned an HTML challenge page (likely Cloudflare bot protection). Please wait a minute and try again.'
		);
	}
	return msg.length > 300 ? `${ msg.slice( 0, 300 ) }…` : msg;
}

// ---------------------------------------------------------------------------
// Per-provider client config.
// Mirrors src/OAuthPool/ProviderConfig.php.
// ---------------------------------------------------------------------------
const PROVIDERS = {
	anthropic: {
		id: 'anthropic',
		slug: 'ultimate-ai-connector-anthropic-max',
		label: __( 'Anthropic Max' ),
		description: __(
			'Use Claude with your Max subscription via OAuth. Supports account pool rotation for reliability.'
		),
		mode: 'oauth-paste',
		emailRequired: true,
		instructions: __(
			'A new window opened for Claude authorization. Log in, then copy the authorization code shown and paste it below.'
		),
		iconText: 'MAX',
	},
	openai: {
		id: 'openai',
		slug: 'ultimate-ai-connector-chatgpt-codex',
		label: __( 'OpenAI ChatGPT (Codex)' ),
		description: __(
			'ChatGPT Plus/Pro/Team via OAuth — unlocks GPT-5.4 mini, GPT-5.4, GPT-5.5, GPT-5.2 through the Codex backend. No localhost server required.'
		),
		mode: 'device-code',
		iconText: 'GPT',
	},
	cursor: {
		id: 'cursor',
		slug: 'ultimate-ai-connector-cursor-pro',
		label: __( 'Cursor Pro' ),
		description: __(
			'Use Cursor Pro tokens. Cursor has no public OAuth — paste your access/refresh tokens from the IDE.'
		),
		mode: 'manual-token',
		emailRequired: false,
		instructions: __(
			'Find your tokens in Cursor IDE: ~/.cursor/auth.json (Linux/macOS) or %APPDATA%/Cursor/auth.json (Windows). Email is auto-derived from the token.'
		),
		iconText: 'CUR',
	},
	google: {
		id: 'google',
		slug: 'ultimate-ai-connector-google-ai-pro',
		label: __( 'Google AI Pro' ),
		description: __(
			'Use Google AI Pro/Ultra or Workspace Gemini via OAuth. Paste the OOB code shown by Google.'
		),
		mode: 'oauth-paste',
		emailRequired: true,
		instructions: __(
			'After signing in, Google shows a code in the browser. Copy and paste it below.'
		),
		iconText: 'GAI',
	},
};

// ---------------------------------------------------------------------------
// Shared UI primitives.
// ---------------------------------------------------------------------------

/**
 * Renders a transient error banner without using @wordpress/components Notice.
 *
 * Inline Notice instances that mount/unmount during state transitions can
 * trip a deps-array race inside Notice.useSpokenMessage's useEffect on
 * re-render, surfacing as "Cannot read properties of undefined (reading
 * 'length')" inside React reconciler. Using a plain styled <div> with
 * role="alert" avoids that hook entirely and matches the WP-core pattern
 * of preferring the @wordpress/notices store for transient feedback.
 *
 * @param {{message: string}} props
 * @return {JSX.Element|null}
 */
function InlineErrorBanner( { message } ) {
	if ( ! message ) {
		return null;
	}
	return (
		<div
			role="alert"
			style={ {
				background: '#fcf0f1',
				borderLeft: '4px solid #cc1818',
				color: '#621e1e',
				padding: '10px 12px',
				fontSize: '13px',
				lineHeight: '1.4',
			} }
		>
			{ message }
		</div>
	);
}

function ProviderLogo( { text } ) {
	const safeText = typeof text === 'string' ? text : '';
	return (
		<svg
			width={ 40 }
			height={ 40 }
			viewBox="0 0 40 40"
			fill="none"
			xmlns="http://www.w3.org/2000/svg"
		>
			<text
				x="20"
				y="24"
				textAnchor="middle"
				dominantBaseline="central"
				fontFamily="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
				fontSize={ safeText.length > 3 ? 11 : 14 }
				fontWeight="800"
				letterSpacing="0.5"
				fill="currentColor"
			>
				{ safeText }
			</text>
		</svg>
	);
}

function ConnectedBadge( { count } ) {
	return (
		<span
			style={ {
				color: '#345b37',
				backgroundColor: '#eff8f0',
				padding: '4px 12px',
				borderRadius: '2px',
				fontSize: '13px',
				fontWeight: 500,
				whiteSpace: 'nowrap',
			} }
		>
			{ count === 1
				? __( '1 account' )
				: count + ' ' + __( 'accounts' ) }
		</span>
	);
}

function StatusBadge( { status, validity } ) {
	const colors = {
		active: { color: '#345b37', bg: '#eff8f0' },
		idle: { color: '#5a5a5a', bg: '#f0f0f0' },
		'rate-limited': { color: '#8a4600', bg: '#fff3e0' },
		'refresh-failed': { color: '#cc1818', bg: '#fce8e8' },
	};
	const c = colors[ status ] || colors.idle;
	let label = status;
	if ( validity === 'invalid' ) {
		label = 'invalid token';
	}
	return (
		<span
			style={ {
				color: c.color,
				backgroundColor: c.bg,
				padding: '2px 8px',
				borderRadius: '2px',
				fontSize: '12px',
				fontWeight: 500,
			} }
		>
			{ label }
		</span>
	);
}

function AccountRow( { account, onRemove, onRefresh, isBusy, providerSupportsRefresh } ) {
	return (
		<HStack
			spacing={ 3 }
			style={ {
				padding: '8px 0',
				borderBottom: '1px solid #e0e0e0',
				alignItems: 'center',
			} }
		>
			<span style={ { flex: 1, fontWeight: 500 } }>
				{ account.email }
			</span>
			<StatusBadge status={ account.status } validity={ account.validity } />
			<span style={ { fontSize: '12px', color: '#757575' } }>
				{ account.tokenExpired
					? __( 'expired' )
					: account.expiresIn > 0
					? Math.round( account.expiresIn / 60 ) +
					  'm ' +
					  __( 'remaining' )
					: '' }
			</span>
			{ providerSupportsRefresh && (
				<Button
					variant="tertiary"
					size="small"
					onClick={ () => onRefresh( account.email ) }
					disabled={ isBusy || ! account.hasRefresh }
				>
					{ __( 'Refresh' ) }
				</Button>
			) }
			<Button
				variant="tertiary"
				size="small"
				isDestructive
				onClick={ () => onRemove( account.email ) }
				disabled={ isBusy }
			>
				{ __( 'Remove' ) }
			</Button>
		</HStack>
	);
}

// ---------------------------------------------------------------------------
// Sandboxed environment detection (e.g. WordPress Playground).
// ---------------------------------------------------------------------------

function isSandboxedEnvironment() {
	if ( window.wp?.playground ) {
		return true;
	}
	try {
		const loc = window.location.href;
		if (
			loc.includes( 'playground.wordpress.net' ) ||
			loc.includes( 'playground.wordpress.org' )
		) {
			return true;
		}
	} catch ( e ) {
		return true;
	}
	try {
		if ( window.parent !== window && window.parent.location.href ) {
			// Same-origin — not necessarily sandboxed.
		}
	} catch ( e ) {
		return true;
	}
	return false;
}

// ---------------------------------------------------------------------------
// OAuth paste-code add form (anthropic, openai, google).
// ---------------------------------------------------------------------------

function OAuthAddForm( { providerCfg, onComplete, onCancel } ) {
	const { id, label, instructions, emailRequired } = providerCfg;
	const [ step, setStep ] = useState( emailRequired ? 'email' : 'authorize' );
	const [ email, setEmail ] = useState( '' );
	const [ oauthState, setOauthState ] = useState( '' );
	const [ authCode, setAuthCode ] = useState( '' );
	const [ authorizeUrl, setAuthorizeUrl ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ inlineError, setInlineError ] = useState( '' );
	const [ showManualLink, setShowManualLink ] = useState( false );

	const handleStartOAuth = async () => {
		if ( emailRequired && ( ! email || ! email.includes( '@' ) ) ) {
			setInlineError( __( 'Enter a valid email address.' ) );
			return;
		}
		setInlineError( '' );
		setIsBusy( true );
		try {
			const data = await apiFetch( {
				path: `${ REST_NS }/${ id }/authorize`,
			} );
			setOauthState( data.state );
			setAuthorizeUrl( data.authorize_url );
			if ( isSandboxedEnvironment() ) {
				setShowManualLink( true );
			} else {
				window.open( data.authorize_url, '_blank', 'noopener' );
				setShowManualLink( false );
			}
			setStep( 'code' );
		} catch ( err ) {
			setInlineError( apiFetchErrorMessage( err, __( 'Failed to start OAuth flow.' ) ) );
		} finally {
			setIsBusy( false );
		}
	};

	const handleExchangeCode = async () => {
		if ( ! authCode.trim() ) {
			setInlineError( __( 'Paste the authorization code.' ) );
			return;
		}
		setInlineError( '' );
		setIsBusy( true );
		try {
			await apiFetch( {
				method: 'POST',
				path: `${ REST_NS }/${ id }/exchange`,
				data: {
					code: authCode.trim(),
					state: oauthState,
					email: email || 'unknown',
				},
			} );
			const { createSuccessNotice } = resolveNoticeDispatchers();
			createSuccessNotice(
				sprintf__( '%s account connected.', label ),
				{ ...SNACKBAR_OPTS, id: `${ id }-add-success` }
			);
			onComplete();
		} catch ( err ) {
			setInlineError( apiFetchErrorMessage( err, __( 'Code exchange failed.' ) ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<VStack spacing={ 3 } style={ { marginTop: '12px' } }>
			{ inlineError && (
				<InlineErrorBanner message={ inlineError } />
			) }
			{ step === 'email' && emailRequired && (
				<Fragment>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ sprintf__( '%s Account Email', label ) }
						type="email"
						value={ email }
						onChange={ setEmail }
						placeholder="you@example.com"
						disabled={ isBusy }
						help={ __(
							'The email address of your subscription account.'
						) }
					/>
					<HStack spacing={ 2 }>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ handleStartOAuth }
							disabled={ isBusy || ! email }
							isBusy={ isBusy }
						>
							{ __( 'Authorize' ) }
						</Button>
						<Button variant="tertiary" onClick={ onCancel }>
							{ __( 'Cancel' ) }
						</Button>
					</HStack>
				</Fragment>
			) }
			{ step === 'code' && (
				<Fragment>
					<Notice
						status={ showManualLink ? 'warning' : 'info' }
						isDismissible={ false }
						politeness="polite"
						spokenMessage={
							showManualLink
								? __(
									'Popups appear to be blocked. Open the authorization link below in a new tab, sign in, then copy the URL you land on and paste it below.'
								)
								: instructions
						}
					>
						<VStack spacing={ 2 }>
							<span>
								{ showManualLink
									? __(
										'Popups appear to be blocked. Open the authorization link below in a new tab, sign in, then copy the URL you land on and paste it below.'
									)
									: instructions }
							</span>
							{ authorizeUrl && (
								<HStack spacing={ 2 } wrap>
									<a
										href={ authorizeUrl }
										target="_blank"
										rel="noopener noreferrer"
										style={ { fontWeight: 500 } }
									>
										{ __( 'Open Authorization Page' ) }
									</a>
									<Button
										variant="link"
										style={ { fontSize: '12px' } }
										onClick={ () => {
											navigator.clipboard
												.writeText( authorizeUrl )
												.then( () => setInlineError( '' ) );
										} }
									>
										{ __( 'Copy link' ) }
									</Button>
								</HStack>
							) }
						</VStack>
					</Notice>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Authorization Code or Redirect URL' ) }
						value={ authCode }
						onChange={ setAuthCode }
						placeholder={ __(
							'Paste the full URL from your browser bar (or just the code)'
						) }
						disabled={ isBusy }
					/>
					<HStack spacing={ 2 }>
						<Button
							__next40pxDefaultSize
							variant="primary"
							onClick={ handleExchangeCode }
							disabled={ isBusy || ! authCode }
							isBusy={ isBusy }
						>
							{ __( 'Add Account' ) }
						</Button>
						<Button variant="tertiary" onClick={ onCancel }>
							{ __( 'Cancel' ) }
						</Button>
					</HStack>
				</Fragment>
			) }
		</VStack>
	);
}

// ---------------------------------------------------------------------------
// Manual-token add form (cursor, or any provider as fallback).
// ---------------------------------------------------------------------------

function ManualTokenAddForm( { providerCfg, onComplete, onCancel } ) {
	const { id, label, instructions } = providerCfg;
	const [ accessToken, setAccessToken ] = useState( '' );
	const [ refreshToken, setRefreshToken ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ inlineError, setInlineError ] = useState( '' );

	const handleSubmit = async () => {
		if ( ! accessToken.trim() ) {
			setInlineError( __( 'Paste the access token.' ) );
			return;
		}
		setInlineError( '' );
		setIsBusy( true );
		try {
			await apiFetch( {
				method: 'POST',
				path: `${ REST_NS }/${ id }/manual`,
				data: {
					access_token: accessToken.trim(),
					refresh_token: refreshToken.trim(),
					email: email.trim(),
				},
			} );
			const { createSuccessNotice } = resolveNoticeDispatchers();
			createSuccessNotice(
				sprintf__( '%s account connected.', label ),
				{ ...SNACKBAR_OPTS, id: `${ id }-add-success` }
			);
			onComplete();
		} catch ( err ) {
			setInlineError( apiFetchErrorMessage( err, __( 'Failed to add account.' ) ) );
		} finally {
			setIsBusy( false );
		}
	};

	const instructionsText =
		typeof instructions === 'string' ? instructions : '';

	return (
		<VStack spacing={ 3 } style={ { marginTop: '12px' } }>
			{ inlineError && (
				<InlineErrorBanner message={ inlineError } />
			) }
			<Notice
				status="info"
				isDismissible={ false }
				politeness="polite"
				spokenMessage={ instructionsText }
			>
				{ instructionsText }
			</Notice>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Access Token' ) }
				value={ accessToken }
				onChange={ setAccessToken }
				rows={ 3 }
				disabled={ isBusy }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Refresh Token (optional)' ) }
				value={ refreshToken }
				onChange={ setRefreshToken }
				rows={ 3 }
				disabled={ isBusy }
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Email (optional — auto-derived from the token if blank)' ) }
				type="email"
				value={ email }
				onChange={ setEmail }
				placeholder="you@example.com"
				disabled={ isBusy }
			/>
			<HStack spacing={ 2 }>
				<Button
					__next40pxDefaultSize
					variant="primary"
					onClick={ handleSubmit }
					disabled={ isBusy || ! accessToken }
					isBusy={ isBusy }
				>
					{ sprintf__( 'Add %s Account', label ) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel' ) }
				</Button>
			</HStack>
		</VStack>
	);
}

// ---------------------------------------------------------------------------
// Device-code add form (OpenAI — no localhost binding needed).
// ---------------------------------------------------------------------------

/**
 * Formats milliseconds as M:SS for the countdown display.
 *
 * @param {number} ms Remaining milliseconds.
 * @return {string}
 */
function formatCountdown( ms ) {
	const totalSecs = Math.max( 0, Math.floor( ms / 1000 ) );
	const m = Math.floor( totalSecs / 60 );
	const s = String( totalSecs % 60 ).padStart( 2, '0' );
	return `${ m }:${ s }`;
}

function DeviceCodeForm( { providerCfg, onComplete, onCancel } ) {
	const { id } = providerCfg;

	// phase: 'loading' | 'waiting' | 'success' | 'expired' | 'error'
	const [ phase, setPhase ]         = useState( 'loading' );
	const [ userCode, setUserCode ]   = useState( '' );
	const [ sessionKey, setSessionKey ] = useState( '' );
	const [ intervalMs, setIntervalMs ] = useState( 5000 );
	const [ expiresAt, setExpiresAt ]   = useState( 0 );
	const [ timeLeft, setTimeLeft ]     = useState( 0 );
	const [ errorMsg, setErrorMsg ]     = useState( '' );
	const [ completedEmail, setCompletedEmail ] = useState( '' );
	const [ copied, setCopied ]         = useState( false );

	const VERIFICATION_URL = 'https://auth.openai.com/codex/device';

	// Start device-code session on mount.
	useEffect( () => {
		let cancelled = false;
		apiFetch( {
			method: 'POST',
			path: `${ REST_NS }/${ id }/device-start`,
		} )
			.then( ( data ) => {
				if ( cancelled ) return;
				setUserCode( data.user_code );
				setSessionKey( data.session_key );
				setIntervalMs( data.interval_ms || 5000 );
				const exp = Date.now() + ( data.expires_in_ms || 900000 );
				setExpiresAt( exp );
				setTimeLeft( data.expires_in_ms || 900000 );
				setPhase( 'waiting' );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setErrorMsg( apiFetchErrorMessage( err, __( 'Failed to start device-code flow.' ) ) );
				setPhase( 'error' );
			} );
		return () => { cancelled = true; };
	}, [ id ] );

	// Countdown ticker (1-second interval while waiting).
	useEffect( () => {
		if ( phase !== 'waiting' || expiresAt === 0 ) return;
		const tick = setInterval( () => {
			const remaining = expiresAt - Date.now();
			if ( remaining <= 0 ) {
				setTimeLeft( 0 );
				setPhase( 'expired' );
				clearInterval( tick );
			} else {
				setTimeLeft( remaining );
			}
		}, 1000 );
		return () => clearInterval( tick );
	}, [ phase, expiresAt ] );

	// Polling loop — fires every intervalMs while waiting.
	useEffect( () => {
		if ( phase !== 'waiting' || ! sessionKey ) return;
		const poll = setInterval( () => {
			apiFetch( {
				method: 'POST',
				path: `${ REST_NS }/${ id }/device-poll`,
				data: { session_key: sessionKey },
			} )
				.then( ( res ) => {
					if ( res.status === 'complete' ) {
						setCompletedEmail( res.email || '' );
						setPhase( 'success' );
					} else if ( res.status === 'expired' ) {
						setPhase( 'expired' );
					}
					// 'pending' → do nothing, keep polling.
				} )
				.catch( ( err ) => {
					setErrorMsg( apiFetchErrorMessage( err, __( 'Polling failed.' ) ) );
					setPhase( 'error' );
				} );
		}, intervalMs );
		return () => clearInterval( poll );
	}, [ phase, sessionKey, intervalMs, id ] );

	// On success, dispatch a snackbar via the global notices store and call
	// onComplete after a short delay so the account list refreshes. The
	// snackbar renders outside this component's tree (via wp.notices's
	// SnackbarNotices), avoiding any inline Notice that would otherwise
	// mount during the unmount cascade and trip Notice.useSpokenMessage.
	useEffect( () => {
		if ( phase !== 'success' ) return;
		const { createSuccessNotice } = resolveNoticeDispatchers();
		const msg = completedEmail
			? sprintf__( 'Connected: %s', completedEmail )
			: __( 'Account connected successfully.' );
		createSuccessNotice( msg, {
			...SNACKBAR_OPTS,
			id: `${ id }-device-code-success`,
		} );
		const t = setTimeout( onComplete, 1500 );
		return () => clearTimeout( t );
	}, [ phase, onComplete, completedEmail, id ] );

	const handleCopyCode = () => {
		navigator.clipboard.writeText( userCode ).then( () => {
			setCopied( true );
			setTimeout( () => setCopied( false ), 2000 );
		} );
	};

	if ( phase === 'loading' ) {
		return (
			<VStack spacing={ 3 } style={ { marginTop: '12px', alignItems: 'center' } }>
				<Spinner />
				<Text>{ __( 'Requesting device code…' ) }</Text>
			</VStack>
		);
	}

	if ( phase === 'error' ) {
		return (
			<VStack spacing={ 3 } style={ { marginTop: '12px' } }>
				<InlineErrorBanner
					message={
						typeof errorMsg === 'string' && errorMsg
							? errorMsg
							: __( 'Device-code flow failed.' )
					}
				/>
				<HStack spacing={ 2 }>
					<Button variant="primary" onClick={ () => window.location.reload() }>
						{ __( 'Try again' ) }
					</Button>
					<Button variant="tertiary" onClick={ onCancel }>
						{ __( 'Cancel' ) }
					</Button>
				</HStack>
			</VStack>
		);
	}

	if ( phase === 'expired' ) {
		const expiredMsg = __(
			'Device code expired. Click "Start again" to get a new one.'
		);
		return (
			<VStack spacing={ 3 } style={ { marginTop: '12px' } }>
				<Notice
					status="warning"
					isDismissible={ false }
					politeness="polite"
					spokenMessage={ expiredMsg }
				>
					{ expiredMsg }
				</Notice>
				<HStack spacing={ 2 }>
					<Button variant="primary" onClick={ () => window.location.reload() }>
						{ __( 'Start again' ) }
					</Button>
					<Button variant="tertiary" onClick={ onCancel }>
						{ __( 'Cancel' ) }
					</Button>
				</HStack>
			</VStack>
		);
	}

	if ( phase === 'success' ) {
		// Success feedback is dispatched via the global notices store
		// (see useEffect above) — render nothing inline to avoid mounting
		// a Notice during the unmount cascade triggered by onComplete().
		return null;
	}

	// phase === 'waiting'
	const waitingMsg = __(
		'Open the link below in your browser, enter the code shown, then sign in with your ChatGPT Plus/Pro/Team account. This page will update automatically once authorised.'
	);
	return (
		<VStack spacing={ 4 } style={ { marginTop: '12px' } }>
			<Notice
				status="info"
				isDismissible={ false }
				politeness="polite"
				spokenMessage={ waitingMsg }
			>
				<VStack spacing={ 2 }>
					<span>{ waitingMsg }</span>
					<a
						href={ VERIFICATION_URL }
						target="_blank"
						rel="noopener noreferrer"
						style={ { fontWeight: 600 } }
					>
						{ VERIFICATION_URL }
					</a>
				</VStack>
			</Notice>

			{ /* User code — large, monospace, easy to read */ }
			<VStack spacing={ 1 } style={ { alignItems: 'center' } }>
				<Text style={ { fontSize: '11px', color: '#757575', textTransform: 'uppercase', letterSpacing: '0.5px' } }>
					{ __( 'Your device code' ) }
				</Text>
				<HStack spacing={ 2 } style={ { alignItems: 'center' } }>
					<span
						style={ {
							fontFamily: 'monospace',
							fontSize: '28px',
							fontWeight: 700,
							letterSpacing: '6px',
							padding: '8px 16px',
							background: '#f6f7f7',
							borderRadius: '4px',
							border: '1px solid #ddd',
							userSelect: 'all',
						} }
					>
						{ userCode }
					</span>
					<Button
						variant="secondary"
						size="small"
						onClick={ handleCopyCode }
					>
						{ copied ? __( 'Copied!' ) : __( 'Copy' ) }
					</Button>
				</HStack>
				<Text style={ { fontSize: '12px', color: '#757575' } }>
					{ __( 'Expires in' ) + ' ' + formatCountdown( timeLeft ) }
				</Text>
			</VStack>

			<HStack spacing={ 3 } style={ { alignItems: 'center' } }>
				<Spinner />
				<Text style={ { color: '#757575' } }>
					{ __( 'Waiting for authorisation…' ) }
				</Text>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel' ) }
				</Button>
			</HStack>
		</VStack>
	);
}

// Tiny sprintf shim — avoids pulling in @wordpress/i18n.sprintf.
function sprintf__( fmt, value ) {
	return fmt.replace( '%s', value );
}

// ---------------------------------------------------------------------------
// Generic provider connector card.
// ---------------------------------------------------------------------------

function ProviderConnectorCard( { providerCfg } ) {
	const { id, label, description, mode, iconText } = providerCfg;
	const [ accounts, setAccounts ] = useState( [] );
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ isAdding, setIsAdding ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isBusy, setIsBusy ] = useState( false );

	const isConnected = accounts.length > 0;
	const supportsRefresh = mode === 'oauth-paste' || mode === 'device-code'; // Manual-token providers can't refresh.

	const fetchAccounts = useCallback( async () => {
		try {
			const data = await apiFetch( {
				path: `${ REST_NS }/${ id }/accounts`,
			} );
			setAccounts( Array.isArray( data ) ? data : [] );
		} catch {
			setAccounts( [] );
		} finally {
			setIsLoading( false );
		}
	}, [ id ] );

	useEffect( () => {
		fetchAccounts();
	}, [ fetchAccounts ] );

	const handleRemove = async ( email ) => {
		setIsBusy( true );
		const { createSuccessNotice, createErrorNotice } = resolveNoticeDispatchers();
		try {
			await apiFetch( {
				method: 'POST',
				path: `${ REST_NS }/${ id }/accounts/remove`,
				data: { email },
			} );
			createSuccessNotice(
				sprintf__( '%s account removed.', label ),
				{ ...SNACKBAR_OPTS, id: `${ id }-remove-success` }
			);
			await fetchAccounts();
		} catch ( err ) {
			createErrorNotice(
				apiFetchErrorMessage( err, __( 'Failed to remove account.' ) ),
				{ ...SNACKBAR_OPTS, id: `${ id }-remove-error` }
			);
		} finally {
			setIsBusy( false );
		}
	};

	const handleRefresh = async ( email ) => {
		setIsBusy( true );
		const { createSuccessNotice, createErrorNotice } = resolveNoticeDispatchers();
		try {
			await apiFetch( {
				method: 'POST',
				path: `${ REST_NS }/${ id }/accounts/refresh`,
				data: { email },
			} );
			createSuccessNotice( __( 'Token refreshed.' ), {
				...SNACKBAR_OPTS,
				id: `${ id }-refresh-success`,
			} );
			await fetchAccounts();
		} catch ( err ) {
			createErrorNotice(
				apiFetchErrorMessage( err, __( 'Refresh failed.' ) ),
				{ ...SNACKBAR_OPTS, id: `${ id }-refresh-error` }
			);
		} finally {
			setIsBusy( false );
		}
	};

	const handleHealthCheck = async () => {
		setIsBusy( true );
		const { createSuccessNotice, createErrorNotice } = resolveNoticeDispatchers();
		try {
			const raw = await apiFetch( {
				path: `${ REST_NS }/${ id }/health`,
			} );
			const results = Array.isArray( raw ) ? raw : [];
			const total = results.length;
			const ok = results.filter( ( r ) => r && r.validity === 'ok' ).length;
			const allHealthy = total > 0 && ok === total;
			const msg = ok + '/' + total + ' ' + __( 'accounts healthy' );
			if ( allHealthy ) {
				createSuccessNotice( msg, {
					...SNACKBAR_OPTS,
					id: `${ id }-health-success`,
				} );
			} else {
				createErrorNotice( msg, {
					...SNACKBAR_OPTS,
					id: `${ id }-health-warning`,
				} );
			}
			await fetchAccounts();
		} catch {
			createErrorNotice( __( 'Health check failed.' ), {
				...SNACKBAR_OPTS,
				id: `${ id }-health-error`,
			} );
		} finally {
			setIsBusy( false );
		}
	};

	const handleButtonClick = () => {
		setIsExpanded( ! isExpanded );
		setIsAdding( false );
	};

	const getButtonLabel = () => {
		if ( isLoading ) {
			return __( 'Loading\u2026' );
		}
		if ( isExpanded ) {
			return __( 'Close' );
		}
		return isConnected ? __( 'Manage' ) : __( 'Set up' );
	};

	const actionArea = (
		<HStack spacing={ 3 } expanded={ false }>
			{ isConnected && ! isExpanded && (
				<ConnectedBadge count={ accounts.length } />
			) }
			<Button
				variant={ isExpanded || isConnected ? 'tertiary' : 'secondary' }
				size={ isExpanded || isConnected ? undefined : 'compact' }
				onClick={ handleButtonClick }
				disabled={ isLoading }
				aria-expanded={ isExpanded }
			>
				{ getButtonLabel() }
			</Button>
		</HStack>
	);

	const AddForm =
		mode === 'manual-token' ? ManualTokenAddForm :
		mode === 'device-code'  ? DeviceCodeForm :
		OAuthAddForm;

	const settingsPanel = isExpanded ? (
		<VStack spacing={ 4 } className="connector-settings">
			{ accounts.length > 0 && (
				<VStack spacing={ 0 }>
					{ accounts.map( ( account ) => (
						<AccountRow
							key={ account.email }
							account={ account }
							onRemove={ handleRemove }
							onRefresh={ handleRefresh }
							isBusy={ isBusy }
							providerSupportsRefresh={ supportsRefresh }
						/>
					) ) }
				</VStack>
			) }
			{ accounts.length === 0 && ! isAdding && (
				<Text style={ { color: '#757575' } }>
					{ sprintf__( 'No %s accounts configured.', label ) }
				</Text>
			) }
			{ isAdding ? (
				<AddForm
					providerCfg={ providerCfg }
					onComplete={ () => {
						setIsAdding( false );
						fetchAccounts();
					} }
					onCancel={ () => setIsAdding( false ) }
				/>
			) : (
				<HStack spacing={ 2 } justify="flex-start">
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ () => setIsAdding( true ) }
						disabled={ isBusy }
					>
						{ __( 'Add Account' ) }
					</Button>
					{ accounts.length > 0 && (
						<Button
							variant="tertiary"
							onClick={ handleHealthCheck }
							disabled={ isBusy }
						>
							{ isBusy ? <Spinner /> : __( 'Health Check' ) }
						</Button>
					) }
				</HStack>
			) }
		</VStack>
	) : null;

	return (
		<ConnectorItem
			className={ `connector-item--${ providerCfg.slug }` }
			logo={ <ProviderLogo text={ iconText } /> }
			name={ label }
			description={ description }
			actionArea={ actionArea }
		>
			{ settingsPanel }
		</ConnectorItem>
	);
}

// ---------------------------------------------------------------------------
// Registration. WP core's `routes/connectors-home/content` module runs
// `registerDefaultConnectors()` from inside an async dynamic import. By the
// time it executes, our top-level registerConnector() has already populated
// the store — and the store reducer spreads new config over existing
// entries, so the default's `args.render = ApiKeyConnector` would overwrite
// our custom render. The proper fix is in WordPress/gutenberg#77116; until
// that ships we re-assert our registration on multiple ticks.
// ---------------------------------------------------------------------------

function registerProvider( providerCfg ) {
	registerConnector(
		providerCfg.slug,
		Object.freeze( {
			label: providerCfg.label,
			description: providerCfg.description,
			logo: <ProviderLogo text={ providerCfg.iconText } />,
			render: () => <ProviderConnectorCard providerCfg={ providerCfg } />,
		} )
	);
}

function registerAll() {
	Object.values( PROVIDERS ).forEach( registerProvider );
}

registerAll();
Promise.resolve().then( registerAll );
setTimeout( registerAll, 0 );
setTimeout( registerAll, 50 );
setTimeout( registerAll, 250 );
setTimeout( registerAll, 1000 );
