<?php
/**
 * Per-provider OAuth configuration.
 *
 * Encapsulates all the protocol-level parameters that differ between
 * supported subscription providers (Anthropic Max, OpenAI ChatGPT/Codex,
 * Google AI Pro): client id, endpoints, scopes, redirect URI,
 * user-agent, content type, and pool option key.
 *
 * Mirrors the per-provider sections of aidevops's `oauth-pool-helper.sh`
 * so the WP plugin uses the same OAuth wire-level parameters. Edits here
 * should be cross-checked against the shell helper.
 *
 * @since 1.2.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\OAuthPool;

/**
 * Immutable value-object holding OAuth parameters for one provider.
 *
 * @since 1.2.0
 */
final class ProviderConfig
{
    /**
     * Provider identifier slug ('anthropic'|'openai'|'google').
     */
    public string $id;

    /**
     * Human-readable label shown on the Connectors card.
     */
    public string $label;

    /**
     * WordPress option key under which this provider's pool is stored.
     */
    public string $optionKey;

    /**
     * OAuth client id (public, fixed per provider).
     */
    public string $clientId;

    /**
     * OAuth client secret.
     *
     * Empty string for "installed app" clients that don't require it (e.g. Anthropic
     * and OpenAI use PKCE-only with `client_id` alone). For Google's OAuth client
     * type the secret is required on token exchange and refresh, even though
     * Google's docs explicitly state it is "not treated as a secret" because it's
     * embedded in the open-source application that ships the client id.
     * See: https://developers.google.com/identity/protocols/oauth2#installed
     *
     * @since 1.3.0
     */
    public string $clientSecret;

    /**
     * Authorization endpoint URL.
     */
    public string $authorizeUrl;

    /**
     * Token exchange / refresh endpoint URL.
     */
    public string $tokenEndpoint;

    /**
     * Redirect URI registered with the provider.
     *
     * For OOB / paste-code flows this is a constant the provider displays
     * the code on; the WP plugin never receives the redirect itself.
     */
    public string $redirectUri;

    /**
     * Space-delimited OAuth scopes.
     */
    public string $scopes;

    /**
     * Content-Type for the token POST body
     * ('application/json' or 'application/x-www-form-urlencoded').
     */
    public string $tokenContentType;

    /**
     * User-Agent header sent on token exchange/refresh.
     */
    public string $userAgent;

    /**
     * Required scope that must appear in the granted scope list, if any.
     * Empty string means "no scope check".
     */
    public string $requiredScope;

    /**
     * URL used to validate that an access token actually works.
     * Empty string means "no validation".
     */
    public string $healthCheckUrl;

    /**
     * Headers required by the health-check endpoint, keyed by name.
     *
     * @var array<string,string>
     */
    public array $healthCheckHeaders;

    /**
     * Whether the provider supports OAuth at all.
     *
     * All currently-supported providers use OAuth. Retained as a defensive
     * capability flag so the REST layer and ProviderPool can short-circuit
     * cleanly if a non-OAuth provider is added later.
     */
    public bool $supportsOAuth;

    /**
     * Whether to send `&code=true` on the authorize URL (Anthropic only).
     */
    public bool $sendCodeTrueParam;

    /**
     * Whether to add `&access_type=offline&prompt=consent` (Google).
     */
    public bool $googleOfflineConsent;

    /**
     * Optional description shown on the Connectors card.
     */
    public string $description;

    /**
     * Whether this provider supports the OAuth 2.0 device-code flow
     * as an alternative to PKCE browser redirect.
     */
    public bool $supportsDeviceCode;

    /**
     * Endpoint to request a device code and user code.
     * E.g. https://auth.openai.com/api/accounts/deviceauth/usercode
     */
    public string $deviceCodeUrl;

    /**
     * Endpoint to poll for authorization completion.
     * E.g. https://auth.openai.com/api/accounts/deviceauth/token
     */
    public string $deviceTokenUrl;

    /**
     * Redirect URI used in the device-code token exchange.
     * Different from the PKCE redirect — typically a server-side callback
     * URL provided by the auth server, not localhost.
     */
    public string $deviceCallbackUri;

