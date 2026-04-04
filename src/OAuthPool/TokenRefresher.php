<?php
/**
 * OAuth token refresher.
 *
 * Handles refreshing expired OAuth access tokens using the refresh token
 * grant type against the Anthropic token endpoint.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\OAuthPool;

/**
 * Refreshes expired OAuth tokens.
 *
 * @since 1.0.0
 */
class TokenRefresher
{
    /**
     * Refreshes an access token using a refresh token.
     *
     * @param string $refresh_token The refresh token.
     * @return array{access_token: string, refresh_token: string, expires_in: int}|null
     *         The new token data, or null on failure.
     */
    public function refresh(string $refresh_token): ?array
    {
        if (empty($refresh_token)) {
            return null;
        }

        $body = wp_json_encode([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => PoolManager::CLIENT_ID,
        ]);

        $response = wp_remote_post(
            PoolManager::TOKEN_ENDPOINT,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent'   => PoolManager::USER_AGENT,
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

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? $refresh_token,
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];
    }
}
