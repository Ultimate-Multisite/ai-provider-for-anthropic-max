<?php
/**
 * Model metadata directory for Anthropic Max.
 *
 * Discovers available models from the Anthropic API using OAuth
 * Bearer token authentication.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use AnthropicMaxAiProvider\Authentication\AnthropicOAuthRequestAuthentication;
use AnthropicMaxAiProvider\Provider\AnthropicMaxProvider;

/**
 * Model metadata directory for Anthropic Max.
 *
 * @since 1.0.0
 *
 * @phpstan-type ModelsResponseData array{
 *     data: list<array{id: string, display_name?: string}>
 * }
 */
class AnthropicMaxModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Returns the request authentication, using our OAuth class.
     *
     * @since 1.0.0
     *
     * @return RequestAuthenticationInterface
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        $requestAuthentication = parent::getRequestAuthentication();

        // If the SDK resolved our OAuth auth, use it directly.
        if ($requestAuthentication instanceof AnthropicOAuthRequestAuthentication) {
            return $requestAuthentication;
        }

        // Fallback: wrap an API key auth in our OAuth auth via the pool manager.
        $pool = \AnthropicMaxAiProvider\OAuthPool\PoolManager::getInstance();
        return new AnthropicOAuthRequestAuthentication($pool);
    }

    /**
     * Creates a request targeting the Anthropic Max provider URL.
     *
     * @since 1.0.0
     *
     * @param HttpMethodEnum $method  HTTP method.
     * @param string         $path    API path.
     * @param array          $headers Request headers.
     * @param mixed          $data    Request data.
     * @return Request
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            AnthropicMaxProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * Parses the model list response from the Anthropic API.
     *
     * @since 1.0.0
     *
     * @param Response $response The API response.
     * @return list<ModelMetadata> The parsed model metadata list.
     *
     * @throws ResponseException If the response is malformed.
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData('Anthropic Max', 'data');
        }

        $capabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];

        $baseOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::topK()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(
                OptionEnum::inputModalities(),
                [
                    [ModalityEnum::text()],
                    [ModalityEnum::text(), ModalityEnum::image()],
                    [ModalityEnum::text(), ModalityEnum::document()],
                    [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document()],
                ]
            ),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];

        $webSearchOptions = array_merge($baseOptions, [
            new SupportedOption(OptionEnum::webSearch()),
        ]);

        $modelsData = (array) $responseData['data'];

        $models = array_values(
            array_map(
                static function (array $modelData) use ($capabilities, $baseOptions, $webSearchOptions): ModelMetadata {
                    $modelId   = $modelData['id'];
                    $modelName = $modelData['display_name'] ?? $modelId;

                    // Models newer than Claude 3 support web search.
                    $options = !preg_match('/^claude-3-[a-z]+/', $modelId)
                        ? $webSearchOptions
                        : $baseOptions;

                    return new ModelMetadata($modelId, $modelName, $capabilities, $options);
                },
                $modelsData
            )
        );

        usort($models, [$this, 'modelSortCallback']);

        return $models;
    }

    /**
     * Sorts models by relevance: newer and flagship models first.
     *
     * @since 1.0.0
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $aId = $a->getId();
        $bId = $b->getId();

        // Prefer Claude models over non-Claude models.
        if (str_starts_with($aId, 'claude-') && !str_starts_with($bId, 'claude-')) {
            return -1;
        }
        if (str_starts_with($bId, 'claude-') && !str_starts_with($aId, 'claude-')) {
            return 1;
        }

        // Prefer non-versioned names (e.g. 'claude-sonnet-4') over versioned (e.g. 'claude-3-5-sonnet').
        if (!preg_match('/^claude-\d/', $aId) && preg_match('/^claude-\d/', $bId)) {
            return -1;
        }
        if (!preg_match('/^claude-\d/', $bId) && preg_match('/^claude-\d/', $aId)) {
            return 1;
        }

        // Sort by version and type for matched patterns.
        $aMatch = preg_match('/^claude-([a-z]+)-(\d(-\d)?)(-[0-9]+)?$/', $aId, $aMatches);
        $bMatch = preg_match('/^claude-([a-z]+)-(\d(-\d)?)(-[0-9]+)?$/', $bId, $bMatches);
        if ($aMatch && !$bMatch) {
            return -1;
        }
        if ($bMatch && !$aMatch) {
            return 1;
        }
        if ($aMatch && $bMatch) {
            $aVersion = str_replace('-', '.', $aMatches[2]);
            $bVersion = str_replace('-', '.', $bMatches[2]);
            if (version_compare($aVersion, $bVersion, '>')) {
                return -1;
            }
            if (version_compare($bVersion, $aVersion, '>')) {
                return 1;
            }

            // Prefer base models over date-suffixed.
            if (!isset($aMatches[4]) && isset($bMatches[4])) {
                return -1;
            }
            if (!isset($bMatches[4]) && isset($aMatches[4])) {
                return 1;
            }

            // Prefer sonnet over other types.
            if ($aMatches[1] === 'sonnet' && $bMatches[1] !== 'sonnet') {
                return -1;
            }
            if ($bMatches[1] === 'sonnet' && $aMatches[1] !== 'sonnet') {
                return 1;
            }

            // Prefer later release dates.
            if (isset($aMatches[4], $bMatches[4])) {
                $aDate = (int) substr($aMatches[4], 1);
                $bDate = (int) substr($bMatches[4], 1);
                if ($aDate > $bDate) {
                    return -1;
                }
                if ($bDate > $aDate) {
                    return 1;
                }
            }
        }

        return strcmp($aId, $bId);
    }
}
