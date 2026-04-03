=== AI Provider for Anthropic Max ===
Contributors: superdav42
Tags: ai, anthropic, claude, oauth, max
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use Claude with your Max subscription via OAuth. Supports account pool rotation for reliability.

== Description ==

This plugin extends the WordPress AI Client to support **Anthropic Claude** using **Claude Max subscription tokens** instead of API keys. It uses the same OAuth flow as the Claude CLI to authenticate.

**Key features:**

* **OAuth authentication** -- No API key needed. Log in with your Claude Max account.
* **Account pool** -- Add multiple Max accounts for automatic rotation and failover.
* **Auto-refresh** -- Expired tokens are refreshed automatically using refresh tokens.
* **Rate limit handling** -- Rate-limited accounts are automatically rotated out with cooldowns.
* **Connectors page integration** -- Manage accounts from the familiar Settings > Connectors UI.

**Requirements by WordPress version:**

* **WordPress 7.0+** -- The AI Client SDK is included in core. This plugin works on its own.
* **WordPress 6.9** -- Requires the [AI Experiments](https://wordpress.org/plugins/ai/) plugin for the SDK.

**How it works:**

1. Install and activate the plugin.
2. Go to **Settings > Connectors** and find the "Anthropic Max" card.
3. Click "Set up" and enter your Claude Max account email.
4. Authorize with Claude in the browser window that opens.
5. Paste the authorization code back into the settings.
6. Repeat to add more accounts for pool rotation.

The plugin registers as a separate provider (`anthropic-max`) and coexists with the standard API-key-based Anthropic provider.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-anthropic-max/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. **WordPress 6.9 only:** Ensure the [AI Experiments](https://wordpress.org/plugins/ai/) plugin is active.
4. Go to **Settings > Connectors** and set up your Claude Max account(s).

== Frequently Asked Questions ==

= What is a Claude Max subscription? =

Claude Max is Anthropic's premium subscription plan that provides access to Claude models without per-token API billing. This plugin lets you use those subscription tokens within WordPress.

= Can I use this alongside the standard Anthropic provider? =

Yes. This plugin registers as "Anthropic Max" (`anthropic-max`), separate from the standard "Anthropic" (`anthropic`) provider. Both can be active simultaneously.

= Why add multiple accounts? =

Multiple accounts provide failover. If one account hits a rate limit, the plugin automatically switches to the next available account.

= How are tokens stored? =

OAuth tokens are stored in the WordPress options table. Only site administrators with `manage_options` capability can manage the account pool.

== Changelog ==

= 1.0.0 =

* Initial release.
* OAuth PKCE flow for Claude Max authentication.
* Account pool with automatic rotation and rate-limit cooldowns.
* Auto-refresh of expired tokens.
* React-based Connectors page UI.
* Health check endpoint for monitoring account status.
* Full Anthropic Messages API support (text, images, documents, tools, web search).
