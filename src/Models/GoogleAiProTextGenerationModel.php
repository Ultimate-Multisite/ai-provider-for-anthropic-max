<?php
/**
 * Google AI Pro text generation model.
 *
 * Handles the Google Generative Language API `generateContent` shape with
 * OAuth Bearer token authentication. Closely mirrors the canonical
 * `WordPress\GoogleAiProvider\Models\GoogleTextGenerationModel` from
 * github.com/WordPress/ai-provider-for-google (Apache-2.0); the differences
 * are:
 *
 *   - Authentication uses GoogleOAuthRequestAuthentication (Bearer token
 *     from the account pool) instead of GoogleApiKeyRequestAuthentication.
 *   - Image-output paths are removed because OAuth bearer with the
 *     `cloud-platform` scope does not currently expose Imagen-style
 *     generation through generativelanguage.googleapis.com.
 *   - 429 responses mark the active pool account as rate-limited via
 *     `markRateLimitedFromResponse`, matching the Anthropic Max rotation
 *     behaviour.
 *
 * @since 1.3.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use AnthropicMaxAiProvider\Authentication\GoogleOAuthRequestAuthentication;
use AnthropicMaxAiProvider\OAuthPool\PoolRegistry;
use AnthropicMaxAiProvider\Provider\GoogleAiProProvider;

/**
 * Text generation model for Google AI Pro.
 *
 * @since 1.3.0
 *
 * @phpstan-type MessageData array{
 *     role?: string,
 *     parts?: list<array<string, mixed>>
 * }
 * @phpstan-type CandidateData array{
 *     content?: MessageData,
 *     finishReason?: string
 * }
 * @phpstan-type UsageData array{
 *     promptTokenCount?: int,
 *     candidatesTokenCount?: int,
 *     thoughtsTokenCount?: int
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     candidates?: list<CandidateData>,
 *     usageMetadata?: UsageData
 * }
 */
class GoogleAiProTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
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

        if ($requestAuthentication instanceof GoogleOAuthRequestAuthentication) {
            return $requestAuthentication;
        }

        return new GoogleOAuthRequestAuthentication(PoolRegistry::pool('google'));
    }

    /**
     * Generates a text result using the Google Generative Language API.
     *
     * On 429 (rate limit) responses, the active account is marked as
     * rate-limited in the pool using the Retry-After header value when
     * present, falling back to the pool's DEFAULT_COOLDOWN_MS.
     *
     * @since 1.3.0
     *
     * @param list<Message> $prompt The prompt to generate text for.
     * @return GenerativeAiResult The generation result.
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        $params = $this->prepareGenerateTextParams($prompt);

        $request = new Request(
            HttpMethodEnum::POST(),
            GoogleAiProProvider::url("models/{$this->metadata()->getId()}:generateContent"),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        $auth     = $this->getRequestAuthentication();
        $request  = $auth->authenticateRequest($request);
        $response = $httpTransporter->send($request);

        // Google returns 429 for quota exhaustion; mark the active account.
        if ($response->getStatusCode() === 429) {
            $this->handleRateLimitResponse($auth, $response);
        }

        ResponseUtil::throwIfNotSuccessful($response);
        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Marks the active pool account as rate-limited based on the API response.
     *
     * @since 1.3.0
     *
     * @param RequestAuthenticationInterface $auth     The request authentication.
     * @param Response                       $response The rate-limit response.
     * @return void
     */
    protected function handleRateLimitResponse(
        RequestAuthenticationInterface $auth,
        Response $response
    ): void {
        if (!($auth instanceof GoogleOAuthRequestAuthentication)) {
            return;
        }
        $email = $auth->getActiveEmail();
        if ($email === null) {
            return;
        }
        PoolRegistry::pool('google')->markRateLimited(
            $email,
            null,
            $this->parseRetryAfterHeader($response)
        );
    }

    /**
     * Parses the Retry-After header (RFC 7231 — seconds or HTTP-date).
     *
     * @since 1.3.0
     *
     * @param Response $response
     * @return int|null Retry-After in seconds, or null if absent/unparseable.
     */
    protected function parseRetryAfterHeader(Response $response): ?int
    {
        $header = $response->getHeaderAsString('retry-after');
        if ($header === null || $header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return (int) $header;
        }
        $timestamp = strtotime($header);
        if ($timestamp !== false && $timestamp > time()) {
            return $timestamp - time();
        }
        return null;
    }

    /**
     * Prepares the API request parameters from the prompt and model config.
     *
     * Mirrors the canonical Google plugin's `prepareGenerateTextParams`.
     *
     * @since 1.3.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> The API request parameters.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'contents' => $this->prepareContentsParam($prompt),
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction) {
            $params['systemInstruction'] = [
                'parts' => [
                    ['text' => is_string($systemInstruction) ? $systemInstruction : (string) $systemInstruction],
                ],
            ];
        }

        $generationConfig = [];

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null) {
            $generationConfig['candidateCount'] = $candidateCount;
        }

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $maxTokens;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $generationConfig['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $generationConfig['topP'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $generationConfig['topK'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $generationConfig['stopSequences'] = $stopSequences;
        }

        $presencePenalty = $config->getPresencePenalty();
        if ($presencePenalty !== null) {
            $generationConfig['presencePenalty'] = $presencePenalty;
        }

        $frequencyPenalty = $config->getFrequencyPenalty();
        if ($frequencyPenalty !== null) {
            $generationConfig['frequencyPenalty'] = $frequencyPenalty;
        }

        $logprobs = $config->getLogprobs();
        if ($logprobs !== null) {
            $generationConfig['responseLogprobs'] = $logprobs;
        }

        $topLogprobs = $config->getTopLogprobs();
        if ($topLogprobs !== null) {
            $generationConfig['logprobs'] = $topLogprobs;
        }

        $outputMimeType = $config->getOutputMimeType();
        if ($outputMimeType) {
            $generationConfig['responseMimeType'] = $outputMimeType;
            if ($outputMimeType === 'application/json') {
                $outputSchema = $config->getOutputSchema();
                if ($outputSchema) {
                    // Google rejects `additionalProperties`; strip recursively.
                    $generationConfig['responseSchema'] = $this->removeAdditionalPropertiesKey($outputSchema);
                }
            }
        }

        if ($generationConfig) {
            $params['generationConfig'] = $generationConfig;
        }

        $tools = [];

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations)) {
            $tools[] = [
                'functionDeclarations' => $this->prepareFunctionDeclarationsParam($functionDeclarations),
            ];
        }

        $webSearch = $config->getWebSearch();
        if ($webSearch) {
            // Allowed/disallowed domain filtering is not supported by the
            // Google AI API; tool is enabled but unfiltered.
            $tools[] = ['googleSearch' => new \stdClass()];
        }

        if ($tools) {
            $params['tools'] = $tools;
        }

        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (str_starts_with($key, 'generationConfig.')) {
                $key = substr($key, strlen('generationConfig.'));
                if (!isset($params['generationConfig']) || !is_array($params['generationConfig'])) {
                    $params['generationConfig'] = [$key => $value];
                    continue;
                }
                if (isset($params['generationConfig'][$key])) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'The custom generationConfig option "%s" conflicts with an existing parameter.',
                            $key
                        )
                    );
                }
                $params['generationConfig'][$key] = $value;
                continue;
            }
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with an existing parameter.', $key)
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Prepares the contents parameter for the API request.
     *
     * @since 1.3.0
     *
     * @param list<Message> $messages The messages to prepare.
     * @return list<array<string, mixed>> The prepared contents.
     */
    protected function prepareContentsParam(array $messages): array
    {
        return array_map(
            function (Message $message): array {
                return [
                    'role'  => $this->getMessageRoleString($message->getRole()),
                    'parts' => array_values(array_filter(array_map(
                        [$this, 'getMessagePartData'],
                        $message->getParts()
                    ))),
                ];
            },
            $messages
        );
    }

    /**
     * Returns the Google API specific role string.
     *
     * @since 1.3.0
     *
     * @param MessageRoleEnum $role
     * @return string
     */
    protected function getMessageRoleString(MessageRoleEnum $role): string
    {
        if ($role === MessageRoleEnum::model()) {
            return 'model';
        }
        return 'user';
    }

    /**
     * Returns the Google API specific data for a message part.
     *
     * @since 1.3.0
     *
     * @param MessagePart $part
     * @return ?array<string, mixed>
     *
     * @throws InvalidArgumentException If the message part is unsupported.
     */
    protected function getMessagePartData(MessagePart $part): ?array
    {
        $type = $part->getType();
        if ($type->isText()) {
            if ($part->getChannel()->isThought()) {
                return ['text' => $part->getText(), 'thought' => true];
            }
            return ['text' => $part->getText()];
        }
        if ($type->isFile()) {
            $file = $part->getFile();
            if (!$file) {
                throw new RuntimeException('The file typed message part must contain a file.');
            }
            if ($file->isRemote()) {
                $fileUrl = $file->getUrl();
                if (!$fileUrl) {
                    throw new RuntimeException('The remote file must contain a URL.');
                }
                // Special case for YouTube URLs (Google natively understands them).
                if (preg_match('/^https?:\/\/(?:www\.)?(?:m\.)?(?:youtu\.be\/|youtube\.com\/)/', $fileUrl)) {
                    return ['fileData' => ['fileUri' => $fileUrl]];
                }
                return [
                    'fileData' => [
                        'mimeType' => $file->getMimeType(),
                        'fileUri'  => $fileUrl,
                    ],
                ];
            }
            $fileBase64Data = $file->getBase64Data();
            if (!$fileBase64Data) {
                throw new RuntimeException('The inline file must contain base64 data.');
            }
            return [
                'inlineData' => [
                    'mimeType' => $file->getMimeType(),
                    'data'     => $fileBase64Data,
                ],
            ];
        }
        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                throw new RuntimeException(
                    'The function_call typed message part must contain a function call.'
                );
            }
            $functionCallData = ['name' => $functionCall->getName()];
            $args = $functionCall->getArgs();
            if ($args !== null) {
                $functionCallData['args'] = $args;
            }
            return ['functionCall' => $functionCallData];
        }
        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                throw new RuntimeException(
                    'The function_response typed message part must contain a function response.'
                );
            }
            return [
                'functionResponse' => [
                    'name'     => $functionResponse->getName(),
                    'response' => [
                        'name'    => $functionResponse->getName(),
                        'content' => $functionResponse->getResponse(),
                    ],
                ],
            ];
        }
        throw new InvalidArgumentException(
            sprintf('Unsupported message part type "%s".', $type)
        );
    }

    /**
     * Prepares the function declarations parameter, stripping
     * `additionalProperties` recursively (Google rejects it).
     *
     * @since 1.3.0
     *
     * @param list<FunctionDeclaration> $functionDeclarations
     * @return list<array<string, mixed>>
     */
    protected function prepareFunctionDeclarationsParam(array $functionDeclarations): array
    {
        $prepared = [];
        foreach ($functionDeclarations as $functionDeclaration) {
            $data = $functionDeclaration->toArray();
            if (isset($data['parameters'])) {
                $data['parameters'] = $this->removeAdditionalPropertiesKey($data['parameters']);
            }
            $prepared[] = $data;
        }
        return $prepared;
    }

    /**
     * Recursively removes the `additionalProperties` key from a JSON schema.
     *
     * The Google AI API does not allow `additionalProperties`.
     *
     * @since 1.3.0
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function removeAdditionalPropertiesKey(array $schema): array
    {
        if (isset($schema['additionalProperties'])) {
            unset($schema['additionalProperties']);
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            /** @var array<string, mixed> $childSchema */
            foreach ($schema['properties'] as $key => $childSchema) {
                $schema['properties'][$key] = $this->removeAdditionalPropertiesKey($childSchema);
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            if (array_is_list($schema['items'])) {
                foreach ($schema['items'] as $key => $itemSchema) {
                    if (is_array($itemSchema)) {
                        /** @var array<string, mixed> $itemSchema */
                        $schema['items'][$key] = $this->removeAdditionalPropertiesKey($itemSchema);
                    }
                }
            } else {
                /** @var array<string, mixed> $items */
                $items = $schema['items'];
                $schema['items'] = $this->removeAdditionalPropertiesKey($items);
            }
        }
        return $schema;
    }

    /**
     * Parses the API response into a GenerativeAiResult.
     *
     * @since 1.3.0
     *
     * @param Response $response
     * @return GenerativeAiResult
     *
     * @throws ResponseException If the response is malformed.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['candidates']) || !$responseData['candidates']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'candidates');
        }
        if (!is_array($responseData['candidates'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'candidates',
                'The value must be an array.'
            );
        }

        $candidates = [];
        foreach ($responseData['candidates'] as $index => $candidateData) {
            if (!is_array($candidateData) || array_is_list($candidateData)) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "candidates[{$index}]",
                    'The value must be an associative array.'
                );
            }
            $candidates[] = $this->parseResponseCandidateToCandidate($candidateData, $index);
        }

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        if (isset($responseData['usageMetadata']) && is_array($responseData['usageMetadata'])) {
            $usage = $responseData['usageMetadata'];
            $tokenUsage = new TokenUsage(
                $usage['promptTokenCount'] ?? 0,
                $usage['candidatesTokenCount'] ?? 0,
                ($usage['candidatesTokenCount'] ?? 0) + ($usage['thoughtsTokenCount'] ?? 0)
            );
        } else {
            $tokenUsage = new TokenUsage(0, 0, 0);
        }

        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['candidates'], $additionalData['usageMetadata']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses a candidate from the API response.
     *
     * @since 1.3.0
     *
     * @param CandidateData $candidateData
     * @param int           $index
     * @return Candidate
     *
     * @throws ResponseException If the candidate is malformed.
     */
    protected function parseResponseCandidateToCandidate(array $candidateData, int $index): Candidate
    {
        if (
            !isset($candidateData['content']) ||
            !is_array($candidateData['content']) ||
            array_is_list($candidateData['content'])
        ) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "candidates[{$index}].content"
            );
        }
        if (!isset($candidateData['finishReason']) || !is_string($candidateData['finishReason'])) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "candidates[{$index}].finishReason"
            );
        }

        $message = $this->parseResponseCandidateMessage($candidateData['content'], $index);

        switch ($candidateData['finishReason']) {
            case 'STOP':
                $finishReason = FinishReasonEnum::stop();
                foreach ($message->getParts() as $messagePart) {
                    if ($messagePart->getType()->isFunctionCall()) {
                        $finishReason = FinishReasonEnum::toolCalls();
                        break;
                    }
                }
                break;
            case 'MAX_TOKENS':
                $finishReason = FinishReasonEnum::length();
                break;
            case 'IMAGE_SAFETY':
            case 'RECITATION':
            case 'SAFETY':
            case 'BLOCKLIST':
            case 'PROHIBITED_CONTENT':
            case 'SPII':
                $finishReason = FinishReasonEnum::contentFilter();
                break;
            default:
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "candidates[{$index}].finishReason",
                    sprintf('Invalid finish reason "%s".', $candidateData['finishReason'])
                );
        }

        return new Candidate($message, $finishReason);
    }

    /**
     * Parses the message from a candidate.
     *
     * @since 1.3.0
     *
     * @param MessageData $messageData
     * @param int         $index
     * @return Message
     */
    protected function parseResponseCandidateMessage(array $messageData, int $index): Message
    {
        $role = isset($messageData['role']) && 'user' === $messageData['role']
            ? MessageRoleEnum::user()
            : MessageRoleEnum::model();

        if (!isset($messageData['parts'])) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "candidates[{$index}].content.parts"
            );
        }
        if (!is_array($messageData['parts']) || !array_is_list($messageData['parts'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                "candidates[{$index}].content.parts",
                'The value must be an indexed array.'
            );
        }

        $parts = [];
        foreach ($messageData['parts'] as $partIndex => $messagePartData) {
            try {
                $parts[] = $this->parseResponseCandidateMessagePart($messagePartData);
            } catch (InvalidArgumentException $e) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "candidates[{$index}].content.parts[{$partIndex}]",
                    $e->getMessage()
                );
            }
        }

        return new Message($role, $parts);
    }

    /**
     * Parses a message part from a candidate.
     *
     * @since 1.3.0
     *
     * @param array<string, mixed> $partData
     * @return MessagePart
     *
     * @throws InvalidArgumentException If the part is malformed.
     */
    protected function parseResponseCandidateMessagePart(array $partData): MessagePart
    {
        if (isset($partData['text'])) {
            if (!is_string($partData['text'])) {
                throw new InvalidArgumentException('Part has an invalid text shape.');
            }
            if (isset($partData['thought']) && $partData['thought']) {
                return new MessagePart($partData['text'], MessagePartChannelEnum::thought());
            }
            return new MessagePart($partData['text']);
        }
        if (isset($partData['inlineData'])) {
            if (
                !is_array($partData['inlineData']) ||
                !isset($partData['inlineData']['data']) ||
                !is_string($partData['inlineData']['data'])
            ) {
                throw new InvalidArgumentException('Part has an invalid inlineData shape.');
            }
            return new MessagePart(
                new File(
                    $partData['inlineData']['data'],
                    isset($partData['inlineData']['mimeType']) && is_string($partData['inlineData']['mimeType']) ?
                        $partData['inlineData']['mimeType'] :
                        null
                )
            );
        }
        if (isset($partData['fileData'])) {
            if (
                !is_array($partData['fileData']) ||
                !isset($partData['fileData']['fileUri']) ||
                !is_string($partData['fileData']['fileUri'])
            ) {
                throw new InvalidArgumentException('Part has an invalid fileData shape.');
            }
            return new MessagePart(
                new File(
                    $partData['fileData']['fileUri'],
                    isset($partData['fileData']['mimeType']) && is_string($partData['fileData']['mimeType']) ?
                        $partData['fileData']['mimeType'] :
                        null
                )
            );
        }
        if (isset($partData['functionCall'])) {
            if (
                !is_array($partData['functionCall']) ||
                !isset($partData['functionCall']['name']) ||
                !is_string($partData['functionCall']['name'])
            ) {
                throw new InvalidArgumentException('Part has an invalid functionCall shape.');
            }
            // Google may omit `args` for no-argument functions, or return `{}`.
            $args = $partData['functionCall']['args'] ?? null;
            if (is_array($args) && count($args) === 0) {
                $args = null;
            }
            return new MessagePart(
                new FunctionCall(null, $partData['functionCall']['name'], $args)
            );
        }
        throw new InvalidArgumentException('Part has an unexpected type.');
    }
}
