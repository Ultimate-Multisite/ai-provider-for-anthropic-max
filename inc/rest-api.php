<?php
/**
 * REST API endpoints for the OAuth account pool.
 *
 * Provides endpoints for listing, adding, removing, refreshing, and
 * health-checking OAuth accounts used by the Anthropic Max provider.
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

/**
 * Registers all REST API routes for the plugin.
 */
function register_routes(): void {
	$namespace = 'anthropic-max-pool/v1';

	// List accounts (sanitized, no tokens exposed).
	register_rest_route(
		$namespace,
		'/accounts',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_list_accounts',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
		]
	);

	// Start OAuth flow (returns authorize URL).
	register_rest_route(
		$namespace,
		'/authorize',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_start_oauth',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
			'args'                => [
				'login_hint'   => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_email',
					'description'       => 'Pre-populate the email field on the Anthropic login page.',
				],
				'login_method' => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'enum'              => [ 'sso', 'magic_link', 'google' ],
					'description'       => 'Request a specific login method.',
				],
				'org_uuid'     => [
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => 'Pre-select an org UUID for team/enterprise logins.',
				],
			],
		]
	);

	// Exchange authorization code for tokens.
	register_rest_route(
		$namespace,
		'/exchange',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_exchange_code',
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

	// Remove an account.
	// Uses POST instead of DELETE because many server configurations (Apache
	// mod_security, reverse proxies, multisite rewrites) silently convert or
	// block DELETE requests, causing a 404 at the routing level.
	// The email is passed in the JSON body to avoid encoding @ in the URL path.
	register_rest_route(
		$namespace,
		'/accounts/remove',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_remove_account',
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

	// Refresh a specific account's token.
	// Email in the JSON body (not the URL path) to avoid encoding @ in the URL.
	register_rest_route(
		$namespace,
		'/accounts/refresh',
		[
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\rest_refresh_account',
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

	// Health check all accounts.
	register_rest_route(
		$namespace,
		'/health',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\rest_health_check',
			'permission_callback' => __NAMESPACE__ . '\\can_manage',
		]
	);
}

/**
 * Permission callback: requires manage_options capability.
 *
 * @return bool
 */
function can_manage(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * Lists all accounts in the pool (no tokens exposed).
 *
 * @return \WP_REST_Response
 */
function rest_list_accounts(): \WP_REST_Response {
	$pool     = PoolManager::getInstance();
	$accounts = $pool->listAccounts();
	return rest_ensure_response( $accounts );
}

/**
 * Starts the OAuth PKCE flow and returns the authorize URL.
 *
 * Accepts optional query params:
 *   - login_hint:   pre-populate the email on the Anthropic login page.
 *   - login_method: request a specific login method ('sso', 'magic_link', 'google').
 *   - org_uuid:     pre-select an org for team/enterprise logins.
 *
 * @param \WP_REST_Request $request The request object.
 * @return \WP_REST_Response
 */
function rest_start_oauth( \WP_REST_Request $request ): \WP_REST_Response {
	$pool = PoolManager::getInstance();
	$data = $pool->startOAuthFlow(
		$request->get_param( 'login_hint' ) ?: null,
		$request->get_param( 'login_method' ) ?: null,
		$request->get_param( 'org_uuid' ) ?: null
	);

	// Only return the authorize URL and state (never the verifier).
	return rest_ensure_response( [
		'authorize_url' => $data['authorize_url'],
		'state'         => $data['state'],
	] );
}

/**
 * Exchanges an authorization code for tokens and adds the account to the pool.
 *
 * @param \WP_REST_Request $request The request object.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_exchange_code( \WP_REST_Request $request ) {
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

	// Scope validation failure: the token was issued but is missing user:inference.
	// The account was NOT added to the pool. Return a clear error so the UI can
	// prompt the user to re-authorize with the correct scopes.
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

/**
 * Removes an account from the pool.
 *
 * @param \WP_REST_Request $request The request object.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_remove_account( \WP_REST_Request $request ) {
	$email = $request->get_param( 'email' );
	$pool  = PoolManager::getInstance();

	if ( ! $pool->removeAccount( $email ) ) {
		return new \WP_Error(
			'not_found',
			__( 'Account not found in pool.', 'ai-provider-for-anthropic-max' ),
			[ 'status' => 404 ]
		);
	}

	return rest_ensure_response( [
		'success' => true,
		'count'   => $pool->count(),
	] );
}

/**
 * Refreshes a specific account's OAuth token.
 *
 * @param \WP_REST_Request $request The request object.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_refresh_account( \WP_REST_Request $request ) {
	$email = $request->get_param( 'email' );
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

/**
 * Health checks all accounts in the pool.
 *
 * @return \WP_REST_Response
 */
function rest_health_check(): \WP_REST_Response {
	$pool    = PoolManager::getInstance();
	$results = $pool->healthCheck();
	return rest_ensure_response( $results );
}
