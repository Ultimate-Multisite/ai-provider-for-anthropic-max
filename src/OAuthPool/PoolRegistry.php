<?php
/**
 * Registry for per-provider OAuth pools.
 *
 * Returns memoised `ProviderPool` instances for each supported provider id
 * (anthropic, openai, google). Acts as the single entry point used
 * by the REST API and provider integration layer.
 *
 * @since 1.2.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\OAuthPool;

/**
 * Provides access to per-provider pool instances.
 *
 * @since 1.2.0
 */
class PoolRegistry
{
    /**
     * Memoised pool instances, keyed by provider id.
     *
     * @var array<string,ProviderPool>
     */
    private static array $pools = [];

    /**
     * Returns the pool for the given provider id.
     *
     * @param string $id One of 'anthropic'|'openai'|'google'.
     * @return ProviderPool
     * @throws \InvalidArgumentException If the provider id is unknown.
     */
    public static function pool(string $id): ProviderPool
    {
        if (!isset(self::$pools[$id])) {
            self::$pools[$id] = new ProviderPool(ProviderConfig::forId($id));
        }
        return self::$pools[$id];
    }

    /**
     * Returns the list of supported provider ids.
     *
     * @return string[]
     */
    public static function supportedIds(): array
    {
        return ProviderConfig::supportedIds();
    }

    /**
     * Returns aggregate statistics across all provider pools.
     *
     * @return array<string,array{label:string,count:int,supportsOAuth:bool}>
     */
    public static function summary(): array
    {
        $out = [];
        foreach (self::supportedIds() as $id) {
            $pool = self::pool($id);
            $cfg  = $pool->getConfig();
            $out[$id] = [
                'label'         => $cfg->label,
                'count'         => $pool->count(),
                'supportsOAuth' => $cfg->supportsOAuth,
            ];
        }
        return $out;
    }

    /**
     * Resets the memoised instances. For test use only.
     */
    public static function reset(): void
    {
        self::$pools = [];
    }
}
