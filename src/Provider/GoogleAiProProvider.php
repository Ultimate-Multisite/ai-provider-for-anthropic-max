<?php
/**
 * Google AI Pro provider for the WordPress AI Client.
 *
 * Registers as a separate provider ('ultimate-ai-connector-google-ai-pro')
 * so it can coexist with the canonical API-key-based
 * `ai-provider-for-google` plugin. Authentication is supplied by
 * GoogleOAuthRequestAuthentication using tokens rotated from the
 * Google account pool (ProviderPool 'google').
 *
 * Mirrors the canonical
 * `WordPress\GoogleAiProvider\Provider\GoogleProvider` from
 * github.com/WordPress/ai-provider-for-google so model discovery and
 * generation use the same endpoints and request shapes, with auth
 * swapped from API key to OAuth bearer.
 *
 * @since 1.3.0
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
use AnthropicMaxAiProvider\Metadata\GoogleAiProModelMetadataDirectory;
use AnthropicMaxAiProvider\Models\GoogleAiProTextGenerationModel;

/**
 * Provider class for Google AI Pro (OAuth-based).
 *
 * @since 1.3.0
 */
class GoogleAiProProvider extends AbstractApiProvider
{
    /**
     * Returns the base URL for the Google Generative Language API.
     *
     * Same endpoint as the canonical `ai-provider-for-google` plugin; only
     * the auth header differs (Bearer vs X-Goog-Api-Key).
     *
     * @since 1.3.0
     *
     * @return string
     */
    protected static function baseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    /**
     * Creates a model instance from metadata.
     *
     * Currently only text generation is wired through OAuth. Image
     * generation (Imagen) requires Cloud project billing and the
     * `cloud-platform` scope is not sufficient on its own, so we route
     * only text generation here. Multimodal text+image models surfaced
     * by the API are exposed as text-only by `parseResponseToModelMetadataList`.
     *
     * @since 1.3.0
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
                return new GoogleAiProTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * Creates the provider metadata.
     *
     * Uses 'ultimate-ai-connector-google-ai-pro' as the provider ID to
     * avoid collision with the canonical 'google' id used by
     * `ai-provider-for-google`. This matches the JS-side `slug` so the
     * WP Connectors page renders one card per provider.
     *
     * @since 1.3.0
     *
     * @return ProviderMetadata
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            'ultimate-ai-connector-google-ai-pro',
            'Google AI Pro',
            ProviderTypeEnum::cloud(),
            null,
            RequestAuthenticationMethod::apiKey(),
        ];

        // Provider description support was added in AI Client SDK 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            if (function_exists('__')) {
                $args[] = __(
                    'Text generation with Gemini via Google AI Pro/Ultra or Workspace subscription.',
                    'ai-provider-for-anthropic-max'
                );
            } else {
                $args[] = 'Text generation with Gemini via Google AI Pro/Ultra or Workspace subscription.';
            }
        }

        return new ProviderMetadata(...$args);
    }

    /**
     * Creates the provider availability checker.
     *
     * @since 1.3.0
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
     * @since 1.3.0
     *
     * @return ModelMetadataDirectoryInterface
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new GoogleAiProModelMetadataDirectory();
    }
}
