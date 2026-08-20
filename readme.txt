=== AI Provider for Anthropic Max ===
Contributors: superdav42
Tags: ai, anthropic, claude, openai, oauth
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.3.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Multi-provider OAuth account pool for the WordPress AI Client. Anthropic Max, OpenAI ChatGPT/Codex, and Google AI Pro.

== Description ==

This plugin extends the WordPress AI Client with **OAuth-based account pools** for paid AI subscriptions, so you can use your existing **Anthropic Claude Max**, **OpenAI ChatGPT/Codex**, or **Google AI Pro** plan from WordPress without per-token API billing.

**Supported providers:**

* **Anthropic Max** -- OAuth (PKCE) flow with `claude.ai`. Pool with rotation, refresh, and cooldowns. Wired into the AI Client SDK as `ultimate-ai-connector-anthropic-max` (full text/image/tool support).
* **OpenAI ChatGPT / Codex** -- OAuth flow with `auth.openai.com`. Paste the auth code from the localhost callback. Wired into the AI Client SDK as `ultimate-ai-connector-chatgpt-codex` (text generation + tool use).
* **Google AI Pro / Ultra / Workspace Gemini** -- OAuth PKCE flow with `accounts.google.com`, using the Gemini CLI's published OAuth client. After signing in, paste the code shown on Google's `codeassist.google.com` landing page. Wired into the AI Client SDK as `ultimate-ai-connector-google-ai-pro` (text generation; Imagen image generation is out of scope for the OAuth bearer flow and remains in the canonical `ai-provider-for-google` plugin).

**Pool features (all providers):**

* **Pool rotation** -- Multiple accounts per provider with automatic failover.
* **Auto-refresh** -- Expired tokens are refreshed automatically when refresh tokens are available.
* **Rate-limit cooldowns** -- Rate-limited accounts rotate out and re-enter the pool after a configurable cooldown.
* **Health checks** -- Per-provider `/health` endpoint reports account status.
* **Connectors page** -- Manage all three providers from `Settings > Connectors` with one card each.

**Requirements by WordPress version:**

