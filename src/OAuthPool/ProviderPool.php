<?php
/**
 * Generic per-provider OAuth account pool.
 *
 * Stores, rotates, and manages a pool of OAuth accounts for one provider
 * (Anthropic Max, OpenAI ChatGPT/Codex, Cursor Pro, or Google AI Pro).
 * Each provider has its own pool, isolated by a per-provider option key.
 *
 * Mirrors the design of aidevops's `oauth-pool-helper.sh` but implemented
 * in PHP for WordPress. All wire-level OAuth parameters live in the
 * associated `ProviderConfig`; this class contains only the provider-
 * agnostic pool operations.
 *
 * @since 1.2.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\OAuthPool;

/**
 * Manages the pool of OAuth accounts for a single provider.
 *
 * @since 1.2.0
 */
class ProviderPool
{
    /**
     * Transient key prefix for PKCE verifiers (per-provider scoped).
     */
    public const PKCE_TRANSIENT_PREFIX = 'anthropic_max_pkce_';

    /**
     * Default cooldown duration for rate-limited accounts (5 minutes).
     */
    public const DEFAULT_COOLDOWN_MS = 300000;

    /**
     * Return statuses for removeAccount(). See PoolManager::REMOVE_*.
     */
    public const REMOVE_NOT_FOUND  = 'not_found';
    public const REMOVE_OK         = 'ok';
    public const REMOVE_SAVE_ERROR = 'save_error';

    /**
     * Provider configuration.
     *
     * @var ProviderConfig
     */
    protected ProviderConfig $config;

    /**
     * Detailed error description from the most recent failed operation,
     * intended for the REST layer to surface to the user.
     *
     * @var string|null
     */
    protected ?string $lastError = null;

