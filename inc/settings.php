<?php
/**
 * Settings registration for the AI Provider for Anthropic Max plugin.
 *
 * Note: Unlike the API-key provider, this plugin stores its configuration
 * (the OAuth account pool) as a serialized option rather than individual
 * settings. The pool is managed entirely through the REST API and React UI.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

namespace AnthropicMaxAiProvider\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin settings for the REST API.
 *
 * The pool data itself is NOT exposed via the settings REST API (it
 * contains tokens). Only safe metadata is exposed through the custom
 * REST endpoints in rest-api.php.
 */
function register_settings(): void {
	// No individual settings to register — pool is managed via custom REST API.
	// This function exists as a hook point for future settings if needed.
}
