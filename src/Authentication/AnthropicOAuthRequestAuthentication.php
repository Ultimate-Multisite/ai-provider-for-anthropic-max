<?php
/**
 * OAuth Bearer token authentication for Anthropic Max subscriptions.
 *
 * Replaces the standard x-api-key header with an OAuth Bearer token
 * and includes the required anthropic-beta header for OAuth access.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Authentication;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use AnthropicMaxAiProvider\OAuthPool\PoolManager;

/**
 * Authenticates HTTP requests using an OAuth Bearer token from the account pool.
 *
 * @since 1.0.0
 */
class AnthropicOAuthRequestAuthentication implements RequestAuthenticationInterface
{
    public const ANTHROPIC_API_VERSION = '2023-06-01';
    public const ANTHROPIC_OAUTH_BETA  = 'oauth-2025-04-20';

    /**
     * The pool manager instance.
     *
     * @var PoolManager
     */
    private PoolManager $pool;

    /**
     * Constructor.
     *
     * @param PoolManager $pool The pool manager to retrieve tokens from.
     */
    public function __construct(PoolManager $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Authenticates the request with an OAuth Bearer token.
     *
     * Retrieves the best available token from the pool, auto-refreshing
     * expired tokens as needed.
     *
     * @since 1.0.0
     *
     * @param Request $request The request to authenticate.
     * @return Request The authenticated request.
     *
     * @throws \RuntimeException If no active token is available in the pool.
     */
    public function authenticateRequest(Request $request): Request
    {
        $token = $this->pool->getActiveToken();

        if (empty($token)) {
            throw new \RuntimeException(
                'No active OAuth token available in the Anthropic Max account pool. ' .
                'Add an account via Settings > Connectors.'
            );
        }

        $request = $request->withHeader('anthropic-version', self::ANTHROPIC_API_VERSION);
        $request = $request->withHeader('anthropic-beta', self::ANTHROPIC_OAUTH_BETA);

        return $request->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * Returns the API key string.
     *
     * Provided for compatibility with SDK internals that may call getApiKey().
     * Returns the current Bearer token.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->pool->getActiveToken() ?? '';
    }
}
