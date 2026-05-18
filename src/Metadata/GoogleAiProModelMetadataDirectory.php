<?php
/**
 * Model metadata directory for Google AI Pro.
 *
 * Discovers available Gemini models from the Google Generative Language API
 * using OAuth Bearer token authentication. Mirrors the model-discovery
 * approach used by the canonical
 * `WordPress\GoogleAiProvider\Metadata\GoogleModelMetadataDirectory` from
 * github.com/WordPress/ai-provider-for-google, with two differences:
 *
 *   1. Authentication is OAuth bearer (subscription billing) instead of
 *      API key (Cloud project billing).
 *   2. Imagen models are excluded — the Imagen API does not accept OAuth
 *      bearer tokens with the `cloud-platform` scope alone, so we surface
 *      only Gemini (`generateContent`) models here. The canonical
 *      `ai-provider-for-google` plugin remains the recommended path for
 *      API-key-billed Imagen usage.
 *
 * @since 1.3.0
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
use AnthropicMaxAiProvider\Authentication\GoogleOAuthRequestAuthentication;
use AnthropicMaxAiProvider\OAuthPool\PoolRegistry;
use AnthropicMaxAiProvider\Provider\GoogleAiProProvider;

/**
 * Model metadata directory for Google AI Pro.
 *
 * @since 1.3.0
 *
 * @phpstan-type ModelsResponseData array{
 *     models?: array<int, array<string, mixed>>
 * }
 */
class GoogleAiProModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Returns the request authentication, using our OAuth class.
     *
     * @since 1.3.0
     *
     * @return RequestAuthenticationInterface
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        $requestAuthentication = parent::getRequestAuthentication();

        // If the SDK already resolved our OAuth auth, use it directly.
        if ($requestAuthentication instanceof GoogleOAuthRequestAuthentication) {
            return $requestAuthentication;
        }

        // Fallback: build OAuth auth from the Google provider pool.
        return new GoogleOAuthRequestAuthentication(PoolRegistry::pool('google'));
    }

    /**
     * Creates a request targeting the Google AI Pro provider URL.
     *
     * Mirrors the canonical Google plugin's approach of forcing
     * `pageSize=1000` on the `models` endpoint so the full catalog is
     * returned in one request.
     *
     * @since 1.3.0
     *
     * @param HttpMethodEnum                $method  HTTP method.
     * @param string                        $path    API path.
     * @param array<string, string>         $headers Request headers.
     * @param array<string, mixed>|null     $data    Request data.
     * @return Request
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        if ($path === 'models' && $data === null) {
            $data = ['pageSize' => 1000];
        }
        return new Request(
            $method,
            GoogleAiProProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * Parses the model list response from the Google Generative Language API.
     *
     * Only models that advertise `generateContent` support are surfaced.
     * Multimodal output (image-generation) Gemini variants are also dropped
     * because the OAuth bearer flow currently exposes text generation only.
     *
     * @since 1.3.0
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
        if (!isset($responseData['models']) || !$responseData['models']) {
            throw ResponseException::fromMissingData('Google AI Pro', 'models');
        }

        $allModalityCombinationsWithText = [
            [ModalityEnum::text()],
            [ModalityEnum::text(), ModalityEnum::image()],
            [ModalityEnum::text(), ModalityEnum::audio()],
            [ModalityEnum::text(), ModalityEnum::document()],
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio()],
            [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document()],
            [ModalityEnum::text(), ModalityEnum::audio(), ModalityEnum::document()],
            [
                ModalityEnum::text(),
                ModalityEnum::image(),
                ModalityEnum::audio(),
                ModalityEnum::document(),
            ],
        ];

        $geminiCapabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];

        $geminiOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::topK()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::logprobs()),
            new SupportedOption(OptionEnum::topLogprobs()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), $allModalityCombinationsWithText),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::webSearch()),
        ];

        $modelsData = (array) $responseData['models'];

        $models = [];
        foreach ($modelsData as $modelData) {
            if (!is_array($modelData)) {
                continue;
            }

            $modelId = $modelData['baseModelId'] ?? $modelData['name'] ?? '';
            if (!is_string($modelId) || $modelId === '') {
                continue;
            }
            if (str_starts_with($modelId, 'models/')) {
                $modelId = substr($modelId, 7);
            }

            $methods = $modelData['supportedGenerationMethods'] ?? [];
            if (!is_array($methods) || !in_array('generateContent', $methods, true)) {
                // Skip non-generateContent models (Imagen, embeddings, etc.).
                continue;
            }

            // Skip multimodal image-output Gemini variants — OAuth bearer
            // with cloud-platform scope does not currently expose Imagen-style
            // generation through generativelanguage.googleapis.com.
            if (
                str_ends_with($modelId, '-image') ||
                str_ends_with($modelId, '-image-preview') ||
                str_ends_with($modelId, '-image-generation') ||
                str_starts_with($modelId, 'gemini-2.0-flash-exp')
            ) {
                continue;
            }

            $modelName = $modelData['displayName'] ?? $modelId;

            $models[] = new ModelMetadata(
                $modelId,
                is_string($modelName) ? $modelName : $modelId,
                $geminiCapabilities,
                $geminiOptions
            );
        }

        usort($models, [$this, 'modelSortCallback']);

        return $models;
    }

    /**
     * Callback function for sorting Gemini models by relevance.
     *
     * Same heuristic as the canonical
     * `ai-provider-for-google::GoogleModelMetadataDirectory::modelSortCallback()`:
     * non-experimental over experimental, non-preview over preview,
     * newest version first, `-pro` over `-flash` for matched patterns.
     *
     * @since 1.3.0
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $aId = $a->getId();
        $bId = $b->getId();

        // Prefer non-experimental models over experimental models.
        if (str_contains($aId, '-exp') && !str_contains($bId, '-exp')) {
            return 1;
        }
        if (str_contains($bId, '-exp') && !str_contains($aId, '-exp')) {
            return -1;
        }

        // Prefer non-preview models over preview models.
        if (str_contains($aId, '-preview') && !str_contains($bId, '-preview')) {
            return 1;
        }
        if (str_contains($bId, '-preview') && !str_contains($aId, '-preview')) {
            return -1;
        }

        // Prefer Gemini models over non-Gemini.
        if (str_starts_with($aId, 'gemini-') && !str_starts_with($bId, 'gemini-')) {
            return -1;
        }
        if (str_starts_with($bId, 'gemini-') && !str_starts_with($aId, 'gemini-')) {
            return 1;
        }

        // Prefer Gemini models with version numbers (e.g. 'gemini-2.5', 'gemini-2.0').
        $aMatch = preg_match('/^gemini-([0-9.]+)(-[a-z0-9-]+)$/', $aId, $aMatches);
        $bMatch = preg_match('/^gemini-([0-9.]+)(-[a-z0-9-]+)$/', $bId, $bMatches);
        if ($aMatch && !$bMatch) {
            return -1;
        }
        if ($bMatch && !$aMatch) {
            return 1;
        }
        if ($aMatch && $bMatch) {
            $aVersion = $aMatches[1];
            $bVersion = $bMatches[1];
            if (version_compare($aVersion, $bVersion, '>')) {
                return -1;
            }
            if (version_compare($bVersion, $aVersion, '>')) {
                return 1;
            }

            // Prefer '-pro' over other suffixes.
            if ($aMatches[2] === '-pro' && $bMatches[2] !== '-pro') {
                return -1;
            }
            if ($bMatches[2] === '-pro' && $aMatches[2] !== '-pro') {
                return 1;
            }

            // Prefer '-flash' over other suffixes.
            if ($aMatches[2] === '-flash' && $bMatches[2] !== '-flash') {
                return -1;
            }
            if ($bMatches[2] === '-flash' && $aMatches[2] !== '-flash') {
                return 1;
            }
        }

        return strcmp($aId, $bId);
    }
}
