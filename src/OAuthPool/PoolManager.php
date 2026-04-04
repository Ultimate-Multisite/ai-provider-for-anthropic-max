<?php
/**
 * OAuth account pool manager.
 *
 * Stores, rotates, and manages a pool of Anthropic Max OAuth accounts
 * in the WordPress options table. Mirrors the design of aidevops's
 * oauth-pool-helper.sh but implemented in PHP for WordPress.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\OAuthPool;

/**
 * Manages the pool of Anthropic Max OAuth accounts.
 *
 * @since 1.0.0
 */
class PoolManager
{
    /**
     * WordPress option key for the encrypted pool data.
     */
    public const OPTION_KEY = 'anthropic_max_oauth_pool';

    /**
     * Transient key prefix for PKCE verifiers.
     */
    public const PKCE_TRANSIENT_PREFIX = 'anthropic_max_pkce_';

    /**
     * Anthropic OAuth constants (same as Claude CLI).
     *
     * Alignment notes (vs Claude CLI codebase):
     *   CLIENT_ID       — identical to Claude CLI (public, same for all clients).
     *   TOKEN_ENDPOINT  — identical to Claude CLI prod config.
     *   AUTHORIZE_URL   — we hit claude.ai directly; Claude CLI now routes through
     *                     https://claude.com/cai/oauth/authorize for attribution,
     *                     which 307-redirects to claude.ai. Ours is the final dest.
     *                     FALLBACK: if authorize breaks, try https://claude.com/cai/oauth/authorize.
     *   REDIRECT_URI    — console.anthropic.com is the legacy Console domain.
     *                     platform.claude.com is the current primary domain. Both
     *                     redirect URIs are currently registered by Anthropic.
     *                     FALLBACK: 'https://platform.claude.com/oauth/code/callback'
     *   SCOPES          — identical to Claude CLI's ALL_OAUTH_SCOPES union.
     */
    public const CLIENT_ID      = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';
    public const TOKEN_ENDPOINT  = 'https://platform.claude.com/v1/oauth/token';
    public const AUTHORIZE_URL   = 'https://claude.ai/oauth/authorize';
    public const REDIRECT_URI    = 'https://console.anthropic.com/oauth/code/callback';
    public const SCOPES          = 'org:create_api_key user:profile user:inference user:sessions:claude_code user:mcp_servers user:file_upload';

    /**
     * User-Agent sent on token exchange and refresh requests.
     *
     * Aligned with Claude CLI format: "claude-cli/{version} (external, cli)".
     * The "(wordpress-plugin)" suffix identifies this client to Anthropic.
     * FALLBACK: if UA filtering causes issues, try 'claude-cli/2.1.80'.
     */
    public const USER_AGENT = 'claude-cli/2.1.80 (wordpress-plugin)';

    /**
     * Default cooldown duration for rate-limited accounts (5 minutes).
     */
    public const DEFAULT_COOLDOWN_MS = 300000;

    /**
     * Required scope that must be present in the granted scope list.
     * A token missing this scope will fail at inference time.
     */
    public const REQUIRED_SCOPE = 'user:inference';

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Loads the pool from the WordPress options table.
     *
     * @return array The pool data with 'accounts' key.
     */
    public function loadPool(): array
    {
        $pool = get_option(self::OPTION_KEY, []);

        if (!is_array($pool) || !isset($pool['accounts'])) {
            return ['accounts' => []];
        }

        return $pool;
    }

    /**
     * Saves the pool to the WordPress options table.
     *
     * @param array $pool The pool data.
     * @return bool Whether the save was successful.
     */
    public function savePool(array $pool): bool
    {
        return update_option(self::OPTION_KEY, $pool, false);
    }

