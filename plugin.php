<?php
/**
 * Plugin Name: AI Provider for Anthropic Max
 * Plugin URI: https://github.com/Ultimate-Multisite/ai-provider-for-anthropic-max
 * Description: Anthropic provider for the WordPress AI Client using Claude Max OAuth tokens with account pool rotation.
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.0
 * Author: Ultimate Multisite Community
 * Author URI: https://ultimatemultisite.com
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-anthropic-max
 *
 * @package AnthropicMaxAiProvider
 */

namespace AnthropicMaxAiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

define( 'ANTHROPIC_MAX_AI_PROVIDER_VERSION', '1.0.0' );

// ---------------------------------------------------------------------------
// PSR-4 autoloader for src/ classes.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/src/autoload.php';

// ---------------------------------------------------------------------------
// Load function files (no SDK dependency).
// ---------------------------------------------------------------------------

require_once __DIR__ . '/inc/settings.php';
require_once __DIR__ . '/inc/admin.php';
require_once __DIR__ . '/inc/rest-api.php';

// ---------------------------------------------------------------------------
// Load SDK-dependent files only when the AI Client SDK is available.
// These files extend SDK abstract classes and will fatal if loaded without it.
// ---------------------------------------------------------------------------

if ( class_exists( 'WordPress\\AiClient\\Providers\\ApiBasedImplementation\\AbstractApiProvider' ) ) {
	require_once __DIR__ . '/inc/provider-registration.php';
}

// ---------------------------------------------------------------------------
// Hook registrations.
// ---------------------------------------------------------------------------

// Settings.
add_action( 'admin_init', 'AnthropicMaxAiProvider\\Settings\\register_settings' );
add_action( 'rest_api_init', 'AnthropicMaxAiProvider\\Settings\\register_settings' );

// Connectors page (React UI) — fires on Settings > Connectors (wp-admin integrated).
add_action( 'options-connectors-wp-admin_init', 'AnthropicMaxAiProvider\\Admin\\enqueue_connector_module' );

// REST API.
add_action( 'rest_api_init', 'AnthropicMaxAiProvider\\RestApi\\register_routes' );

// Provider registration (only when SDK classes are available).
if ( function_exists( 'AnthropicMaxAiProvider\\Registration\\register_provider' ) ) {
	add_action( 'init', 'AnthropicMaxAiProvider\\Registration\\register_provider', 5 );
}
