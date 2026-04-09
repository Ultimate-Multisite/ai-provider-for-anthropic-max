/**
 * AI Provider for Anthropic Max -- Connectors page integration.
 *
 * Registers a card on Settings > Connectors that lets admins manage
 * the Anthropic Max OAuth account pool: add, remove, refresh, and
 * health-check accounts used for Claude Max subscriptions.
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
	Spinner,
	Notice,
	__experimentalHStack: HStack,
	__experimentalVStack: VStack,
	__experimentalText: Text,
} = wp.components;
const { __ } = wp.i18n;
const apiFetch = wp.apiFetch;

/**
 * Anthropic logo icon.
 */
function Logo() {
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
				fontSize="14"
				fontWeight="800"
				letterSpacing="0.5"
				fill="currentColor"
			>
				MAX
			</text>
		</svg>
	);
}

/**
 * Green "Connected" badge.
 */
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

/**
 * Status badge for an individual account.
 */
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

/**
 * Single account row in the pool list.
 */
function AccountRow( { account, onRemove, onRefresh, isBusy } ) {
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
			<StatusBadge
				status={ account.status }
				validity={ account.validity }
			/>
			<span style={ { fontSize: '12px', color: '#757575' } }>
				{ account.tokenExpired
					? __( 'expired' )
					: account.expiresIn > 0
					? Math.round( account.expiresIn / 60 ) +
					  'm ' +
					  __( 'remaining' )
					: '' }
			</span>
			<Button
				variant="tertiary"
				size="small"
				onClick={ () => onRefresh( account.email ) }
				disabled={ isBusy || ! account.hasRefresh }
			>
				{ __( 'Refresh' ) }
			</Button>
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

/**
 * OAuth flow form: email input + authorize + paste code.
 */
function AddAccountForm( { onComplete, onCancel } ) {
	const [ step, setStep ] = useState( 'email' );
	const [ email, setEmail ] = useState( '' );
	const [ oauthState, setOauthState ] = useState( '' );
	const [ authCode, setAuthCode ] = useState( '' );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );

	const handleStartOAuth = async () => {
		if ( ! email || ! email.includes( '@' ) ) {
			setError( __( 'Enter a valid email address.' ) );
			return;
		}
		setError( null );
		setIsBusy( true );
		try {
			const data = await apiFetch( {
				path: '/anthropic-max-pool/v1/authorize',
			} );
			setOauthState( data.state );
			window.open( data.authorize_url, '_blank', 'noopener' );
			setStep( 'code' );
		} catch ( err ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Failed to start OAuth flow.' )
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
				path: '/anthropic-max-pool/v1/exchange',
				data: {
					code: authCode.trim(),
					state: oauthState,
					email,
				},
			} );
			onComplete();
		} catch ( err ) {
			setError(
				err instanceof Error
					? err.message
					: __( 'Code exchange failed.' )
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
			{ step === 'email' && (
				<Fragment>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Claude Max Account Email' ) }
						type="email"
						value={ email }
						onChange={ setEmail }
						placeholder="you@example.com"
						disabled={ isBusy }
						help={ __(
							'The email address of your Claude Max subscription account.'
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
							{ __( 'Authorize with Claude' ) }
						</Button>
						<Button variant="tertiary" onClick={ onCancel }>
							{ __( 'Cancel' ) }
						</Button>
					</HStack>
				</Fragment>
			) }
			{ step === 'code' && (
				<Fragment>
					<Notice status="info" isDismissible={ false }>
						{ __(
							'A new window opened for Claude authorization. Log in, then copy the authorization code shown and paste it below.'
						) }
					</Notice>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Authorization Code' ) }
						value={ authCode }
						onChange={ setAuthCode }
						placeholder={ __(
							'Paste the code from the Claude window'
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

/**
 * Main connector card component.
 */
function AnthropicMaxConnectorCard( { slug, label, description, logo } ) {
	const [ accounts, setAccounts ] = useState( [] );
	const [ isExpanded, setIsExpanded ] = useState( false );
	const [ isAdding, setIsAdding ] = useState( false );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isBusy, setIsBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const isConnected = accounts.length > 0;

	const fetchAccounts = useCallback( async () => {
		try {
			const data = await apiFetch( {
				path: '/anthropic-max-pool/v1/accounts',
			} );
			setAccounts( Array.isArray( data ) ? data : [] );
		} catch {
			setAccounts( [] );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		fetchAccounts();
	}, [ fetchAccounts ] );

	const handleRemove = async ( email ) => {
		setIsBusy( true );
		setNotice( null );
		try {
			await apiFetch( {
				method: 'DELETE',
				path:
					'/anthropic-max-pool/v1/accounts/' +
					encodeURIComponent( email ),
			} );
			await fetchAccounts();
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err instanceof Error
						? err.message
						: __( 'Failed to remove account.' ),
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
				path:
					'/anthropic-max-pool/v1/accounts/' +
					encodeURIComponent( email ) +
					'/refresh',
			} );
			setNotice( {
				status: 'success',
				message: __( 'Token refreshed.' ),
			} );
			await fetchAccounts();
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message:
					err instanceof Error
						? err.message
						: __( 'Refresh failed.' ),
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
				path: '/anthropic-max-pool/v1/health',
			} );
			const ok = results.filter( ( r ) => r.validity === 'ok' ).length;
			setNotice( {
				status: ok === results.length ? 'success' : 'warning',
				message:
					ok +
					'/' +
					results.length +
					' ' +
					__( 'accounts healthy' ),
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
				variant={
					isExpanded || isConnected ? 'tertiary' : 'secondary'
				}
				size={
					isExpanded || isConnected ? undefined : 'compact'
				}
				onClick={ handleButtonClick }
				disabled={ isLoading }
				aria-expanded={ isExpanded }
			>
				{ getButtonLabel() }
			</Button>
		</HStack>
	);

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
						/>
					) ) }
				</VStack>
			) }

			{ accounts.length === 0 && ! isAdding && (
				<Text style={ { color: '#757575' } }>
					{ __(
						'No accounts configured. Add a Claude Max account to get started.'
					) }
				</Text>
			) }

			{ isAdding ? (
				<AddAccountForm
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
			className="connector-item--ultimate-ai-connector-anthropic-max"
			logo={ logo || <Logo /> }
			name={ label }
			description={ description }
			actionArea={ actionArea }
		>
			{ settingsPanel }
		</ConnectorItem>
	);
}

// Register the connector card.
//
// SLUG matches the PHP provider id from AnthropicMaxProvider::createProviderMetadata()
// so the WP core Connectors page renders ONE card instead of two (the
// auto-discovered server entry + a separately-keyed JS entry).
const SLUG = 'ultimate-ai-connector-anthropic-max';
const CONFIG = {
	label: __( 'Anthropic Max' ),
	description: __(
		'Use Claude with your Max subscription via OAuth. Supports account pool rotation for reliability.'
	),
	logo: <Logo />,
	render: AnthropicMaxConnectorCard,
};

// WP core's `routes/connectors-home/content` module runs
// `registerDefaultConnectors()` from inside an async dynamic import. By the
// time it executes, our top-level registerConnector() has already populated
// the store — and the store reducer spreads new config over existing
// entries, so the default's `args.render = ApiKeyConnector` would overwrite
// our custom render. The proper fix is in WordPress/gutenberg#77116; until
// that ships we re-assert our registration on five ticks (sync + microtask
// + setTimeout 0/50/250/1000ms) so our render always ends up last regardless
// of dynamic-import resolution order. Re-registering with the same render
// reference is idempotent so the redundant calls cost essentially nothing.
function registerOurs() {
	registerConnector( SLUG, CONFIG );
}

registerOurs();
Promise.resolve().then( registerOurs );
setTimeout( registerOurs, 0 );
setTimeout( registerOurs, 50 );
setTimeout( registerOurs, 250 );
setTimeout( registerOurs, 1000 );
