<?php
/**
 * Anthropic Max OAuth account pool manager (legacy facade).
 *
 * Since 1.2.0 this class is a thin backwards-compatibility facade over
 * `PoolRegistry::pool('anthropic')` — the actual pool logic lives in
 * `ProviderPool`, and the same logic powers the OpenAI/Cursor/Google
 * pools introduced in 1.2.0. Public methods, constants, and the option
 * key (`anthropic_max_oauth_pool`) are preserved unchanged so existing
 * consumers (auth class, settings UI, REST routes, stored data) keep
 * working without migration.
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
     * WordPress option key for the Anthropic pool data.
     *
     * Kept stable across the 1.0/1.1/1.2 series so upgrades never have
     * to migrate data — `ProviderConfig::forId('anthropic')` references
     * the same key.
     */
    public const OPTION_KEY = 'anthropic_max_oauth_pool';

    /**
     * Transient key prefix for PKCE verifiers.
     *
     * Note: 1.2.0 namespaces verifiers per-provider internally; this
     * constant remains for any external callers but new code should
     * not rely on it.
     */
    public const PKCE_TRANSIENT_PREFIX = 'anthropic_max_pkce_';

    /**
     * Anthropic OAuth constants — preserved for back-compat with code
     * that imports them directly. The canonical source is now
     * `ProviderConfig::forId('anthropic')`.
     */
    public const CLIENT_ID       = '9d1c250a-e61b-44d9-88ed-5944d1962f5e';
    public const TOKEN_ENDPOINT  = 'https://platform.claude.com/v1/oauth/token';
    public const AUTHORIZE_URL   = 'https://claude.ai/oauth/authorize';
    public const REDIRECT_URI    = 'https://console.anthropic.com/oauth/code/callback';
    public const SCOPES          = 'org:create_api_key user:profile user:inference user:sessions:claude_code user:mcp_servers user:file_upload';
    public const USER_AGENT      = 'claude-cli/2.1.80 (wordpress-plugin)';

    /**
     * Default cooldown duration for rate-limited accounts (5 minutes).
     */
    public const DEFAULT_COOLDOWN_MS = ProviderPool::DEFAULT_COOLDOWN_MS;

    /**
     * Required scope that must be granted for a token to be useful.
     */
    public const REQUIRED_SCOPE = 'user:inference';

    /**
     * Return statuses for removeAccount() — see ProviderPool::REMOVE_*.
     */
    public const REMOVE_NOT_FOUND  = ProviderPool::REMOVE_NOT_FOUND;
    public const REMOVE_OK         = ProviderPool::REMOVE_OK;
    public const REMOVE_SAVE_ERROR = ProviderPool::REMOVE_SAVE_ERROR;

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * The underlying generic pool for the 'anthropic' provider.
     */
    private ProviderPool $pool;

    private function __construct()
    {
        $this->pool = PoolRegistry::pool('anthropic');
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Resets the singleton — for test use only.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function loadPool(): array
    {
        return $this->pool->loadPool();
    }

    public function savePool(array $pool): bool
    {
        return $this->pool->savePool($pool);
    }

    public function listAccounts(): array
    {
        return $this->pool->listAccounts();
    }

    public function getActiveTokenWithEmail(): ?array
    {
        return $this->pool->getActiveTokenWithEmail();
    }

    public function getActiveToken(): ?string
    {
        return $this->pool->getActiveToken();
    }

    public function addAccount(
        string $email,
        string $access_token,
        string $refresh_token,
        int $expires_in,
        ?string $account_id = null
    ): int {
        return $this->pool->addAccount($email, $access_token, $refresh_token, $expires_in, $account_id);
    }

    public function removeAccount(string $email): string
    {
        return $this->pool->removeAccount($email);
    }

    public function markRateLimited(string $email, ?int $cooldown_ms = null, ?int $retry_after_secs = null): bool
    {
        return $this->pool->markRateLimited($email, $cooldown_ms, $retry_after_secs);
    }

    /**
     * Marks the account as rate-limited based on a wp_remote_* response.
     *
     * @param string $email
     * @param array<string,mixed>  $response
     * @return bool
     */
    public function markRateLimitedFromResponse(string $email, array $response): bool
    {
        $retry_after_secs = $this->parseRetryAfter($response);
        return $this->markRateLimited($email, null, $retry_after_secs);
    }

    public function refreshAccount(string $email): bool
    {
        return $this->pool->refreshAccount($email);
    }

    public function healthCheck(): array
    {
        return $this->pool->healthCheck();
    }

    /**
     * Starts the Anthropic OAuth flow.
     *
     * @param string|null $login_hint
     * @param string|null $login_method
     * @param string|null $org_uuid Accepted for compatibility; not used.
     * @return array{verifier:string,challenge:string,state:string,authorize_url:string}
     */
    public function startOAuthFlow(
        ?string $login_hint = null,
        ?string $login_method = null,
        ?string $org_uuid = null
    ): array {
        // org_uuid is intentionally ignored — claude.ai's authorize endpoint
        // doesn't support it. Kept in the signature for back-compat.
        unset($org_uuid);
        return $this->pool->startOAuthFlow($login_hint, $login_method);
    }

    public function exchangeCode(string $code, string $state, string $email): ?array
    {
        return $this->pool->exchangeCode($code, $state, $email);
    }

    public function count(): int
    {
        return $this->pool->count();
    }

    /**
     * Parses the Retry-After header from a wp_remote_* response.
     *
     * Preserved here (not in ProviderPool) because callers passing a
     * wp_remote response array — rather than an int — predate 1.2.0.
     *
     * @param array<string,mixed> $response
     * @return int|null
     */
    protected function parseRetryAfter(array $response): ?int
    {
        $header = wp_remote_retrieve_header($response, 'retry-after');
        if (empty($header)) {
            return null;
        }

        if (ctype_digit((string) $header)) {
            return (int) $header;
        }

        $timestamp = strtotime((string) $header);
        if ($timestamp !== false && $timestamp > time()) {
            return $timestamp - time();
        }

        return null;
    }
}
