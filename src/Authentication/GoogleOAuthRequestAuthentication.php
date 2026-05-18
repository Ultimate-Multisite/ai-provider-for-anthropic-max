<?php
/**
 * OAuth Bearer token authentication for Google AI Pro subscriptions.
 *
 * Replaces the standard `X-Goog-Api-Key` header used by the canonical
 * `ai-provider-for-google` plugin with an `Authorization: Bearer` header
 * sourced from the Google OAuth account pool. Mirrors the architecture of
 * AnthropicOAuthRequestAuthentication and ChatGptCodexOAuthRequestAuthentication.
 *
 * @since 1.3.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Authentication;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use AnthropicMaxAiProvider\OAuthPool\ProviderPool;

/**
 * Authenticates HTTP requests to Google's Generative Language API using an
 * OAuth Bearer token from the account pool.
 *
 * The canonical Google plugin uses `X-Goog-Api-Key: <api-key>` because that
 * plugin targets the API-key auth method. For OAuth-based subscription
 * billing (AI Pro / Ultra / Workspace) we must use Bearer auth so Google
 * routes the request through the subscription quota instead of project
 * billing.
 *
 * @since 1.3.0
 */
class GoogleOAuthRequestAuthentication extends ApiKeyRequestAuthentication
{
    /**
     * The Google provider pool.
     *
     * @var ProviderPool
     */
    private ProviderPool $pool;

    /**
     * The email of the account whose token was last used.
     *
     * Populated by authenticateRequest() so callers can identify which
     * account to mark as rate-limited on a 429 response.
     *
     * @var string|null
     */
    private ?string $activeEmail = null;

    /**
     * Constructor.
     *
     * @param ProviderPool $pool The Google provider pool.
     */
    public function __construct(ProviderPool $pool)
    {
        $this->pool = $pool;
        // Parent constructor requires a string; real token is resolved per-request.
        parent::__construct('');
    }

    /**
     * Authenticates the request with an OAuth Bearer token.
     *
     * Retrieves the best available token from the pool, auto-refreshing
     * expired tokens as needed. Caches the active account email so callers
     * can mark it as rate-limited on a 429 response.
     *
     * @since 1.3.0
     *
     * @param Request $request The request to authenticate.
     * @return Request The authenticated request.
     *
     * @throws \RuntimeException If no active token is available in the pool.
     */
    public function authenticateRequest(Request $request): Request
    {
        $this->activeEmail = null;
        $result = $this->pool->getActiveTokenWithEmail();

        if ($result === null || empty($result['token'])) {
            throw new \RuntimeException(
                'No active OAuth token available in the Google AI Pro account pool. ' .
                'Add an account via Settings > Connectors.'
            );
        }

        $this->activeEmail = $result['email'];

        return $request->withHeader('Authorization', 'Bearer ' . $result['token']);
    }

    /**
     * Returns the email of the account whose token was last used.
     *
     * Returns null if authenticateRequest() has not been called yet.
     *
     * @since 1.3.0
     *
     * @return string|null
     */
    public function getActiveEmail(): ?string
    {
        return $this->activeEmail;
    }

    /**
     * Returns the API key string.
     *
     * Provided for compatibility with SDK internals that may call getApiKey().
     * Returns the current Bearer token.
     *
     * @since 1.3.0
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->pool->getActiveToken() ?? '';
    }

    /**
     * Returns a JSON schema describing this authentication DTO.
     *
     * Required by WithJsonSchemaInterface. OAuth tokens are sourced from the
     * account pool at runtime, so no user-facing fields are exposed.
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
