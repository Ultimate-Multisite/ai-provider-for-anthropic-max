<?php
/**
 * Admin integration for the AI Provider for Anthropic Max plugin.
 *
 * Enqueues the React connector module on the Connectors admin page.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

namespace AnthropicMaxAiProvider\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the connector script module on the Connectors page.
 *
 * The `options-connectors-wp-admin_init` action fires only on the
 * Settings > Connectors page (wp-admin integrated view), so the module
 * is loaded only where it is needed.
 */
function enqueue_connector_module(): void {
	$asset_file = __DIR__ . '/../build/connector.js';
	$version     = file_exists( $asset_file )
		? filemtime( $asset_file )
		: ANTHROPIC_MAX_AI_PROVIDER_VERSION;

	wp_register_script_module(
		'ai-provider-for-anthropic-max',
		ANTHROPIC_MAX_AI_PROVIDER_URL . 'build/connector.js',
		[
			[
				'id'     => '@wordpress/connectors',
				'import' => 'static',
			],
		],
		$version
	);
	wp_enqueue_script_module( 'ai-provider-for-anthropic-max' );
}