    /**
     * @param ProviderConfig $config
     */
    public function __construct(ProviderConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Returns (and clears) the detailed error from the most recent operation
     * that returned null.
     *
     * @return string|null
     */
    public function getLastError(): ?string
    {
        $error           = $this->lastError;
        $this->lastError = null;
        return $error;
    }

    /**
     * Returns the underlying provider configuration.
     *
     * @return ProviderConfig
     */
    public function getConfig(): ProviderConfig
    {
        return $this->config;
    }

    /**
     * Loads the pool from the WordPress options table.
     *
     * @return array{accounts: array<int,array<string,mixed>>}
     */
    public function loadPool(): array
    {
        $pool = get_option($this->config->optionKey, []);
        if (!is_array($pool) || !isset($pool['accounts'])) {
            return ['accounts' => []];
        }
        return $pool;
    }

    /**
     * Saves the pool to the WordPress options table.
     *
     * @param array<string,mixed> $pool
     * @return bool
     */
    public function savePool(array $pool): bool
    {
        return update_option($this->config->optionKey, $pool, false);
    }

    /**
     * Returns the list of accounts (without exposing tokens).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAccounts(): array
    {
        $pool     = $this->loadPool();
        $accounts = $pool['accounts'] ?? [];
        $now_ms   = $this->nowMs();

        return array_map(function (array $account) use ($now_ms): array {
            $expires_in_ms = ($account['expires'] ?? 0) - $now_ms;

            return [
                'email'         => $account['email'] ?? 'unknown',
                'status'        => $account['status'] ?? 'unknown',
                'added'         => $account['added'] ?? '',
                'lastUsed'      => $account['lastUsed'] ?? '',
                'tokenExpired'  => $expires_in_ms <= 0,
                'expiresIn'     => max(0, intdiv($expires_in_ms, 1000)),
                'hasRefresh'    => !empty($account['refresh']),
                'cooldownUntil' => $account['cooldownUntil'] ?? null,
                'accountId'     => $account['accountId'] ?? null,
                'validity'      => null,
            ];
        }, $accounts);
    }

    /**
     * Returns the best available access token and its account email.
     *
     * @return array{token: string, email: string}|null
     */
    public function getActiveTokenWithEmail(): ?array
    {
        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();

        $changed = $this->clearExpiredCooldowns($pool, $now_ms);
        if ($changed) {
            $this->savePool($pool);
        }

        $best       = null;
        $best_index = -1;

        foreach ($pool['accounts'] as $index => $account) {
            $status = $account['status'] ?? 'idle';
            if ($status === 'rate-limited') {
                continue;
            }
            if (empty($account['access'])) {
                continue;
            }
            if ($best === null || ($account['lastUsed'] ?? '') < ($best['lastUsed'] ?? '')) {
                $best       = $account;
                $best_index = $index;
            }
        }

        if ($best === null) {
            return null;
        }

        $expires = $best['expires'] ?? 0;
        if ($expires > 0 && $expires <= $now_ms && !empty($best['refresh'])) {
            $refreshed = $this->refreshTokens($best['refresh']);
            if ($refreshed !== null) {
                $pool['accounts'][$best_index]['access']   = $refreshed['access_token'];
                $pool['accounts'][$best_index]['expires']  = $now_ms + ($refreshed['expires_in'] * 1000);
                $pool['accounts'][$best_index]['status']   = 'active';
                $pool['accounts'][$best_index]['lastUsed'] = gmdate('Y-m-d\TH:i:s\Z');
                if (!empty($refreshed['refresh_token'])) {
                    $pool['accounts'][$best_index]['refresh'] = $refreshed['refresh_token'];
                }
                $this->savePool($pool);
                return ['token' => $refreshed['access_token'], 'email' => $best['email'] ?? ''];
            }

            $pool['accounts'][$best_index]['status'] = 'refresh-failed';
            $this->savePool($pool);
            return null;
        }

        $pool['accounts'][$best_index]['status']   = 'active';
        $pool['accounts'][$best_index]['lastUsed'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->savePool($pool);

        return ['token' => $best['access'], 'email' => $best['email'] ?? ''];
    }

    /**
     * Returns the best available access token (no email).
     *
     * @return string|null
     */
    public function getActiveToken(): ?string
    {
        $result = $this->getActiveTokenWithEmail();
        return $result === null ? null : $result['token'];
    }

    /**
     * Returns the best available access token plus the email and the
     * provider-specific account id (e.g. OpenAI `chatgpt_account_id`).
     *
     * Same selection/refresh semantics as {@see self::getActiveTokenWithEmail()};
     * extends the return shape with `accountId` so consumers that need an
     * extra request header (Codex backend, Cursor, etc.) don't have to make
     * a second pool query or re-decode the JWT.
     *
     * @return array{token: string, email: string, accountId: string|null}|null
     */
    public function getActiveTokenWithMeta(): ?array
    {
        $result = $this->getActiveTokenWithEmail();
        if ($result === null) {
            return null;
        }

        // Re-read the freshly-updated pool to pick up the matching accountId
        // (the token-with-email path already persisted any token refresh).
        $accountId = null;
        $pool      = $this->loadPool();
        foreach ($pool['accounts'] as $account) {
            if (($account['email'] ?? '') === $result['email']) {
                $candidate = $account['accountId'] ?? null;
                if ($candidate !== null && $candidate !== '') {
                    $accountId = (string) $candidate;
                }
                break;
            }
        }

        return [
            'token'     => $result['token'],
            'email'     => $result['email'],
            'accountId' => $accountId,
        ];
    }

    /**
     * Adds or updates an account in the pool.
     *
     * @param string      $email
     * @param string      $access_token
     * @param string      $refresh_token
     * @param int         $expires_in   Seconds until expiry.
     * @param string|null $account_id
     * @return int Total accounts after the operation.
     */
    public function addAccount(
        string $email,
        string $access_token,
        string $refresh_token,
        int $expires_in,
        ?string $account_id = null
    ): int {
        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();
        $now    = gmdate('Y-m-d\TH:i:s\Z');

        $expires_ms = $now_ms + ($expires_in * 1000);

        $found = false;
        foreach ($pool['accounts'] as &$account) {
            if (($account['email'] ?? '') === $email) {
                $account['access']        = $access_token;
                $account['refresh']       = $refresh_token;
                $account['expires']       = $expires_ms;
                $account['lastUsed']      = $now;
                $account['status']        = 'active';
                $account['cooldownUntil'] = null;
                if ($account_id !== null) {
                    $account['accountId'] = $account_id;
                }
                $found = true;
                break;
            }
        }
        unset($account);

        if (!$found) {
            $entry = [
                'email'         => $email,
                'access'        => $access_token,
                'refresh'       => $refresh_token,
                'expires'       => $expires_ms,
                'added'         => $now,
                'lastUsed'      => $now,
                'status'        => 'active',
                'cooldownUntil' => null,
            ];
            if ($account_id !== null) {
                $entry['accountId'] = $account_id;
            }
            $pool['accounts'][] = $entry;
        }

        $this->savePool($pool);
        return count($pool['accounts']);
    }

    /**
     * Removes an account by email. Returns one of REMOVE_*.
     *
     * @param string $email
     * @return string
     */
    public function removeAccount(string $email): string
    {
        $pool     = $this->loadPool();
        $original = count($pool['accounts']);

        $pool['accounts'] = array_values(array_filter(
            $pool['accounts'],
            static function (array $account) use ($email): bool {
                return ($account['email'] ?? '') !== $email;
            }
        ));

        if (count($pool['accounts']) === $original) {
            return self::REMOVE_NOT_FOUND;
        }

        return $this->savePool($pool) ? self::REMOVE_OK : self::REMOVE_SAVE_ERROR;
    }

    /**
     * Marks an account as rate-limited.
     *
     * @param string   $email
     * @param int|null $cooldown_ms      Default cooldown if no Retry-After.
     * @param int|null $retry_after_secs Retry-After value in seconds (overrides cooldown_ms).
     * @return bool Whether the account was found.
     */
    public function markRateLimited(string $email, ?int $cooldown_ms = null, ?int $retry_after_secs = null): bool
    {
        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();

        if ($retry_after_secs !== null) {
            $cooldown_ms = $retry_after_secs * 1000;
        } elseif ($cooldown_ms === null) {
            $cooldown_ms = self::DEFAULT_COOLDOWN_MS;
        }

        foreach ($pool['accounts'] as &$account) {
            if (($account['email'] ?? '') === $email) {
                $account['status']        = 'rate-limited';
                $account['cooldownUntil'] = $now_ms + $cooldown_ms;
                $this->savePool($pool);
                return true;
            }
        }
        unset($account);

        return false;
    }

    /**
     * Refreshes a specific account's token using the provider's token endpoint.
     *
     * @param string $email
     * @return bool
     */
    public function refreshAccount(string $email): bool
    {
        if (!$this->config->supportsOAuth) {
            return false;
        }

        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();

        foreach ($pool['accounts'] as &$account) {
            if (($account['email'] ?? '') !== $email) {
                continue;
            }
            if (empty($account['refresh'])) {
                return false;
            }

            $refreshed = $this->refreshTokens($account['refresh']);
            if ($refreshed === null) {
                $account['status'] = 'refresh-failed';
                $this->savePool($pool);
                return false;
            }

            $account['access']        = $refreshed['access_token'];
            $account['expires']       = $now_ms + ($refreshed['expires_in'] * 1000);
            $account['status']        = 'active';
            $account['lastUsed']      = gmdate('Y-m-d\TH:i:s\Z');
            $account['cooldownUntil'] = null;
            if (!empty($refreshed['refresh_token'])) {
                $account['refresh'] = $refreshed['refresh_token'];
            }

            $this->savePool($pool);
            return true;
        }
        unset($account);

        return false;
    }

    /**
     * Performs a health check on all accounts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function healthCheck(): array
    {
        $pool    = $this->loadPool();
        $results = [];
        $now_ms  = $this->nowMs();

        foreach ($pool['accounts'] as $account) {
            $email  = $account['email'] ?? 'unknown';
            $status = $account['status'] ?? 'unknown';
            $token  = $account['access'] ?? '';

            $result = [
                'email'        => $email,
                'status'       => $status,
                'tokenExpired' => ($account['expires'] ?? 0) <= $now_ms,
                'hasRefresh'   => !empty($account['refresh']),
                'accountId'    => $account['accountId'] ?? null,
                'validity'     => 'unknown',
            ];

            if (!empty($token) && $this->config->healthCheckUrl !== '') {
                $result['validity'] = $this->validateToken($token);
            } elseif (!empty($token)) {
                // Provider has no health endpoint; assume ok if token isn't expired.
                $result['validity'] = $result['tokenExpired'] ? 'expired' : 'ok';
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Generates a PKCE verifier/challenge and returns the authorize URL.
     *
     * @param string|null $login_hint   Optional email to pre-populate.
     * @param string|null $login_method Optional ('sso','magic_link','google').
     * @return array{verifier:string, challenge:string, state:string, authorize_url:string}
     * @throws \RuntimeException If the provider does not support OAuth.
     */
    public function startOAuthFlow(
        ?string $login_hint = null,
        ?string $login_method = null
    ): array {
        if (!$this->config->supportsOAuth) {
            throw new \RuntimeException(sprintf(
                'Provider "%s" does not support OAuth — use the manual token form instead.',
                $this->config->id
            ));
        }

        $verifier  = $this->generateVerifier();
        $challenge = $this->generateChallenge($verifier);
        $state     = bin2hex(random_bytes(24));

        // PKCE verifier is keyed by provider id so concurrent flows for
        // different providers don't trample each other.
        set_transient(
            self::PKCE_TRANSIENT_PREFIX . $this->config->id . '_' . $state,
            $verifier,
            600
        );

        $params = [
            'client_id'             => $this->config->clientId,
            'response_type'         => 'code',
            'redirect_uri'          => $this->config->redirectUri,
            'scope'                 => $this->config->scopes,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state,
        ];

        if ($this->config->sendCodeTrueParam) {
            $params['code'] = 'true';
        }
        if ($this->config->googleOfflineConsent) {
            $params['access_type'] = 'offline';
            $params['prompt']      = 'consent';
        }
        if ($login_hint !== null && $login_hint !== '') {
            $params['login_hint'] = $login_hint;
        }
        if ($login_method !== null && $login_method !== '') {
            $params['login_method'] = $login_method;
        }

        return [
            'verifier'      => $verifier,
            'challenge'     => $challenge,
            'state'         => $state,
            'authorize_url' => $this->config->authorizeUrl . '?' . http_build_query($params),
        ];
    }

    /**
     * Exchanges an authorization code for tokens and stores the account.
     *
     * @param string $code
     * @param string $state
     * @param string $email
     * @return array<string,mixed>|null Returns null on transport failure.
     *                                  Returns ['scope_error'=>true,'granted_scopes'=>[]] on insufficient scope.
     */
    public function exchangeCode(string $code, string $state, string $email): ?array
    {
        $this->lastError = null;

        if (!$this->config->supportsOAuth) {
            $this->lastError = sprintf(
                'Provider "%s" does not support OAuth.',
                $this->config->id
            );
            return null;
        }

        // Some flows return the bare URL the user was redirected to (the
        // localhost callback URL for OpenAI). Extract code and state from
        // it BEFORE looking up the transient — the URL state must win over
        // the JS-side state, because the user may have started the flow
        // in a stale tab.
        if (preg_match('#[?&]state=([^&\s]+)#', $code, $sm)) {
            $url_state = urldecode($sm[1]);
            if ($url_state !== '') {
                $state = $url_state;
            }
        }
        // Anthropic returns the code as "code#state" (no URL wrapper).
        if (strpos($code, '#') !== false && !preg_match('#[?&]code=#', $code)) {
            $code = substr($code, 0, strpos($code, '#'));
        }
        if (preg_match('#[?&]code=([^&\s]+)#', $code, $m)) {
            $code = urldecode($m[1]);
        }

        $transient_key = self::PKCE_TRANSIENT_PREFIX . $this->config->id . '_' . $state;
        $verifier      = get_transient($transient_key);
        delete_transient($transient_key);

        if (empty($verifier)) {
            $this->lastError = __(
                'Authorization session expired or state mismatch. Click "Authorize" again to restart.',
                'ai-provider-for-anthropic-max'
            );
            return null;
        }

        // Build the token-exchange body. OpenAI's token endpoint rejects
        // unknown fields, so `state` is sent only for Anthropic where the
        // token endpoint requires it (Claude CLI v2.1.x behaviour).
        $body_params = [
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->config->clientId,
            'redirect_uri'  => $this->config->redirectUri,
            'code_verifier' => $verifier,
        ];
        if ($this->config->id === 'anthropic') {
            $body_params['state'] = $state;
        }
        $body = $this->buildTokenRequestBody($body_params);

        $response = wp_remote_post(
            $this->config->tokenEndpoint,
            [
                'headers' => [
                    'Content-Type' => $this->config->tokenContentType,
                    'User-Agent'   => $this->config->userAgent,
                ],
                'body'    => $body,
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            $this->lastError = sprintf(
                /* translators: %s: WP_Error message */
                __( 'Network error contacting token endpoint: %s', 'ai-provider-for-anthropic-max' ),
                $response->get_error_message()
            );
            error_log(sprintf(
                '[anthropic-max:%s] Token exchange WP_Error: %s',
                $this->config->id,
                $response->get_error_message()
            ));
            return null;
        }
        $status   = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        if ($status !== 200) {
            $this->lastError = sprintf(
                /* translators: 1: HTTP status, 2: provider error message */
                __( 'Token endpoint returned HTTP %1$d: %2$s', 'ai-provider-for-anthropic-max' ),
                $status,
                $this->stringifyTokenError($body_raw)
            );
            error_log(sprintf(
                '[anthropic-max:%s] Token exchange HTTP %s body: %s',
                $this->config->id,
                $status,
                $body_raw
            ));
            return null;
        }

        $data = json_decode($body_raw, true);
        if (!is_array($data) || empty($data['access_token'])) {
            $this->lastError = __(
                'Token endpoint returned 200 but no access_token in the body.',
                'ai-provider-for-anthropic-max'
            );
            return null;
        }

        // Optional scope validation (Anthropic).
        if ($this->config->requiredScope !== '' && !empty($data['scope'])) {
            $granted = explode(' ', (string) $data['scope']);
            if (!in_array($this->config->requiredScope, $granted, true)) {
                return [
                    'scope_error'    => true,
                    'granted_scopes' => $granted,
                ];
            }
        }

        $account_id = null;
        if (!empty($data['account']) && is_array($data['account'])) {
            // Anthropic returns `account.uuid` directly in the token response.
            $account_id = $data['account']['uuid'] ?? null;
        }
        // OpenAI ChatGPT-OAuth tokens carry `chatgpt_account_id` as a JWT claim
        // (either in id_token or access_token). This id is required as the
        // `chatgpt-account-id` header on every Codex backend request, so we
        // capture it at exchange time instead of decoding the JWT on every call.
        if ($account_id === null) {
            $jwt_source = $data['id_token'] ?? $data['access_token'] ?? '';
            if (is_string($jwt_source) && $jwt_source !== '') {
                $jwt = $this->decodeJwtPayload($jwt_source);
                $account_id = $jwt['chatgpt_account_id']
                    ?? ($jwt['https://api.openai.com/auth']['chatgpt_account_id'] ?? null);
                if (is_array($account_id)) {
                    $account_id = null;
                }
                if ($account_id !== null) {
                    $account_id = (string) $account_id;
                }
            }
        }

        $result = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];

        $this->addAccount(
            $email,
            $result['access_token'],
            $result['refresh_token'],
            $result['expires_in'],
            $account_id
        );

        return $result;
    }

    /**
     * Adds an account by direct token paste (used for Cursor and as
     * a manual fallback for any provider).
     *
     * Email may be derived from the JWT 'sub' claim if empty.
     *
     * @param string      $email
     * @param string      $access_token
     * @param string      $refresh_token
     * @param int|null    $expires_in    Seconds until expiry. Null → derive from JWT exp or use 3600.
     * @return array{email:string,count:int}
     */
    public function addAccountManual(
        string $email,
        string $access_token,
        string $refresh_token = '',
        ?int $expires_in = null
    ): array {
        $jwt = $this->decodeJwtPayload($access_token);

        if ($email === '' || strpos($email, '@') === false) {
            $email = (string) ($jwt['email'] ?? $jwt['preferred_username'] ?? $jwt['sub'] ?? 'unknown');
        }

        if ($expires_in === null) {
            if (isset($jwt['exp']) && is_int($jwt['exp']) && $jwt['exp'] > time()) {
                $expires_in = $jwt['exp'] - time();
            } else {
                $expires_in = 3600;
            }
        }

        $count = $this->addAccount($email, $access_token, $refresh_token, $expires_in);
        return ['email' => $email, 'count' => $count];
    }

    /**
     * Returns the number of accounts in the pool.
     *
     * @return int
     */
    public function count(): int
    {
        $pool = $this->loadPool();
        return count($pool['accounts'] ?? []);
    }

    // -----------------------------------------------------------------
    // Device-code flow (OpenAI / providers where supportsDeviceCode=true)
    // -----------------------------------------------------------------

    /**
     * Starts an OAuth 2.0 device-code flow.
     *
     * POSTs to the provider's device-code URL to obtain a user code and
     * device auth id, stores them in a transient so `pollDeviceCode()` can
     * retrieve them later, and returns everything the UI needs to prompt
     * the user.
     *
     * @since 1.2.0
     *
     * @return array{user_code:string,verification_url:string,session_key:string,interval_ms:int,expires_in_ms:int}|null
     *         Null (with $lastError set) on failure.
     */
    public function startDeviceCode(): ?array
    {
        if (!$this->config->supportsDeviceCode || $this->config->deviceCodeUrl === '') {
            $this->lastError = sprintf(
                'Provider "%s" does not support the device-code flow.',
                $this->config->id
            );
            return null;
        }

        $response = wp_remote_post(
            $this->config->deviceCodeUrl,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'originator'   => 'opencode',
                    'User-Agent'   => $this->config->userAgent,
                ],
                'body'    => wp_json_encode(['client_id' => $this->config->clientId]),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            $this->lastError = sprintf(
                'Network error requesting device code: %s',
                $response->get_error_message()
            );
            return null;
        }

        $status   = (int) wp_remote_retrieve_response_code($response);
        $body_raw = (string) wp_remote_retrieve_body($response);

        if ($status !== 200) {
            if ($status === 404) {
                $this->lastError = 'OpenAI device-code login is not available for this account. Try a different login method.';
            } else {
                $this->lastError = $this->stringifyTokenError($body_raw) ?: sprintf('Device code request failed (HTTP %d).', $status);
            }
            return null;
        }

        $body = json_decode($body_raw, true);
        if (!is_array($body)) {
            $this->lastError = 'Device code response was not valid JSON.';
            return null;
        }

        $device_auth_id = (string) ($body['device_auth_id'] ?? '');
        $user_code      = (string) ($body['user_code'] ?? $body['usercode'] ?? '');
        if ($device_auth_id === '' || $user_code === '') {
            $this->lastError = 'Device code response was missing device_auth_id or user_code.';
            return null;
        }

        // Default 5 s poll interval; minimum 1 s.
        $interval_s  = max(1, (int) ($body['interval'] ?? 5));
        $interval_ms = $interval_s * 1000;
        $timeout_ms  = 15 * 60 * 1000; // 15 minutes, matches OpenAI's device code TTL.

        $session_key     = bin2hex(random_bytes(16));
        $transient_key   = 'anthropic_max_dc_' . $session_key;
        $transient_value = [
            'device_auth_id' => $device_auth_id,
            'user_code'      => $user_code,
            'interval_ms'    => $interval_ms,
            'expires_at_ms'  => (int) (microtime(true) * 1000) + $timeout_ms,
        ];
        set_transient($transient_key, $transient_value, 901); // TTL slightly above 15 min.

        error_log(sprintf(
            '[anthropic-max:%s] Device code started session=%s user_code=%s',
            $this->config->id,
            $session_key,
            $user_code
        ));

        return [
            'user_code'        => $user_code,
            'verification_url' => 'https://auth.openai.com/codex/device',
            'session_key'      => $session_key,
            'interval_ms'      => $interval_ms,
            'expires_in_ms'    => $timeout_ms,
        ];
    }

    /**
     * Polls for device-code authorization and, on success, exchanges the
     * authorization code for tokens and saves the account.
     *
     * Called once per poll tick from the REST layer. The JS side is
     * responsible for timing (using the `interval_ms` from startDeviceCode).
     *
     * @since 1.2.0
     *
     * @param string $session_key The key returned by startDeviceCode().
     * @param string $email       Optional email hint; auto-derived from JWT if blank.
     * @return array{status:string,...} Keys:
     *   - status: 'pending' | 'complete' | 'expired' | 'error'
     *   - email (when status='complete')
     *   - message (when status='error')
     */
    public function pollDeviceCode(string $session_key, string $email = ''): array
    {
        if ($session_key === '' || !preg_match('/^[0-9a-f]{32}$/i', $session_key)) {
            return ['status' => 'error', 'message' => 'Invalid session key.'];
        }

        $transient_key = 'anthropic_max_dc_' . $session_key;
        $session       = get_transient($transient_key);
        if (!is_array($session)) {
            return ['status' => 'expired', 'message' => 'Device code session not found or expired. Please start again.'];
        }

        $now_ms = (int) (microtime(true) * 1000);
        if ($now_ms >= (int) ($session['expires_at_ms'] ?? 0)) {
            delete_transient($transient_key);
            return ['status' => 'expired', 'message' => 'Device code expired after 15 minutes. Please start again.'];
        }

        // Poll the device token endpoint once.
        $response = wp_remote_post(
            $this->config->deviceTokenUrl,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'originator'   => 'opencode',
                    'User-Agent'   => $this->config->userAgent,
                ],
                'body'    => wp_json_encode([
                    'device_auth_id' => $session['device_auth_id'],
                    'user_code'      => $session['user_code'],
                ]),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => 'Network error polling device code: ' . $response->get_error_message()];
        }

