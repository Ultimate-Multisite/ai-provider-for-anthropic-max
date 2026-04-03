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
     */
    public const CLIENT_ID      = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';
    public const TOKEN_ENDPOINT  = 'https://platform.claude.com/v1/oauth/token';
    public const AUTHORIZE_URL   = 'https://claude.ai/oauth/authorize';
    public const REDIRECT_URI    = 'https://console.anthropic.com/oauth/code/callback';
    public const SCOPES          = 'org:create_api_key user:profile user:inference user:sessions:claude_code user:mcp_servers user:file_upload';

    /**
     * Default cooldown duration for rate-limited accounts (5 minutes).
     */
    public const DEFAULT_COOLDOWN_MS = 300000;

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
            ];
        }, $accounts);
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
     * @param string $email         The account email.
     * @param string $access_token  The OAuth access token.
     * @param string $refresh_token The OAuth refresh token.
     * @param int    $expires_in    Token lifetime in seconds.
     * @return int The total number of accounts in the pool.
     */
    public function addAccount(
        string $email,
        string $access_token,
        string $refresh_token,
        int $expires_in
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
                $found                    = true;
                break;
            }
        }
        unset($account);

        if (!$found) {
            $pool['accounts'][] = [
                'email'         => $email,
                'access'        => $access_token,
                'refresh'       => $refresh_token,
                'expires'       => $expires_ms,
                'added'         => $now,
                'lastUsed'      => $now,
                'status'        => 'active',
                'cooldownUntil' => null,
            ];
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
     * @param string   $email       The account email.
     * @param int|null $cooldown_ms Cooldown in milliseconds (null for default).
     * @return bool Whether the account was found.
     */
    public function markRateLimited(string $email, ?int $cooldown_ms = null): bool
    {
        $pool   = $this->loadPool();
        $now_ms = $this->nowMs();

        if ($cooldown_ms === null) {
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
     * @return array{verifier: string, challenge: string, state: string, authorize_url: string}
     */
    public function startOAuthFlow(): array
    {
        // Generate PKCE code_verifier (43 chars, base64url no padding).
        $verifier  = $this->generateVerifier();
        $challenge = $this->generateChallenge($verifier);
        $state     = bin2hex(random_bytes(24));

        // Store verifier + state in a transient (10 minute TTL).
        set_transient(self::PKCE_TRANSIENT_PREFIX . $state, $verifier, 600);

        $authorize_url = self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id'             => self::CLIENT_ID,
            'response_type'        => 'code',
            'redirect_uri'         => self::REDIRECT_URI,
            'scope'                => self::SCOPES,
            'code_challenge'       => $challenge,
            'code_challenge_method' => 'S256',
            'state'                => $state,
            'code'                 => 'true',
        ]);

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
     * @param string $code  The authorization code.
     * @param string $state The state nonce.
     * @param string $email The account email.
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
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
                    'User-Agent'   => 'ai-provider-for-anthropic-max/1.0.0',
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

        $result = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];

        // Store in pool.
        $this->addAccount($email, $result['access_token'], $result['refresh_token'], $result['expires_in']);

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
}
