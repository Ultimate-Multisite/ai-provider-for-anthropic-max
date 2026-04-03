<?php
/**
 * Provider registration with the WordPress AI Client.
 *
 * Registers the Anthropic Max provider and injects OAuth authentication
 * from the account pool.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

namespace AnthropicMaxAiProvider\Registration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\AiClient;
use AnthropicMaxAiProvider\Authentication\AnthropicOAuthRequestAuthentication;
use AnthropicMaxAiProvider\OAuthPool\PoolManager;
use AnthropicMaxAiProvider\Provider\AnthropicMaxProvider;

/**
 * Registers the Anthropic Max provider with the AI Client on init.
 *
 * Runs at priority 5 so the provider is available before most plugins
 * act on `init` at the default priority of 10.
 */
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	$pool = PoolManager::getInstance();

	// Only register if there are accounts in the pool.
	if ( $pool->count() === 0 ) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	if ( $registry->hasProvider( AnthropicMaxProvider::class ) ) {
		return;
	}

	$registry->registerProvider( AnthropicMaxProvider::class );

	// Inject the OAuth authentication using the pool manager.
	$registry->setProviderRequestAuthentication(
		AnthropicMaxProvider::class,
		new AnthropicOAuthRequestAuthentication( $pool )
	);
}
