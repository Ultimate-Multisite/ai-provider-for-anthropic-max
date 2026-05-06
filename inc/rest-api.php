<?php
/**
 * REST API endpoints for the OAuth account pools.
 *
 * Provides endpoints for listing, adding, removing, refreshing, and
 * health-checking OAuth accounts across all supported providers
 * (anthropic, openai, cursor, google).
 *
 * Two route shapes are exposed:
 *
 *   1. Per-provider (1.2.0+):
 *        /anthropic-max-pool/v1/providers
 *        /anthropic-max-pool/v1/{provider}/accounts
 *        /anthropic-max-pool/v1/{provider}/authorize
 *        /anthropic-max-pool/v1/{provider}/exchange
 *        /anthropic-max-pool/v1/{provider}/manual
 *        /anthropic-max-pool/v1/{provider}/accounts/remove
 *        /anthropic-max-pool/v1/{provider}/accounts/refresh
 *        /anthropic-max-pool/v1/{provider}/health
 *
 *   2. Legacy (1.0/1.1 — Anthropic only, kept stable):
 *        /anthropic-max-pool/v1/accounts
 *        /anthropic-max-pool/v1/authorize
 *        /anthropic-max-pool/v1/exchange
 *        /anthropic-max-pool/v1/accounts/remove
 *        /anthropic-max-pool/v1/accounts/refresh
 *        /anthropic-max-pool/v1/health
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

namespace AnthropicMaxAiProvider\RestApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use AnthropicMaxAiProvider\OAuthPool\PoolManager;
use AnthropicMaxAiProvider\OAuthPool\PoolRegistry;
use AnthropicMaxAiProvider\OAuthPool\ProviderConfig;
use AnthropicMaxAiProvider\OAuthPool\ProviderPool;

/**
 * Registers all REST API routes for the plugin.
 */
function register_routes(): void {
	$namespace = 'anthropic-max-pool/v1';

	// -----------------------------------------------------------------
	// Provider directory.
	// -----------------------------------------------------------------
	register_rest_route(
		$namespace,
		'/providers',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_list_providers',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
		]
	);

	// -----------------------------------------------------------------
	// Per-provider routes (1.2.0+).
	// -----------------------------------------------------------------
	$provider_arg = [
		'provider' => [
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
			'enum'              => PoolRegistry::supportedIds(),
		],
	];

	register_rest_route(
		$namespace,
		'/(?P<provider>[a-z0-9_-]+)/accounts',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_list_accounts',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => $provider_arg,
		]
	);

	register_rest_route(
		$namespace,
		'/(?P<provider>[a-z0-9_-]+)/authorize',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_start_oauth',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array_merge(
				$provider_arg,
				[
					'login_hint'   => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
					'login_method' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'sso', 'magic_link', 'google' ],
					],
				]
			),
		]
	);

	register_rest_route(
		$namespace,
		'/(?P<provider>[a-z0-9_-]+)/exchange',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_exchange_code',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array_merge(
				$provider_arg,
				[
					'code'  => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'state' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'email' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
				]
			),
		]
	);

	register_rest_route(
		$namespace,
		'/(?P<provider>[a-z0-9_-]+)/manual',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_add_manual',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array_merge(
				$provider_arg,
				[
					'access_token'  => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'refresh_token' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'email'         => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'expires_in'    => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				]
			),
		]
	);

	register_rest_route(
		$namespace,
		'/(?P<provider>[a-z0-9_-]+)/accounts/remove',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_remove_account',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array_merge(
				$provider_arg,
				[
					'email' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				]
			),
		]
	);

	register_rest_route(
		$namespace,
		'/(?P<provider>[a-z0-9_-]+)/accounts/refresh',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_refresh_account',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => array_merge(
				$provider_arg,
				[
					'email' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				]
			),
		]
	);

	register_rest_route(
		$namespace,
		'/(?P<provider>[a-z0-9_-]+)/health',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_health_check',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => $provider_arg,
		]
	);

	// -----------------------------------------------------------------
	// Legacy routes (1.0/1.1) — Anthropic only.
	//
	// These delegate to the per-provider handlers with provider=anthropic
	// so existing clients keep working unchanged.
	// -----------------------------------------------------------------
	register_rest_route(
		$namespace,
		'/accounts',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_legacy_list_accounts',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
		]
	);

	register_rest_route(
		$namespace,
		'/authorize',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_legacy_start_oauth',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => [
				'login_hint'   => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				],
				'login_method' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'enum'              => [ 'sso', 'magic_link', 'google' ],
				],
				'org_uuid'     => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]
	);

	register_rest_route(
		$namespace,
		'/exchange',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_legacy_exchange_code',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => [
				'code'  => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'state' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'email' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
				],
			],
		]
	);

	register_rest_route(
		$namespace,
		'/accounts/remove',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_legacy_remove_account',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => [
				'email' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]
	);

	register_rest_route(
		$namespace,
		'/accounts/refresh',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_legacy_refresh_account',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => [
				'email' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		]
	);

	register_rest_route(
		$namespace,
		'/health',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_legacy_health_check',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
		]
	);
}

