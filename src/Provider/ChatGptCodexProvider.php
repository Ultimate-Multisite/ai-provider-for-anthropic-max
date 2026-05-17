<?php
/**
 * ChatGPT Codex (OAuth) provider for the WordPress AI Client.
 *
 * Registers as a separate provider (`ultimate-ai-connector-chatgpt-codex`)
 * so it can coexist with the standard API-key-based OpenAI provider.
 * Backed by ChatGPT Plus/Pro/Team OAuth tokens stored in the OAuth
 * account pool — these tokens are accepted ONLY by the Codex backend
 * (`chatgpt.com/backend-api/codex`) and rejected by `api.openai.com`,
 * which is why this provider exists as a distinct class.
 *
 * @since 1.2.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use AnthropicMaxAiProvider\Metadata\ChatGptCodexModelMetadataDirectory;
use AnthropicMaxAiProvider\Models\ChatGptCodexTextGenerationModel;

/**
 * Provider class for ChatGPT Codex (OAuth-based, ChatGPT Plus/Pro/Team).
 *
 * @since 1.2.0
 */
class ChatGptCodexProvider extends AbstractApiProvider
{
    /**
     * Returns the base URL for the Codex backend.
     *
     * Codex is hosted at chatgpt.com, NOT api.openai.com. ChatGPT-Account
     * OAuth tokens are rejected by api.openai.com with "Missing scopes"
     * errors and are only accepted by this Codex endpoint.
     *
     * @since 1.2.0
     *
     * @return string
     */
    protected static function baseUrl(): string
    {
        return 'https://chatgpt.com/backend-api/codex';
    }

    /**
     * Creates a model instance from metadata.
     *
     * Only text generation is supported via the Codex backend.
     *
     * @since 1.2.0
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
                return new ChatGptCodexTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * Creates the provider metadata.
     *
     * Uses `ultimate-ai-connector-chatgpt-codex` as the id, matching the
     * naming convention of sibling Ultimate-Multisite connector plugins
     * (`ultimate-ai-connector-anthropic-max`, `ultimate-ai-connector-webllm`).
     * Distinct from the stock `openai` id so this provider does not
     * collide with the API-key OpenAI provider.
     *
     * @since 1.2.0
     *
     * @return ProviderMetadata
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            'ultimate-ai-connector-chatgpt-codex',
            'ChatGPT Codex',
            ProviderTypeEnum::cloud(),
            null,
            RequestAuthenticationMethod::apiKey(),
        ];

        // Provider description support was added in AI Client SDK 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            if (function_exists('__')) {
                $args[] = __(
                    'Text generation via ChatGPT Plus/Pro/Team OAuth on the Codex backend (chatgpt.com).',
                    'ai-provider-for-anthropic-max'
                );
            } else {
                $args[] = 'Text generation via ChatGPT Plus/Pro/Team OAuth on the Codex backend (chatgpt.com).';
            }
        }

        return new ProviderMetadata(...$args);
    }

    /**
     * Creates the provider availability checker.
     *
     * Re-uses the SDK's list-models availability check; for Codex this
     * resolves against the hardcoded model directory (no HTTP call), so
     * the provider is "configured" whenever the OpenAI OAuth pool has
     * at least one entry — which is also the gate
     * {@see \AnthropicMaxAiProvider\Registration\register_provider()}
     * uses before registering this class.
     *
     * @since 1.2.0
     *
     * @return ProviderAvailabilityInterface
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new \WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * Creates the model metadata directory.
     *
     * @since 1.2.0
     *
     * @return ModelMetadataDirectoryInterface
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new ChatGptCodexModelMetadataDirectory();
    }
}