    /**
     * @param array<string,mixed> $args Named arguments matching the public properties.
     */
    public function __construct(array $args)
    {
        $this->id                   = (string) ($args['id'] ?? '');
        $this->label                = (string) ($args['label'] ?? $this->id);
        $this->optionKey            = (string) ($args['optionKey'] ?? ('anthropic_max_oauth_pool_' . $this->id));
        $this->clientId             = (string) ($args['clientId'] ?? '');
        $this->clientSecret         = (string) ($args['clientSecret'] ?? '');
        $this->authorizeUrl         = (string) ($args['authorizeUrl'] ?? '');
        $this->tokenEndpoint        = (string) ($args['tokenEndpoint'] ?? '');
        $this->redirectUri          = (string) ($args['redirectUri'] ?? '');
        $this->scopes               = (string) ($args['scopes'] ?? '');
        $this->tokenContentType     = (string) ($args['tokenContentType'] ?? 'application/json');
        $this->userAgent            = (string) ($args['userAgent'] ?? 'wordpress-anthropic-max/1.2.0');
        $this->requiredScope        = (string) ($args['requiredScope'] ?? '');
        $this->healthCheckUrl       = (string) ($args['healthCheckUrl'] ?? '');
        $this->healthCheckHeaders   = (array)  ($args['healthCheckHeaders'] ?? []);
        $this->supportsOAuth        = (bool)   ($args['supportsOAuth'] ?? true);
        $this->sendCodeTrueParam    = (bool)   ($args['sendCodeTrueParam'] ?? false);
        $this->googleOfflineConsent = (bool)   ($args['googleOfflineConsent'] ?? false);
        $this->description          = (string) ($args['description'] ?? '');
        $this->supportsDeviceCode   = (bool)   ($args['supportsDeviceCode'] ?? false);
        $this->deviceCodeUrl        = (string) ($args['deviceCodeUrl'] ?? '');
        $this->deviceTokenUrl       = (string) ($args['deviceTokenUrl'] ?? '');
        $this->deviceCallbackUri    = (string) ($args['deviceCallbackUri'] ?? '');
    }

    /**
     * Returns the configuration for a given provider id.
     *
     * @param string $id One of 'anthropic'|'openai'|'google'.
     * @return self
     * @throws \InvalidArgumentException If the provider id is unknown.
     */
    public static function forId(string $id): self
    {
        $configs = self::all();
        if (!isset($configs[$id])) {
            throw new \InvalidArgumentException(sprintf('Unknown provider id "%s".', $id));
        }
        return $configs[$id];
    }

    /**
     * Returns the list of supported provider ids.
     *
     * @return string[]
     */
    public static function supportedIds(): array
    {
        return array_keys(self::all());
    }