/**
 * Permission callback: requires manage_options capability.
 */
function can_manage(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Resolves the pool for the {provider} URL parameter, or returns a WP_Error.
 *
 * @param \WP_REST_Request $request
 * @return ProviderPool|\WP_Error
 */
function resolve_pool( \WP_REST_Request $request ) {
	$id = (string) $request->get_param( 'provider' );
	if ( ! in_array( $id, PoolRegistry::supportedIds(), true ) ) {
		return new \WP_Error(
			'unknown_provider',
			sprintf(
				/* translators: %s: provider id */
				__( 'Unknown provider "%s".', 'ai-provider-for-anthropic-max' ),
				$id
			),
			[ 'status' => 404 ]
		);
	}
	return PoolRegistry::pool( $id );
}

// ---------------------------------------------------------------------------
// Per-provider handlers
// ---------------------------------------------------------------------------

/**
 * Lists all known providers along with their account counts.
 */
function rest_list_providers(): \WP_REST_Response {
	$providers = [];
	foreach ( PoolRegistry::supportedIds() as $id ) {
		$pool                = PoolRegistry::pool( $id );
		$cfg                 = $pool->getConfig();
		$providers[ $id ] = [
			'id'            => $cfg->id,
			'label'         => $cfg->label,
			'description'   => $cfg->description,
			'count'         => $pool->count(),
			'supportsOAuth' => $cfg->supportsOAuth,
		];
	}
	return rest_ensure_response( $providers );
}

function rest_list_accounts( \WP_REST_Request $request ) {
	$pool = resolve_pool( $request );
	if ( $pool instanceof \WP_Error ) {
		return $pool;
	}
	return rest_ensure_response( $pool->listAccounts() );
}

function rest_start_oauth( \WP_REST_Request $request ) {
	$pool = resolve_pool( $request );
	if ( $pool instanceof \WP_Error ) {
		return $pool;
	}
	if ( ! $pool->getConfig()->supportsOAuth ) {
		return new \WP_Error(
			'oauth_unsupported',
			sprintf(
				/* translators: %s: provider label */
				__( '%s does not support OAuth — use the manual token form instead.', 'ai-provider-for-anthropic-max' ),
				$pool->getConfig()->label
			),
			[ 'status' => 400 ]
		);
	}

	try {
		$data = $pool->startOAuthFlow(
			$request->get_param( 'login_hint' ) ?: null,
			$request->get_param( 'login_method' ) ?: null
		);
	} catch ( \Throwable $e ) {
		return new \WP_Error( 'oauth_start_failed', $e->getMessage(), [ 'status' => 500 ] );
	}

	return rest_ensure_response( [
		'authorize_url' => $data['authorize_url'],
		'state'         => $data['state'],
		'redirect_uri'  => $pool->getConfig()->redirectUri,
	] );
}

function rest_exchange_code( \WP_REST_Request $request ) {
	$pool = resolve_pool( $request );
	if ( $pool instanceof \WP_Error ) {
		return $pool;
	}

	$code  = $request->get_param( 'code' );
	$state = $request->get_param( 'state' );
	$email = $request->get_param( 'email' );

	if ( empty( $code ) || empty( $state ) || empty( $email ) ) {
		return new \WP_Error(
			'missing_params',
			__( 'Code, state, and email are required.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 400 ]
		);
	}

	$result = $pool->exchangeCode( $code, $state, $email );
	if ( $result === null ) {
		return new \WP_Error(
			'exchange_failed',
			__( 'Failed to exchange authorization code. The code may be expired or the state is invalid.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 400 ]
		);
	}

	if ( ! empty( $result['scope_error'] ) ) {
		$granted = implode( ' ', (array) ( $result['granted_scopes'] ?? [] ) );
		return new \WP_Error(
			'insufficient_scope',
			sprintf(
				/* translators: %s: granted scope list */
				__( 'Authorization succeeded but the token is missing a required scope. Granted: %s', 'ai-provider-for-anthropic-max' ),
				$granted ?: __( '(none)', 'ai-provider-for-anthropic-max' )
			),
			[ 'status' => 403 ]
		);
	}

	return rest_ensure_response( [
		'success' => true,
		'message' => sprintf(
			/* translators: 1: email, 2: provider label */
			__( 'Account %1$s added to %2$s pool.', 'ai-provider-for-anthropic-max' ),
			$email,
			$pool->getConfig()->label
		),
		'count'   => $pool->count(),
	] );
}

function rest_add_manual( \WP_REST_Request $request ) {
	$pool = resolve_pool( $request );
	if ( $pool instanceof \WP_Error ) {
		return $pool;
	}

	$access  = (string) $request->get_param( 'access_token' );
	$refresh = (string) ( $request->get_param( 'refresh_token' ) ?? '' );
	$email   = (string) ( $request->get_param( 'email' ) ?? '' );
	$exp     = $request->get_param( 'expires_in' );
	$exp_in  = is_numeric( $exp ) ? (int) $exp : null;

	if ( $access === '' ) {
		return new \WP_Error(
			'missing_params',
			__( 'access_token is required.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 400 ]
		);
	}

	$res = $pool->addAccountManual( $email, $access, $refresh, $exp_in );

	return rest_ensure_response( [
		'success' => true,
		'message' => sprintf(
			/* translators: 1: email, 2: provider label */
			__( 'Account %1$s added to %2$s pool.', 'ai-provider-for-anthropic-max' ),
			$res['email'],
			$pool->getConfig()->label
		),
		'email'   => $res['email'],
		'count'   => $res['count'],
	] );
}

function rest_remove_account( \WP_REST_Request $request ) {
	$pool = resolve_pool( $request );
	if ( $pool instanceof \WP_Error ) {
		return $pool;
	}

	$email  = (string) $request->get_param( 'email' );
	$result = $pool->removeAccount( $email );

	if ( $result === ProviderPool::REMOVE_NOT_FOUND ) {
		return new \WP_Error(
			'not_found',
			__( 'Account not found in pool.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 404 ]
		);
	}
	if ( $result === ProviderPool::REMOVE_SAVE_ERROR ) {
		return new \WP_Error(
			'save_failed',
			__( 'Account removed but could not be saved.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 500 ]
		);
	}

	return rest_ensure_response( [ 'success' => true, 'count' => $pool->count() ] );
}

function rest_refresh_account( \WP_REST_Request $request ) {
	$pool = resolve_pool( $request );
	if ( $pool instanceof \WP_Error ) {
		return $pool;
	}

	$email = (string) $request->get_param( 'email' );
	if ( ! $pool->refreshAccount( $email ) ) {
		return new \WP_Error(
			'refresh_failed',
			__( 'Token refresh failed. The account may need to be re-authorized.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 500 ]
		);
	}

	return rest_ensure_response( [
		'success' => true,
		'message' => sprintf(
			/* translators: %s: email address */
			__( 'Token refreshed for %s.', 'ai-provider-for-anthropic-max' ),
			$email
		),
	] );
}

function rest_health_check( \WP_REST_Request $request ) {
	$pool = resolve_pool( $request );
	if ( $pool instanceof \WP_Error ) {
		return $pool;
	}
	return rest_ensure_response( $pool->healthCheck() );
}

// ---------------------------------------------------------------------------
// Legacy handlers (Anthropic only)
// ---------------------------------------------------------------------------

function rest_legacy_list_accounts(): \WP_REST_Response {
	return rest_ensure_response( PoolManager::getInstance()->listAccounts() );
}

function rest_legacy_start_oauth( \WP_REST_Request $request ): \WP_REST_Response {
	$data = PoolManager::getInstance()->startOAuthFlow(
		$request->get_param( 'login_hint' ) ?: null,
		$request->get_param( 'login_method' ) ?: null,
		$request->get_param( 'org_uuid' ) ?: null
	);
	return rest_ensure_response( [
		'authorize_url' => $data['authorize_url'],
		'state'         => $data['state'],
	] );
}

function rest_legacy_exchange_code( \WP_REST_Request $request ) {
	$code  = $request->get_param( 'code' );
	$state = $request->get_param( 'state' );
	$email = $request->get_param( 'email' );

	if ( empty( $code ) || empty( $state ) || empty( $email ) ) {
		return new \WP_Error(
			'missing_params',
			__( 'Code, state, and email are required.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 400 ]
		);
	}

	$pool   = PoolManager::getInstance();
	$result = $pool->exchangeCode( $code, $state, $email );

	if ( $result === null ) {
		return new \WP_Error(
			'exchange_failed',
			__( 'Failed to exchange authorization code. The code may be expired or the state is invalid.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 400 ]
		);
	}

	if ( ! empty( $result['scope_error'] ) ) {
		$granted = implode( ' ', (array) ( $result['granted_scopes'] ?? [] ) );
		return new \WP_Error(
			'insufficient_scope',
			sprintf(
				/* translators: %s: space-separated list of granted scopes */
				__( 'Authorization succeeded but the token is missing the required "user:inference" scope. Granted scopes: %s. Please re-authorize and ensure you are using a Claude Max subscription account.', 'ai-provider-for-anthropic-max' ),
				$granted ?: __( '(none)', 'ai-provider-for-anthropic-max' )
			),
			[ 'status' => 403 ]
		);
	}

	return rest_ensure_response( [
		'success' => true,
		'message' => sprintf(
			/* translators: %s: email address */
			__( 'Account %s added to pool.', 'ai-provider-for-anthropic-max' ),
			$email
		),
		'count'   => $pool->count(),
	] );
}

function rest_legacy_remove_account( \WP_REST_Request $request ) {
	$email  = (string) $request->get_param( 'email' );
	$pool   = PoolManager::getInstance();
	$result = $pool->removeAccount( $email );

	if ( $result === PoolManager::REMOVE_NOT_FOUND ) {
		return new \WP_Error(
			'not_found',
			__( 'Account not found in pool.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 404 ]
		);
	}
	if ( $result === PoolManager::REMOVE_SAVE_ERROR ) {
		return new \WP_Error(
			'save_failed',
			__( 'Account removed but could not be saved to the database.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 500 ]
		);
	}

	return rest_ensure_response( [ 'success' => true, 'count' => $pool->count() ] );
}

function rest_legacy_refresh_account( \WP_REST_Request $request ) {
	$email = (string) $request->get_param( 'email' );
	$pool  = PoolManager::getInstance();
	if ( ! $pool->refreshAccount( $email ) ) {
		return new \WP_Error(
			'refresh_failed',
			__( 'Token refresh failed. The account may need to be re-authorized.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 500 ]
		);
	}
	return rest_ensure_response( [
		'success' => true,
		'message' => sprintf(
			/* translators: %s: email address */
			__( 'Token refreshed for %s.', 'ai-provider-for-anthropic-max' ),
			$email
		),
	] );
}

function rest_legacy_health_check(): \WP_REST_Response {
	return rest_ensure_response( PoolManager::getInstance()->healthCheck() );
}