        $status   = (int) wp_remote_retrieve_response_code($response);
        $body_raw = (string) wp_remote_retrieve_body($response);

        // 403/404 = still waiting for user to authorise; not an error.
        if ($status === 403 || $status === 404) {
            return ['status' => 'pending'];
        }

        if ($status !== 200) {
            return ['status' => 'error', 'message' => $this->stringifyTokenError($body_raw) ?: sprintf('Device poll failed (HTTP %d).', $status)];
        }

        $body              = json_decode($body_raw, true);
        $auth_code         = is_array($body) ? (string) ($body['authorization_code'] ?? '') : '';
        $device_code_verifier = is_array($body) ? (string) ($body['code_verifier'] ?? '') : '';

        if ($auth_code === '' || $device_code_verifier === '') {
            return ['status' => 'error', 'message' => 'Device authorization response missing authorization_code or code_verifier.'];
        }

        // Exchange the authorization code for tokens.
        $token_response = wp_remote_post(
            $this->config->tokenEndpoint,
            [
                'headers' => [
                    'Content-Type' => $this->config->tokenContentType,
                    'User-Agent'   => $this->config->userAgent,
                ],
                'body'    => $this->buildTokenRequestBody([
                    'grant_type'    => 'authorization_code',
                    'code'          => $auth_code,
                    'redirect_uri'  => $this->config->deviceCallbackUri,
                    'client_id'     => $this->config->clientId,
                    'code_verifier' => $device_code_verifier,
                ]),
                'timeout' => 15,
            ]
        );