* **WordPress 7.0+** -- The AI Client SDK is included in core. This plugin works on its own.
* **WordPress 6.9** -- Requires the [AI Experiments](https://wordpress.org/plugins/ai/) plugin for the SDK.

**How it works:**

1. Install and activate the plugin.
2. Go to **Settings > Connectors**. You will see three cards: Anthropic Max, OpenAI ChatGPT/Codex, Google AI Pro.
3. Click **Set up** on any card and authorize in a browser, then paste the returned code (OpenAI uses an in-page device code flow; Google shows the code on `codeassist.google.com`).
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

= 1.3.1 =
Version 1.3.1 - Released on 2026-08-19
- Improved: WordPress compatibility metadata now reflects testing through WordPress 7.1.

= 1.3.0 =
Version 1.3.0 - Released on 2026-06-03
- New: Expanded the provider pool experience so OpenAI ChatGPT/Codex and Google AI Pro join the existing Anthropic Max OAuth pool workflow.
- New: Added full tool-support behavior for ChatGPT Codex in connector-supported operations.
- New: Removed the Cursor Pro provider and its setup pathways so only supported OAuth providers remain.
- Fix: Improved DELETE/refresh connector endpoints to validate emails and payloads more safely, including better save/remove failure handling.
- Fix: Added a manual OAuth fallback path for sandboxed setups so users can continue connecting accounts.
- Fix: Repaired Google AI Pro OAuth behavior and wired the provider integration for reliable SDK use.
- Fix: Fixed Anthropic tool-use payload handling for empty array and round-trip thinking signatures.
- Improved: Added tag-triggered release and deployment workflow, plus build/packaging tracking updates for release publishing.
- Improved: Synced release tooling metadata and updated static-analysis and planning config files used during CI and release prep.

= Unreleased =

* **FIX**: Google AI Pro OAuth flow was completely broken in 1.2.0. The configured OAuth client id (`681255809395-oo8ft6t5t0rnmhfqgpnkqtev5b9a2i5j.apps.googleusercontent.com`) did not exist — Google returned `invalid_client: The OAuth client was not found.` Replaced with the live Gemini CLI Code Assist client id (`oo8ft2oprdrnp9e3aqf6av3hmdib135j`), added the matching `client_secret` (public per Google's installed-app spec — embedded in the open-source Gemini CLI), swapped the discontinued OOB redirect (`urn:ietf:wg:oauth:2.0:oob`, deprecated by Google 2022-10-03) for the Gemini CLI's `https://codeassist.google.com/authcode` paste-code landing page, switched the scopes to the exact set used by Gemini CLI (`cloud-platform`, `userinfo.email`, `userinfo.profile`), switched the token endpoint content type to `application/x-www-form-urlencoded` (Google rejects JSON bodies), and replaced the API-key health-check endpoint with `https://www.googleapis.com/oauth2/v2/userinfo` (works with OAuth bearer tokens).
* **NEW**: Google AI Pro is now wired into the AI Client SDK as `ultimate-ai-connector-google-ai-pro` (text generation, function calling, web search). The new `GoogleAiProProvider`, `GoogleOAuthRequestAuthentication`, `GoogleAiProModelMetadataDirectory`, and `GoogleAiProTextGenerationModel` classes mirror the patterns in the canonical WordPress.org `ai-provider-for-google` plugin so request/response shapes are identical; only the auth header swaps from `X-Goog-Api-Key` to `Authorization: Bearer`. Imagen image generation is intentionally out of scope here — OAuth bearer with the `cloud-platform` scope does not currently expose Imagen through `generativelanguage.googleapis.com`, so the canonical `ai-provider-for-google` plugin remains the recommended path for API-key-billed Imagen usage.
* **NEW**: `ProviderConfig::$clientSecret` field for OAuth client types that require a secret on token exchange (Google). Anthropic and OpenAI use PKCE-only and leave it empty. Wired through `ProviderPool::exchangeCode()` and `refreshTokens()` so the secret is sent when present.
* **REMOVED**: Cursor Pro provider. The `cursor` provider entry, its Connectors card, the `manual-token` add form, and the `/anthropic-max-pool/v1/{provider}/manual` REST route have all been removed. Anthropic Max, OpenAI ChatGPT/Codex, and Google AI Pro remain. If any sites stored data under the `anthropic_max_oauth_pool_cursor` option key it is no longer read and can be deleted manually.

= 1.2.0 =

* **NEW**: multi-provider OAuth pool support. Adds OpenAI ChatGPT/Codex and Google AI Pro alongside Anthropic Max.
* Transient success/error feedback (add account, refresh, remove, health check) is dispatched on the `@wordpress/notices` store and rendered by the global SnackbarList outside the card subtree, matching WP-core's connector pattern. Inline error display uses a plain banner with `role="alert"` instead of `Notice`. Persistent body `Notice` components carry explicit `politeness` and `spokenMessage` props. This avoids a deps-array race inside `@wordpress/components` `useSpokenMessage` that surfaced as "Cannot read properties of undefined (reading 'length')" during the device-code success-flip on add-account.
* **NEW**: per-provider REST routes under `/anthropic-max-pool/v1/{provider}/` (`accounts`, `authorize`, `exchange`, `accounts/remove`, `accounts/refresh`, `health`) plus a top-level `/providers` index endpoint. Legacy `/anthropic-max-pool/v1/accounts` (and friends) remain Anthropic-only and unchanged for backward compatibility.
* **NEW**: `src/OAuthPool/ProviderConfig.php` declares per-provider OAuth parameters (client id, endpoints, scopes, redirect, user-agent, health-check URL); `ProviderPool.php` implements the generic per-provider pool (load/save/list/add/remove/refresh/rotate/health); `PoolRegistry.php` is the memoised pool factory. `PoolManager` is now a thin facade over the registry for the Anthropic pool, preserving every public method and constant used by 1.0/1.1 callers.
* **NEW**: Connectors page now renders three cards (Anthropic Max, OpenAI ChatGPT/Codex, Google AI Pro) with provider-specific badges and OAuth forms.
* Stored option keys for new providers (`anthropic_max_oauth_pool_openai`, `..._google`) are namespaced; the existing `anthropic_max_oauth_pool` key for Anthropic is unchanged, so 1.1.0 installs upgrade in place with zero migration.
* AI Client SDK provider classes for Google are out of scope for this release; pool storage and rotation are fully wired, and SDK integration ships in a follow-up.

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