    /**
     * Returns the list of accounts (without exposing tokens).
     *
     * @return array[] List of sanitized account objects.
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
            ];
        }, $accounts);
    }

    /**
     * Returns the best available access token and its account email.
     *
     * Same selection logic as getActiveToken(). Returns an associative array
     * with 'token' and 'email' keys, or null if no account is available.
     * Used by AnthropicOAuthRequestAuthentication to track which account
     * is in use so it can be marked rate-limited on 429/529 responses.
     *
     * @return array{token: string, email: string}|null
     */
    public function getActiveTokenWithEmail(): ?array
    {
        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();

        // Clear expired cooldowns.
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
            $refresher = new TokenRefresher();
            $refreshed = $refresher->refresh($best['refresh']);

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
     * Returns the best available access token from the pool.
     *
     * Selection priority:
     * 1. Active, non-expired, non-rate-limited accounts.
     * 2. Expired accounts with a refresh token (auto-refreshed).
     * 3. Returns null if no accounts are available.
     *
     * @return string|null The access token, or null if none available.
     */
    public function getActiveToken(): ?string
    {
        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();

        // Clear expired cooldowns.
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

        if ($changed) {
            $this->savePool($pool);
        }

        // Find the best available account (least recently used, not rate-limited).
        $best       = null;
        $best_index = -1;

        foreach ($pool['accounts'] as $index => $account) {
            $status = $account['status'] ?? 'idle';

            // Skip rate-limited accounts.
            if ($status === 'rate-limited') {
                continue;
            }

            // Skip accounts without tokens.
            if (empty($account['access'])) {
                continue;
            }

            // Prefer the least recently used account.
            if ($best === null || ($account['lastUsed'] ?? '') < ($best['lastUsed'] ?? '')) {
                $best       = $account;
                $best_index = $index;
            }
        }

        if ($best === null) {
            return null;
        }

        // Auto-refresh if expired.
        $expires = $best['expires'] ?? 0;
        if ($expires > 0 && $expires <= $now_ms && !empty($best['refresh'])) {
            $refresher = new TokenRefresher();
            $refreshed = $refresher->refresh($best['refresh']);

            if ($refreshed !== null) {
                $pool['accounts'][$best_index]['access']   = $refreshed['access_token'];
                $pool['accounts'][$best_index]['expires']  = $now_ms + ($refreshed['expires_in'] * 1000);
                $pool['accounts'][$best_index]['status']   = 'active';
                $pool['accounts'][$best_index]['lastUsed'] = gmdate('Y-m-d\TH:i:s\Z');

                if (!empty($refreshed['refresh_token'])) {
                    $pool['accounts'][$best_index]['refresh'] = $refreshed['refresh_token'];
                }

                $this->savePool($pool);
                return $refreshed['access_token'];
            }

            // Refresh failed — mark account as needing attention.
            $pool['accounts'][$best_index]['status'] = 'refresh-failed';
            $this->savePool($pool);
            return null;
        }

        // Mark as active and update last used.
        $pool['accounts'][$best_index]['status']   = 'active';
        $pool['accounts'][$best_index]['lastUsed'] = gmdate('Y-m-d\TH:i:s\Z');
        $this->savePool($pool);

        return $best['access'];
    }

    /**
     * Adds or updates an account in the pool.
     *
     * @param string      $email         The account email.
     * @param string      $access_token  The OAuth access token.
     * @param string      $refresh_token The OAuth refresh token.
     * @param int         $expires_in    Token lifetime in seconds.
     * @param string|null $account_id    Optional account UUID from the token response.
     * @return int The total number of accounts in the pool.
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

        // Check if account already exists (update it).
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
     * Removes an account from the pool by email.
     *
     * @param string $email The account email to remove.
     * @return bool Whether an account was found and removed.
     */
    public function removeAccount(string $email): bool
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
            return false;
        }

