<?php
/**
 * Anthropic Max provider for the WordPress AI Client.
 *
 * Registers as a separate provider ('anthropic-max') so it can coexist
 * with the standard API-key-based Anthropic provider.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use AnthropicMaxAiProvider\Metadata\AnthropicMaxModelMetadataDirectory;
use AnthropicMaxAiProvider\Models\AnthropicMaxTextGenerationModel;

/**
 * Provider class for Anthropic Max (OAuth-based).
 *
 * @since 1.0.0
 */
class AnthropicMaxProvider extends AbstractApiProvider
{
    /**
     * Returns the base URL for the Anthropic API.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected static function baseUrl(): string
    {
        return 'https://api.anthropic.com/v1';
    }

    /**
     * Creates a model instance from metadata.
     *
     * @since 1.0.0
     *
     * @param ModelMetadata    $modelMetadata    The model metadata.
     * @param ProviderMetadata $providerMetadata The provider metadata.
     * @return ModelInterface The model instance.
     *
     * @throws RuntimeException If the model capabilities are unsupported.
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                return new AnthropicMaxTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * Creates the provider metadata.
     *
     * Uses 'anthropic-max' as the provider ID to avoid conflicts
     * with the standard API-key-based Anthropic provider.
     *
     * @since 1.0.0
     *
     * @return ProviderMetadata
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            'anthropic-max',
            'Anthropic Max',
            ProviderTypeEnum::cloud(),
            null,
            RequestAuthenticationMethod::apiKey(),
        ];

        // Provider description support was added in AI Client SDK 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            if (function_exists('__')) {
                $args[] = __('Text generation with Claude via Max subscription.', 'ai-provider-for-anthropic-max');
            } else {
                $args[] = 'Text generation with Claude via Max subscription.';
            }
        }

        return new ProviderMetadata(...$args);
    }

    /**
     * Creates the provider availability checker.
     *
     * @since 1.0.0
     *
     * @return ProviderAvailabilityInterface
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * Creates the model metadata directory.
     *
     * @since 1.0.0
     *
     * @return ModelMetadataDirectoryInterface
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new AnthropicMaxModelMetadataDirectory();
    }
}