        if (is_wp_error($token_response)) {
            return ['status' => 'error', 'message' => 'Network error exchanging device code: ' . $token_response->get_error_message()];
        }

        $tok_status   = (int) wp_remote_retrieve_response_code($token_response);
        $tok_body_raw = (string) wp_remote_retrieve_body($token_response);

        if ($tok_status !== 200) {
            return ['status' => 'error', 'message' => 'Token exchange failed (HTTP ' . $tok_status . '): ' . $this->stringifyTokenError($tok_body_raw)];
        }

        $tok_data      = json_decode($tok_body_raw, true);
        $access_token  = is_array($tok_data) ? (string) ($tok_data['access_token'] ?? '') : '';
        $refresh_token = is_array($tok_data) ? (string) ($tok_data['refresh_token'] ?? '') : '';
        $expires_in    = is_array($tok_data) ? (int) ($tok_data['expires_in'] ?? 3600) : 3600;

        if ($access_token === '') {
            return ['status' => 'error', 'message' => 'Token exchange returned no access_token.'];
        }

        // Extract the chatgpt_account_id from the JWT (needed for Codex backend calls).
        $account_id = null;
        $jwt        = $this->decodeJwtPayload($access_token);
        $account_id = $jwt['chatgpt_account_id']
            ?? ($jwt['https://api.openai.com/auth']['chatgpt_account_id'] ?? null);
        if (is_array($account_id)) {
            $account_id = null;
        }
        if ($account_id !== null) {
            $account_id = (string) $account_id;
        }

