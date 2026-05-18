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
use AnthropicMaxAiProvider\Authentication\ChatGptCodexOAuthRequestAuthentication;
use AnthropicMaxAiProvider\Authentication\GoogleOAuthRequestAuthentication;
use AnthropicMaxAiProvider\OAuthPool\PoolManager;
use AnthropicMaxAiProvider\OAuthPool\PoolRegistry;
use AnthropicMaxAiProvider\Provider\AnthropicMaxProvider;
use AnthropicMaxAiProvider\Provider\ChatGptCodexProvider;
use AnthropicMaxAiProvider\Provider\GoogleAiProProvider;

/**
 * Registers all available OAuth-backed AI providers with the AI Client on init.
 *
 * Runs at priority 5 so providers are available before most plugins
 * act on `init` at the default priority of 10. Each provider is only
 * registered when its underlying account pool has at least one entry,
 * so an empty pool leaves the WordPress AiClient registry untouched.
 */
function register_provider(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	// --- Anthropic Max (Claude via Max subscription OAuth) ---
	$anthropic_pool = PoolManager::getInstance();
	if ( $anthropic_pool->count() > 0
		&& ! $registry->hasProvider( AnthropicMaxProvider::class )
	) {
		$registry->registerProvider( AnthropicMaxProvider::class );
		$registry->setProviderRequestAuthentication(
			AnthropicMaxProvider::class,
			new AnthropicOAuthRequestAuthentication( $anthropic_pool )
		);
	}

	// --- ChatGPT Codex (OpenAI ChatGPT Plus/Pro/Team OAuth → Codex backend) ---
	// Distinct provider id (`ultimate-ai-connector-chatgpt-codex`) because
	// the OAuth token is rejected by `api.openai.com` and only works
	// against `chatgpt.com/backend-api/codex/responses`. See
	// ChatGptCodexProvider class docblock for the live-test evidence.
	$openai_pool = PoolRegistry::pool( 'openai' );
	if ( $openai_pool->count() > 0
		&& ! $registry->hasProvider( ChatGptCodexProvider::class )
	) {
		$registry->registerProvider( ChatGptCodexProvider::class );
		$registry->setProviderRequestAuthentication(
			ChatGptCodexProvider::class,
			new ChatGptCodexOAuthRequestAuthentication( $openai_pool )
		);
	}

	// --- Google AI Pro (Gemini via OAuth subscription, not API key) ---
	// Distinct provider id (`ultimate-ai-connector-google-ai-pro`) to avoid
	// collision with the canonical `ai-provider-for-google` plugin that
	// targets the API-key auth path. The endpoint base URL is the same
	// (`generativelanguage.googleapis.com/v1beta`) so request/response
	// shapes mirror the canonical plugin; only the auth header differs
	// (Bearer vs X-Goog-Api-Key).
	$google_pool = PoolRegistry::pool( 'google' );
	if ( $google_pool->count() > 0
		&& ! $registry->hasProvider( GoogleAiProProvider::class )
	) {
		$registry->registerProvider( GoogleAiProProvider::class );
		$registry->setProviderRequestAuthentication(
			GoogleAiProProvider::class,
			new GoogleOAuthRequestAuthentication( $google_pool )
		);
	}
}
