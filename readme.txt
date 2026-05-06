=== AI Provider for Anthropic Max ===
Contributors: superdav42
Tags: ai, anthropic, claude, openai, oauth
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-provider OAuth account pool for the WordPress AI Client. Anthropic Max, OpenAI ChatGPT/Codex, Cursor Pro, and Google AI Pro.

== Description ==

This plugin extends the WordPress AI Client with **OAuth-based account pools** for paid AI subscriptions, so you can use your existing **Anthropic Claude Max**, **OpenAI ChatGPT/Codex**, **Cursor Pro**, or **Google AI Pro** plan from WordPress without per-token API billing.

**Supported providers:**

* **Anthropic Max** -- OAuth (PKCE) flow with `claude.ai`. Pool with rotation, refresh, and cooldowns. Wired into the AI Client SDK as `ultimate-ai-connector-anthropic-max` (full text/image/tool support).
* **OpenAI ChatGPT / Codex** -- OAuth flow with `auth.openai.com`. Paste the auth code from the localhost callback. Pool storage and rotation are implemented; SDK integration ships in a follow-up.
* **Cursor Pro** -- Manual token paste (Cursor has no public OAuth client). Tokens are stored as a pool with email auto-derived from the JWT `sub` claim.
* **Google AI Pro / Ultra / Workspace Gemini** -- OAuth OOB flow with `accounts.google.com`. Paste the code Google shows after authorization.

**Pool features (all providers):**

* **Pool rotation** -- Multiple accounts per provider with automatic failover.
* **Auto-refresh** -- Expired tokens are refreshed automatically when refresh tokens are available.
* **Rate-limit cooldowns** -- Rate-limited accounts rotate out and re-enter the pool after a configurable cooldown.
* **Health checks** -- Per-provider `/health` endpoint reports account status.
* **Connectors page** -- Manage all four providers from `Settings > Connectors` with one card each.

**Requirements by WordPress version:**

* **WordPress 7.0+** -- The AI Client SDK is included in core. This plugin works on its own.
* **WordPress 6.9** -- Requires the [AI Experiments](https://wordpress.org/plugins/ai/) plugin for the SDK.

**How it works:**

1. Install and activate the plugin.
2. Go to **Settings > Connectors**. You will see four cards: Anthropic Max, OpenAI ChatGPT/Codex, Cursor Pro, Google AI Pro.
3. Click **Set up** on any card and follow the provider-specific flow:
   * Anthropic / OpenAI / Google: authorize in a browser, paste the returned code.
   * Cursor: paste your access token (and optional refresh token) from the Cursor IDE.
4. Add as many accounts per provider as you like for pool rotation.

The plugin registers Anthropic Max as a separate provider (`ultimate-ai-connector-anthropic-max`) in the AI Client SDK and coexists with the standard API-key-based Anthropic provider.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-anthropic-max/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. **WordPress 6.9 only:** Ensure the [AI Experiments](https://wordpress.org/plugins/ai/) plugin is active.
4. Go to **Settings > Connectors** and set up your Claude Max account(s).

== Frequently Asked Questions ==

= What is a Claude Max subscription? =

Claude Max is Anthropic's premium subscription plan that provides access to Claude models without per-token API billing. This plugin lets you use those subscription tokens within WordPress.

= Can I use this alongside the standard Anthropic provider? =

Yes. This plugin registers as "Anthropic Max" (`ultimate-ai-connector-anthropic-max`), separate from the standard "Anthropic" (`anthropic`) provider. Both can be active simultaneously.

= Why add multiple accounts? =

Multiple accounts provide failover. If one account hits a rate limit, the plugin automatically switches to the next available account.

= How are tokens stored? =

OAuth tokens are stored in the WordPress options table. Only site administrators with `manage_options` capability can manage the account pool.

== Changelog ==

= 1.2.0 =

* **NEW**: multi-provider OAuth pool support. Adds OpenAI ChatGPT/Codex, Cursor Pro, and Google AI Pro alongside Anthropic Max.
* **NEW**: per-provider REST routes under `/anthropic-max-pool/v1/{provider}/` (`accounts`, `authorize`, `exchange`, `manual`, `accounts/remove`, `accounts/refresh`, `health`) plus a top-level `/providers` index endpoint. Legacy `/anthropic-max-pool/v1/accounts` (and friends) remain Anthropic-only and unchanged for backward compatibility.
* **NEW**: `src/OAuthPool/ProviderConfig.php` declares per-provider OAuth parameters (client id, endpoints, scopes, redirect, user-agent, health-check URL); `ProviderPool.php` implements the generic per-provider pool (load/save/list/add/remove/refresh/rotate/health); `PoolRegistry.php` is the memoised pool factory. `PoolManager` is now a thin facade over the registry for the Anthropic pool, preserving every public method and constant used by 1.0/1.1 callers.
* **NEW**: Connectors page now renders four cards (Anthropic Max, OpenAI ChatGPT/Codex, Cursor Pro, Google AI Pro) with provider-specific badges and forms — `oauth-paste` for the three OAuth providers, `manual-token` for Cursor.
* Stored option keys for new providers (`anthropic_max_oauth_pool_openai`, `..._cursor`, `..._google`) are namespaced; the existing `anthropic_max_oauth_pool` key for Anthropic is unchanged, so 1.1.0 installs upgrade in place with zero migration.
* AI Client SDK provider classes for OpenAI/Cursor/Google are out of scope for this release; pool storage and rotation are fully wired, and SDK integration ships in a follow-up.

= 1.1.0 =

* **BREAKING**: renamed the AI Client provider id from `anthropic-max` to `ultimate-ai-connector-anthropic-max` for consistency with the sister plugins (`ultimate-ai-connector-webllm`, `ultimate-ai-connector-compatible-endpoints`) and to claim the namespace properly. Code that called `AiClient::defaultRegistry()->getProvider('anthropic-max')` must update the id. Stored OAuth tokens, REST endpoints, and option keys are unchanged so existing setups continue to work.
* Improved: the JS-side `registerConnector()` slug now matches the PHP provider id, so the WP core Connectors page renders one card instead of two (a previously-hidden duplicate auto-registered card with the generic API-key form is now suppressed).
* Fix: re-assert our `registerConnector()` call across multiple ticks (microtask + 0/50/250/1000 ms) so the WP core `registerDefaultConnectors()` auto-register can't clobber the custom card with the generic API-key UI. Necessary because matching the slug exposes us to the same race the other Ultimate-Multisite connector plugins hit. The proper upstream fix is in https://github.com/WordPress/gutenberg/pull/77116 — once that ships in a Gutenberg release, this workaround can be removed.
* Fix: use `logo` prop instead of `icon` for ConnectorItem and add the MAX text icon to the registration config so the connector page renders the icon correctly.

= 1.0.0 =

* Initial release.
* OAuth PKCE flow for Claude Max authentication.
* Account pool with automatic rotation and rate-limit cooldowns.
* Auto-refresh of expired tokens.
* React-based Connectors page UI.
* Health check endpoint for monitoring account status.
* Full Anthropic Messages API support (text, images, documents, tools, web search).
