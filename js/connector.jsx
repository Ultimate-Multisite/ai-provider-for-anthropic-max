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

const REST_NS = '/anthropic-max-pool/v1';

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
		slug: 'ultimate-ai-connector-openai-codex',
		label: __( 'OpenAI ChatGPT/Codex' ),
		description: __(
			'Use ChatGPT Plus/Pro or Codex via OAuth. Paste the auth code from the localhost redirect.'
		),
		mode: 'oauth-paste',
		emailRequired: true,
		instructions: __(
			'After signing in, OpenAI redirects to a localhost URL. Copy the entire URL (or just the code+state value) from your browser bar and paste it below.'
		),
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

function ProviderLogo( { text } ) {
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
				fontSize={ text.length > 3 ? 11 : 14 }
				fontWeight="800"
				letterSpacing="0.5"
				fill="currentColor"
			>
				{ text }
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
	const [ error, setError ] = useState( null );
	const [ showManualLink, setShowManualLink ] = useState( false );

	const handleStartOAuth = async () => {
		if ( emailRequired && ( ! email || ! email.includes( '@' ) ) ) {
			setError( __( 'Enter a valid email address.' ) );
			return;
		}
		setError( null );
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
			setError(
				err instanceof Error ? err.message : __( 'Failed to start OAuth flow.' )
			);
		} finally {
			setIsBusy( false );
		}
	};

	const handleExchangeCode = async () => {
		if ( ! authCode.trim() ) {
			setError( __( 'Paste the authorization code.' ) );
			return;
		}
		setError( null );
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
			onComplete();
		} catch ( err ) {
			setError(
				err instanceof Error ? err.message : __( 'Code exchange failed.' )
			);
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<VStack spacing={ 3 } style={ { marginTop: '12px' } }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
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
					{ showManualLink ? (
						<Notice status="warning" isDismissible={ false }>
							<VStack spacing={ 2 }>
								<span>
									{ __(
										'Popups are blocked in this environment. Open the authorization link below in a new browser tab, log in, then copy the code shown and paste it here.'
									) }
								</span>
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
												.then( () => setError( null ) );
										} }
									>
										{ __( 'Copy link' ) }
									</Button>
								</HStack>
							</VStack>
						</Notice>
					) : (
						<Fragment>
							<Notice status="info" isDismissible={ false }>
								{ instructions }
							</Notice>
							{ ! showManualLink && authorizeUrl && (
								<Button
									variant="link"
									onClick={ () => setShowManualLink( true ) }
									style={ { fontSize: '12px', padding: 0 } }
								>
									{ __(
										"Window didn't open or was blocked? Click here for a direct link."
									) }
								</Button>
							) }
						</Fragment>
					) }
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Authorization Code or Redirect URL' ) }
						value={ authCode }
						onChange={ setAuthCode }
						placeholder={ __(
							'Paste the code (or the full localhost URL you were redirected to)'
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
	const [ error, setError ] = useState( null );

	const handleSubmit = async () => {
		if ( ! accessToken.trim() ) {
			setError( __( 'Paste the access token.' ) );
			return;
		}
		setError( null );
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
			onComplete();
		} catch ( err ) {
			setError(
				err instanceof Error ? err.message : __( 'Failed to add account.' )
			);
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<VStack spacing={ 3 } style={ { marginTop: '12px' } }>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<Notice status="info" isDismissible={ false }>
				{ instructions }
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
	const [ notice, setNotice ] = useState( null );

	const isConnected = accounts.length > 0;
	const supportsRefresh = mode === 'oauth-paste'; // Manual-token providers can't refresh.

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
		setNotice( null );
		try {
			await apiFetch( {
				method: 'POST',
				path: `${ REST_NS }/${ id }/accounts/remove`,
				data: { email },
			} );
			await fetchAccounts();
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err instanceof Error ? err.message : __( 'Failed to remove account.' ),
			} );
		} finally {
			setIsBusy( false );
		}
	};

	const handleRefresh = async ( email ) => {
		setIsBusy( true );
		setNotice( null );
		try {
			await apiFetch( {
				method: 'POST',
				path: `${ REST_NS }/${ id }/accounts/refresh`,
				data: { email },
			} );
			setNotice( { status: 'success', message: __( 'Token refreshed.' ) } );
			await fetchAccounts();
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err instanceof Error ? err.message : __( 'Refresh failed.' ),
			} );
		} finally {
			setIsBusy( false );
		}
	};

	const handleHealthCheck = async () => {
		setIsBusy( true );
		setNotice( null );
		try {
			const results = await apiFetch( {
				path: `${ REST_NS }/${ id }/health`,
			} );
			const ok = results.filter( ( r ) => r.validity === 'ok' ).length;
			setNotice( {
				status: ok === results.length ? 'success' : 'warning',
				message:
					ok + '/' + results.length + ' ' + __( 'accounts healthy' ),
			} );
			await fetchAccounts();
		} catch {
			setNotice( {
				status: 'error',
				message: __( 'Health check failed.' ),
			} );
		} finally {
			setIsBusy( false );
		}
	};

	const handleButtonClick = () => {
		setIsExpanded( ! isExpanded );
		setIsAdding( false );
		setNotice( null );
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

	const AddForm = mode === 'manual-token' ? ManualTokenAddForm : OAuthAddForm;

	const settingsPanel = isExpanded ? (
		<VStack spacing={ 4 } className="connector-settings">
			{ notice && (
				<Notice
					status={ notice.status }
					isDismissible
					onDismiss={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
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