        $this->savePool($pool);
        return true;
    }

    /**
     * Marks an account as rate-limited with a cooldown period.
     *
     * Respects a Retry-After value when provided (e.g. from a 429 response
     * header). Falls back to DEFAULT_COOLDOWN_MS when not specified.
     *
     * @param string   $email            The account email.
     * @param int|null $cooldown_ms      Cooldown in milliseconds (null for default).
     * @param int|null $retry_after_secs Retry-After header value in seconds (overrides cooldown_ms).
     * @return bool Whether the account was found.
     */
    public function markRateLimited(string $email, ?int $cooldown_ms = null, ?int $retry_after_secs = null): bool
    {
        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();

        if ($retry_after_secs !== null && $retry_after_secs > 0) {
            // Retry-After takes precedence — use the server-specified window.
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
     * Marks the currently active account as rate-limited based on an HTTP response.
     *
     * Parses the Retry-After header (seconds or HTTP-date) from the response
     * and uses it as the cooldown duration. Falls back to DEFAULT_COOLDOWN_MS.
     *
     * @param string $email    The account email to mark.
     * @param array  $response The wp_remote_* response array.
     * @return bool Whether the account was found and marked.
     */
    public function markRateLimitedFromResponse(string $email, array $response): bool
    {
        $retry_after_secs = $this->parseRetryAfter($response);
        return $this->markRateLimited($email, null, $retry_after_secs);
    }

    /**
     * Refreshes a specific account's token.
     *
     * @param string $email The account email.
     * @return bool Whether the refresh was successful.
     */
    public function refreshAccount(string $email): bool
    {
        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();

        foreach ($pool['accounts'] as &$account) {
            if (($account['email'] ?? '') !== $email) {
                continue;
            }

            if (empty($account['refresh'])) {
                return false;
            }

            $refresher = new TokenRefresher();
            $result    = $refresher->refresh($account['refresh']);

            if ($result === null) {
                $account['status'] = 'refresh-failed';
                $this->savePool($pool);
                return false;
            }

            $account['access']        = $result['access_token'];
            $account['expires']       = $now_ms + ($result['expires_in'] * 1000);
            $account['status']        = 'active';
            $account['lastUsed']      = gmdate('Y-m-d\TH:i:s\Z');
            $account['cooldownUntil'] = null;

            if (!empty($result['refresh_token'])) {
                $account['refresh'] = $result['refresh_token'];
            }

            $this->savePool($pool);
            return true;
        }
        unset($account);

        return false;
    }

    /**
     * Performs a health check on all accounts by testing the /v1/models endpoint.
     *
     * @return array[] Health status for each account.
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

            if (!empty($token)) {
                $result['validity'] = $this->validateToken($token);
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Validates a token against the Anthropic /v1/models endpoint.
     *
     * @param string $token The access token to validate.
     * @return string Validation result: 'ok', 'invalid', 'expired', or 'error'.
     */
    protected function validateToken(string $token): string
    {
        $response = wp_remote_get(
            'https://api.anthropic.com/v1/models',
            [
                'headers' => [
                    'Authorization'    => 'Bearer ' . $token,
                    'anthropic-version' => '2023-06-01',
                    'anthropic-beta'   => 'oauth-2025-04-20',
                    'User-Agent'       => self::USER_AGENT,
                ],
                'timeout' => 10,
            ]
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
     * Generates a PKCE verifier and challenge, stores the verifier in a transient.
     *
     * Optional parameters align with Claude CLI's authorize URL support:
     *   - login_hint:   pre-populate the email field on the login page (standard OIDC).
     *   - login_method: request a specific login method ('sso', 'magic_link', 'google').
     *   - org_uuid:     pre-select an org for team/enterprise logins.
     *
     * @param string|null $login_hint   Optional email to pre-populate on the login page.
     * @param string|null $login_method Optional login method ('sso', 'magic_link', 'google').
     * @param string|null $org_uuid     Optional org UUID for team/enterprise logins.
     * @return array{verifier: string, challenge: string, state: string, authorize_url: string}
     */
    public function startOAuthFlow(
        ?string $login_hint = null,
        ?string $login_method = null,
        ?string $org_uuid = null
    ): array {
        // Generate PKCE code_verifier (43 chars, base64url no padding).
        $verifier  = $this->generateVerifier();
        $challenge = $this->generateChallenge($verifier);
        $state     = bin2hex(random_bytes(24));

        // Store verifier + state in a transient (10 minute TTL).
        set_transient(self::PKCE_TRANSIENT_PREFIX . $state, $verifier, 600);

        $params = [
            'client_id'             => self::CLIENT_ID,
            'response_type'         => 'code',
            'redirect_uri'          => self::REDIRECT_URI,
            'scope'                 => self::SCOPES,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state,
            // Alignment: &code=true matches Claude CLI — tells the login page to
            // show the Claude Max upsell. Required for parity with the official client.
            'code'                  => 'true',
        ];

        // Optional params supported by Claude CLI (not sent unless provided).
        if ($login_hint !== null && $login_hint !== '') {
            $params['login_hint'] = $login_hint;
        }
        if ($login_method !== null && $login_method !== '') {
            $params['login_method'] = $login_method;
        }
        if ($org_uuid !== null && $org_uuid !== '') {
            $params['orgUUID'] = $org_uuid;
        }

        $authorize_url = self::AUTHORIZE_URL . '?' . http_build_query($params);

        return [
            'verifier'      => $verifier,
            'challenge'     => $challenge,
            'state'         => $state,
            'authorize_url' => $authorize_url,
        ];
    }

    /**
     * Exchanges an authorization code for tokens.
     *
     * After a successful exchange, validates that the granted scope includes
     * 'user:inference'. A token missing this scope will fail at inference time;
     * catching it here gives a clearer error at add-account time.
     *
     * The token response may also contain an 'account' object with a UUID
     * (account.uuid) which is stored as accountId for diagnostics.
     *
     * @param string $code  The authorization code.
     * @param string $state The state nonce.
     * @param string $email The account email.
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     *         Returns null on failure. Returns WP_Error-compatible array on scope failure
     *         via the 'scope_error' key set to true.
     */
    public function exchangeCode(string $code, string $state, string $email): ?array
    {
        // Retrieve and delete the PKCE verifier.
        $verifier = get_transient(self::PKCE_TRANSIENT_PREFIX . $state);
        delete_transient(self::PKCE_TRANSIENT_PREFIX . $state);

        if (empty($verifier)) {
            return null;
        }

        $body = wp_json_encode([
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'client_id'     => self::CLIENT_ID,
            'redirect_uri'  => self::REDIRECT_URI,
            'code_verifier' => $verifier,
            'state'         => $state,
        ]);

        $response = wp_remote_post(
            self::TOKEN_ENDPOINT,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent'   => self::USER_AGENT,
                ],
                'body'    => $body,
                'timeout' => 15,
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        // Validate that the granted scope includes user:inference.
        // The 'scope' field is a space-delimited string of granted scopes.
        // Diagnostic: d.get('scope', '').split() should include 'user:inference'.
        if (!empty($data['scope'])) {
            $granted_scopes = explode(' ', (string) $data['scope']);
            if (!in_array(self::REQUIRED_SCOPE, $granted_scopes, true)) {
                return [
                    'scope_error'    => true,
                    'granted_scopes' => $granted_scopes,
                ];
            }
        }

        // Extract accountId from the 'account' object if present.
        // Alignment: Claude CLI's token response contains 'account' => ['uuid' => ..., 'email_address' => ...].
        $account_id = null;
        if (!empty($data['account']) && is_array($data['account'])) {
            $account_id = $data['account']['uuid'] ?? null;
        }

        $result = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];

        // Store in pool, including accountId if available.
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
     * Returns the number of accounts in the pool.
     *
     * @return int
     */
    public function count(): int
    {
        $pool = $this->loadPool();
        return count($pool['accounts'] ?? []);
    }

    /**
     * Returns the current time in milliseconds.
     *
     * @return int
     */
    protected function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Generates a PKCE code_verifier (43 chars, base64url, no padding).
     *
     * @return string
     */
    protected function generateVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generates a PKCE code_challenge from a verifier (S256).
     *
     * @param string $verifier The code verifier.
     * @return string The code challenge.
     */
    protected function generateChallenge(string $verifier): string
    {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Parses the Retry-After header from a wp_remote_* response.
     *
     * Supports both integer (seconds) and HTTP-date formats per RFC 7231.
     * Returns null if the header is absent or unparseable.
     *
     * @param array $response The wp_remote_* response array.
     * @return int|null Retry-After in seconds, or null if not present.
     */
    protected function parseRetryAfter(array $response): ?int
    {
        $header = wp_remote_retrieve_header($response, 'retry-after');
        if (empty($header)) {
            return null;
        }

        // Integer seconds format: "Retry-After: 60"
        if (ctype_digit((string) $header)) {
            return (int) $header;
        }

        // HTTP-date format: "Retry-After: Wed, 21 Oct 2025 07:28:00 GMT"
        $timestamp = strtotime((string) $header);
        if ($timestamp !== false && $timestamp > time()) {
            return $timestamp - time();
        }

        return null;
    }
}