        // Derive email from JWT if caller didn't provide one.
        if ($email === '' || strpos($email, '@') === false) {
            $email = (string) (
                $jwt['https://api.openai.com/profile']['email']
                ?? $jwt['email']
                ?? $jwt['preferred_username']
                ?? $jwt['sub']
                ?? 'openai-unknown'
            );
        }

        $this->addAccount($email, $access_token, $refresh_token, $expires_in, $account_id);
        delete_transient($transient_key);

        error_log(sprintf(
            '[anthropic-max:%s] Device code completed session=%s email=%s accountId=%s',
            $this->config->id,
            $session_key,
            $email,
            $account_id ?? 'none'
        ));

        return ['status' => 'complete', 'email' => $email];
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    /**
     * Refreshes an access token using a refresh token. Provider-aware.
     *
     * @param string $refresh_token
     * @return array{access_token:string,refresh_token:string,expires_in:int}|null
     */
    protected function refreshTokens(string $refresh_token): ?array
    {
        if ($refresh_token === '' || !$this->config->supportsOAuth) {
            return null;
        }

        $body = $this->buildTokenRequestBody([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $this->config->clientId,
        ]);

        $response = wp_remote_post(
            $this->config->tokenEndpoint,
            [
                'headers' => [
                    'Content-Type' => $this->config->tokenContentType,
                    'User-Agent'   => $this->config->userAgent,
                ],
                'body'    => $body,
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            error_log(sprintf(
                '[anthropic-max:%s] Token refresh WP_Error: %s',
                $this->config->id,
                $response->get_error_message()
            ));
            return null;
        }

        $status   = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        if ($status !== 200) {
            error_log(sprintf(
                '[anthropic-max:%s] Token refresh HTTP %s body: %s',
                $this->config->id,
                $status,
                $body_raw
            ));
            return null;
        }

        $data = json_decode($body_raw, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refresh_token,
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];
    }

    /**
     * Encodes the token request body in the provider's content type.
     *
     * @param array<string,mixed> $params
     * @return string
     */
    protected function buildTokenRequestBody(array $params): string
    {
        if ($this->config->tokenContentType === 'application/x-www-form-urlencoded') {
            return http_build_query($params);
        }
        $json = wp_json_encode($params);
        return $json === false ? '' : $json;
    }

    /**
     * Extracts a human-readable error message from a non-200 token-endpoint
     * response body. Handles RFC 6749 JSON (`error_description`, `error`) as
     * well as providers that return arrays, nested objects, or plain text.
     *
     * If the response body looks like HTML (e.g. a Cloudflare "Just a moment…"
     * challenge page), returns a generic operator-friendly message and writes
     * the full body to the PHP error log instead of leaking the markup into
     * the admin UI. Otherwise falls back to the raw body trimmed to 300 chars
     * so the admin UI never shows the literal string "Array".
     *
     * @param string $body_raw Raw HTTP response body.
     * @return string Readable error message safe to render in admin UI.
     */
    protected function stringifyTokenError(string $body_raw): string
    {
        if ($this->looksLikeHtml($body_raw)) {
            error_log(sprintf(
                '[anthropic-max:%s] Upstream returned HTML challenge page (likely Cloudflare). Body: %s',
                $this->config->id,
                substr($body_raw, 0, 2000)
            ));
            return __(
                'The upstream provider returned an HTML challenge page (likely Cloudflare bot protection). The request was blocked before reaching OpenAI. Please wait a minute and try again.',
                'ai-provider-for-anthropic-max'
            );
        }

        $parsed = json_decode($body_raw, true);
        if (is_array($parsed)) {
            foreach (['error_description', 'error', 'message'] as $key) {
                if (!isset($parsed[$key])) {
                    continue;
                }
                $value = $parsed[$key];
                if (is_string($value) && $value !== '') {
                    return $value;
                }
                if (is_array($value)) {
                    $flat = [];
                    array_walk_recursive($value, static function ($v) use (&$flat): void {
                        if (is_scalar($v)) {
                            $flat[] = (string) $v;
                        }
                    });
                    if (!empty($flat)) {
                        return implode('; ', $flat);
                    }
                }
                if (is_scalar($value)) {
                    return (string) $value;
                }
            }
        }
        $trimmed = trim($body_raw);
        if ($trimmed === '') {
            return __( '(empty response body)', 'ai-provider-for-anthropic-max' );
        }
        return strlen($trimmed) > 300 ? substr($trimmed, 0, 300) . '…' : $trimmed;
    }

    /**
     * Detects whether a raw HTTP response body is HTML rather than the
     * expected JSON/form-encoded payload. Used to short-circuit error
     * rendering when upstream intermediaries (Cloudflare, WAFs, captive
     * portals) inject challenge pages.
     *
     * @param string $body_raw Raw HTTP response body.
     * @return bool True when the body begins with an HTML document.
     */
    protected function looksLikeHtml(string $body_raw): bool
    {
        $head = ltrim(substr($body_raw, 0, 256));
        if ($head === '') {
            return false;
        }
        // Case-insensitive match against the most common HTML document openers
        // and the Cloudflare "Just a moment…" challenge marker.
        return (bool) preg_match(
            '/^(<!doctype\s+html|<html\b|<\?xml[^>]*>\s*<!doctype\s+html|<head\b)/i',
            $head
        ) || stripos($head, '<title>Just a moment') !== false;
    }

    /**
     * Validates a token against the provider's health endpoint.
     *
     * @param string $token
     * @return string 'ok' | 'invalid' | 'expired' | 'http-NNN' | 'error' | 'unknown'
     */
    protected function validateToken(string $token): string
    {
        if ($this->config->healthCheckUrl === '') {
            return 'unknown';
        }

        $headers = array_merge(
            [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'    => $this->config->userAgent,
            ],
            $this->config->healthCheckHeaders
        );

        $response = wp_remote_get(
            $this->config->healthCheckUrl,
            ['headers' => $headers, 'timeout' => 10]
        );

        if (is_wp_error($response)) {
            return 'error';
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return 'ok';
        }
        if ($code === 401) {
            return 'invalid';
        }
        return 'http-' . $code;
    }

    /**
     * Decodes a JWT payload (no signature verification — used only to extract
     * email/expiry from a token the user already trusted by pasting it).
     *
     * @param string $jwt
     * @return array<string,mixed>
     */
    protected function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return [];
        }
        $payload = strtr($parts[1], '-_', '+/');
        $padded  = str_pad($payload, strlen($payload) + (4 - strlen($payload) % 4) % 4, '=');
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            return [];
        }
        $data = json_decode($decoded, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Clears expired cooldowns in-place. Returns whether anything changed.
     *
     * @param array<string,mixed> $pool   Passed by reference.
     * @param int                 $now_ms
     * @return bool
     */
    protected function clearExpiredCooldowns(array &$pool, int $now_ms): bool
    {
        $changed = false;
        foreach ($pool['accounts'] as &$account) {
            if (
                ($account['status'] ?? '') === 'rate-limited' &&
                isset($account['cooldownUntil']) &&
                $account['cooldownUntil'] > 0 &&
                $account['cooldownUntil'] <= $now_ms
            ) {
                $account['status']        = 'idle';
                $account['cooldownUntil'] = null;
                $changed                  = true;
            }
        }
        unset($account);
        return $changed;
    }

    /**
     * @return int Now in milliseconds.
     */
    protected function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * @return string PKCE code_verifier (43 chars, base64url, no padding).
     */
    protected function generateVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * @param string $verifier
     * @return string PKCE code_challenge (S256).
     */
    protected function generateChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }
}
