<?php
/**
 * OAuth Bearer token authentication for OpenAI ChatGPT-Account tokens
 * targeting the Codex backend (`chatgpt.com/backend-api/codex`).
 *
 * The ChatGPT OAuth token is NOT a standard OpenAI API key — it cannot
 * be used against `api.openai.com` and instead requires the Codex
 * backend with two extra headers: `chatgpt-account-id` and `Originator`.
 *
 * @since 1.2.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Authentication;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use AnthropicMaxAiProvider\OAuthPool\ProviderPool;

/**
 * Authenticates HTTP requests against the ChatGPT Codex backend using
 * a Bearer access token, account id, and Originator string drawn from
 * the per-provider OAuth account pool.
 *
 * @since 1.2.0
 */
class ChatGptCodexOAuthRequestAuthentication extends ApiKeyRequestAuthentication
{
    /**
     * Default Originator string sent on each Codex request. Codex uses
     * this for analytics/quota attribution; reusing the upstream
     * `opencode` identifier keeps us inside the same allow-list bucket
     * proven to work in live tests.
     *
     * Override via the `anthropic_max_chatgpt_codex_originator` filter
     * if a future Codex deployment requires a different value.
     */
    public const DEFAULT_ORIGINATOR = 'opencode';

    /**
     * The OpenAI pool from which to draw Bearer tokens.
     *
     * @var ProviderPool
     */
    private ProviderPool $pool;

    /**
     * The email of the account whose token was most recently used.
     *
     * Populated by {@see self::authenticateRequest()} so the calling
     * model can mark this account as rate-limited on 429/529.
     *
     * @var string|null
     */
    private ?string $activeEmail = null;

    /**
     * The ChatGPT account id of the most recently used account.
     *
     * @var string|null
     */
    private ?string $activeAccountId = null;

    /**
     * Constructor.
     *
     * @param ProviderPool $pool The OpenAI pool to retrieve tokens from.
     */
    public function __construct(ProviderPool $pool)
    {
        $this->pool = $pool;
        // Parent constructor requires a non-null string; real Bearer
        // tokens are resolved per-request from the pool.
        parent::__construct('');
    }

    /**
     * Authenticates the request with an OAuth Bearer token plus the
     * Codex backend's required `chatgpt-account-id` and `Originator`
     * headers.
     *
     * @since 1.2.0
     *
     * @param Request $request The request to authenticate.
     * @return Request The authenticated request.
     *
     * @throws \RuntimeException If no usable token is available.
     */
    public function authenticateRequest(Request $request): Request
    {
        $this->activeEmail     = null;
        $this->activeAccountId = null;

        $meta = $this->pool->getActiveTokenWithMeta();
        if ($meta === null || empty($meta['token'])) {
            throw new \RuntimeException(
                'No active OpenAI ChatGPT OAuth token available in the account pool. ' .
                'Add an account via Settings > Connectors > OpenAI ChatGPT (OAuth).'
            );
        }

        if (empty($meta['accountId'])) {
            throw new \RuntimeException(
                'OpenAI account is missing chatgpt_account_id (required for Codex backend). ' .
                'Remove and re-add the account to capture the id from the OAuth response.'
            );
        }

        $this->activeEmail     = $meta['email'];
        $this->activeAccountId = $meta['accountId'];

        $originator = (string) apply_filters(
            'anthropic_max_chatgpt_codex_originator',
            self::DEFAULT_ORIGINATOR
        );

        $request = $request->withHeader('Authorization', 'Bearer ' . $meta['token']);
        $request = $request->withHeader('chatgpt-account-id', $meta['accountId']);
        $request = $request->withHeader('Originator', $originator);

        return $request;
    }

    /**
     * Returns the email of the account whose token was last used.
     *
     * @since 1.2.0
     *
     * @return string|null
     */
    public function getActiveEmail(): ?string
    {
        return $this->activeEmail;
    }

    /**
     * Returns the ChatGPT account id of the account whose token was
     * last used.
     *
     * @since 1.2.0
     *
     * @return string|null
     */
    public function getActiveAccountId(): ?string
    {
        return $this->activeAccountId;
    }

    /**
     * Returns the Bearer token string. Provided for SDK internals that
     * call {@see ApiKeyRequestAuthentication::getApiKey()} directly.
     *
     * @since 1.2.0
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->pool->getActiveToken() ?? '';
    }

    /**
     * Returns a JSON schema describing this authentication DTO. OAuth
     * tokens are sourced from the pool at runtime, so no user-facing
     * fields are exposed.
     *
     * @since 1.2.0
     *
     * @return array<string, mixed>
     */
    public static function getJsonSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [],
        ];
    }
}