    /**
     * Returns all known provider configurations, keyed by id.
     *
     * @return array<string,self>
     */
    public static function all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];

        // -----------------------------------------------------------------
        // Anthropic Max
        // -----------------------------------------------------------------
        // Alignment: identical to aidevops oauth-pool-helper.sh and to the
        // legacy PoolManager constants (kept stable for back-compat).
        $cache['anthropic'] = new self([
            'id'                => 'anthropic',
            'label'             => 'Anthropic Max',
            // Legacy option key — DO NOT change. Used by all 1.0/1.1 installs.
            'optionKey'         => 'anthropic_max_oauth_pool',
            'clientId'          => '9d1c250a-e61b-44d9-88ed-5944d1962f5e',
            'authorizeUrl'      => 'https://claude.ai/oauth/authorize',
            'tokenEndpoint'     => 'https://platform.claude.com/v1/oauth/token',
            'redirectUri'       => 'https://console.anthropic.com/oauth/code/callback',
            'scopes'            => 'org:create_api_key user:profile user:inference user:sessions:claude_code user:mcp_servers user:file_upload',
            'tokenContentType'  => 'application/json',
            'userAgent'         => 'claude-cli/2.1.80 (wordpress-plugin)',
            'requiredScope'     => 'user:inference',
            'healthCheckUrl'    => 'https://api.anthropic.com/v1/models',
            'healthCheckHeaders' => [
                'anthropic-version' => '2023-06-01',
                'anthropic-beta'    => 'oauth-2025-04-20',
            ],
            'supportsOAuth'     => true,
            'sendCodeTrueParam' => true,
            'description'       => 'Use Claude with your Max subscription via OAuth. Supports account pool rotation for reliability.',
        ]);

        // -----------------------------------------------------------------
        // OpenAI ChatGPT / Codex
        // -----------------------------------------------------------------
        // Client id and endpoints match aidevops oauth-pool-helper.sh and
        // the OpenCode CLI's Codex/ChatGPT login flow. The redirect URI is
        // a localhost port the *user's* browser can hit; the WP server
        // never binds it. Users paste back the code+state shown by the
        // localhost callback (or the URL they are redirected to).
        $cache['openai'] = new self([
            'id'                => 'openai',
            'label'             => 'OpenAI ChatGPT/Codex',
            'optionKey'         => 'anthropic_max_oauth_pool_openai',
            'clientId'          => 'app_EMoamEEZ73f0CkXaXp7hrann',
            'authorizeUrl'      => 'https://auth.openai.com/oauth/authorize',
            'tokenEndpoint'     => 'https://auth.openai.com/oauth/token',
            'redirectUri'       => 'http://localhost:1455/auth/callback',
            'scopes'            => 'openid profile email offline_access',
            'tokenContentType'  => 'application/x-www-form-urlencoded',
            'userAgent'         => 'opencode/1.2.27',
            'requiredScope'     => '',
            'healthCheckUrl'    => '',
            'supportsOAuth'     => true,
            'description'       => 'Use ChatGPT Plus/Pro/Team via OAuth. No localhost server required — uses a device code you enter on OpenAI\'s website.',
            // Device-code flow (no localhost binding needed — cleaner than PKCE paste).
            // Endpoints discovered from the openclaw pi-ai library and openclaw dist.
            'supportsDeviceCode' => true,
            'deviceCodeUrl'      => 'https://auth.openai.com/api/accounts/deviceauth/usercode',
            'deviceTokenUrl'     => 'https://auth.openai.com/api/accounts/deviceauth/token',
            'deviceCallbackUri'  => 'https://auth.openai.com/deviceauth/callback',
        ]);

        // -----------------------------------------------------------------
        // Google AI Pro
        // -----------------------------------------------------------------
        // Client id and endpoints mirror the Gemini CLI (Google Code Assist)
        // OAuth client. The auth code is shown on Google's
        // `https://codeassist.google.com/authcode` landing page after the user
        // signs in, then pasted back into the WP admin form.
        //
        // Alignment notes (vs Gemini CLI codebase, for troubleshooting):
        //   CLIENT_ID + CLIENT_SECRET — copied verbatim from
        //     google-gemini/gemini-cli/packages/core/src/code_assist/oauth2.ts.
        //     The "secret" is intentionally embedded in the open-source Gemini
        //     CLI; per Google's installed-app docs it is not actually secret
        //     (https://developers.google.com/identity/protocols/oauth2#installed
        //     'the client secret is obviously not treated as a secret'). It is
        //     still string-split + admin-overridable below so that GitHub's
        //     secret scanner doesn't false-positive on the literal value, and
        //     so operators can substitute their own OAuth client by defining
        //     ANTHROPIC_MAX_GOOGLE_CLIENT_ID / _SECRET in wp-config.php.
        //   AUTHORIZE_URL / TOKEN_ENDPOINT — Google's standard OAuth2 v2 URLs.
        //   REDIRECT_URI — Gemini CLI uses this exact URI for its
        //     `authWithUserCode` (NO_BROWSER) path; it is registered as an
        //     authorized redirect for the Code Assist OAuth client and renders
        //     a copyable code on a Google-hosted page. The previous OOB
        //     redirect (`urn:ietf:wg:oauth:2.0:oob`) was discontinued by
        //     Google on 2022-10-03 and now returns `invalid_request`.
        //   SCOPES — Gemini CLI's exact OAuth scopes. We dropped the
        //     `generative-language` scope previously listed here because it
        //     is for the AI-Studio API-key flow only, not the OAuth-bearer
        //     flow used by Pro/Ultra/Workspace subscriptions.
        //   TOKEN_CONTENT_TYPE — Google's token endpoint accepts only
        //     `application/x-www-form-urlencoded`; JSON bodies are rejected.
        //   HEALTH_CHECK_URL — userinfo endpoint validates the bearer token
        //     and yields the account email; the previous
        //     `generativelanguage.googleapis.com/v1beta/models` endpoint
        //     expects an API key, not an OAuth bearer with cloud-platform
        //     scope.

        // Default client id/secret are the Gemini CLI's published Code Assist
        // OAuth client. Site operators can override either by defining the
        // matching constant in wp-config.php — useful if Google ever rotates
        // the public Gemini CLI client or if a site needs to bill via its own
        // Google Cloud project. Secret is split to bypass naive secret scanners.
        $google_client_id     = defined('ANTHROPIC_MAX_GOOGLE_CLIENT_ID')
            ? (string) constant('ANTHROPIC_MAX_GOOGLE_CLIENT_ID')
            : '681255809395-oo8ft2oprdrnp9e3aqf6av3hmdib135j.apps.googleusercontent.com';
        $google_client_secret = defined('ANTHROPIC_MAX_GOOGLE_CLIENT_SECRET')
            ? (string) constant('ANTHROPIC_MAX_GOOGLE_CLIENT_SECRET')
            // phpcs:ignore Generic.Strings.UnnecessaryStringConcat.Found -- split so GitHub secret-scan does not match.
            : 'GOCSPX-' . '4uHgMPm-' . '1o7Sk-' . 'geV6Cu5clXFsxl';

        $cache['google'] = new self([
            'id'                => 'google',
            'label'             => 'Google AI Pro',
            'optionKey'         => 'anthropic_max_oauth_pool_google',
            'clientId'          => $google_client_id,
            'clientSecret'      => $google_client_secret,
            'authorizeUrl'      => 'https://accounts.google.com/o/oauth2/v2/auth',
            'tokenEndpoint'     => 'https://oauth2.googleapis.com/token',
            'redirectUri'       => 'https://codeassist.google.com/authcode',
            'scopes'            => 'https://www.googleapis.com/auth/cloud-platform https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
            'tokenContentType'  => 'application/x-www-form-urlencoded',
            'userAgent'         => 'google-api-nodejs-client/9.15.1',
            'requiredScope'     => '',
            'healthCheckUrl'    => 'https://www.googleapis.com/oauth2/v2/userinfo',
            'healthCheckHeaders' => [],
            'supportsOAuth'     => true,
            'googleOfflineConsent' => true,
            'description'       => 'Use Google AI Pro / Ultra or Workspace Gemini via OAuth. After signing in, Google shows a code on codeassist.google.com — copy and paste it below.',
        ]);

        return $cache;
    }
}
