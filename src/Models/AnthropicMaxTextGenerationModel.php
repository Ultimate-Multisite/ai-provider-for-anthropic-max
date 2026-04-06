<?php
/**
 * Anthropic Max text generation model.
 *
 * Handles the Anthropic Messages API format with OAuth Bearer token authentication.
 * Based on the upstream ai-provider-for-anthropic but using pool-rotated OAuth tokens.
 *
 * @since 1.0.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Models;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
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
use WordPress\AiClient\Tools\DTO\WebSearch;
use AnthropicMaxAiProvider\Authentication\AnthropicOAuthRequestAuthentication;
use AnthropicMaxAiProvider\OAuthPool\PoolManager;
use AnthropicMaxAiProvider\Provider\AnthropicMaxProvider;

/**
 * Text generation model for Anthropic Max.
 *
 * @since 1.0.0
 *
 * @phpstan-type UsageData array{
 *     input_tokens?: int,
 *     output_tokens?: int,
 *     cache_creation_input_tokens?: int,
 *     cache_read_input_tokens?: int
 * }
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     role?: string,
 *     content?: list<array<string, mixed>>,
 *     stop_reason?: string,
 *     usage?: UsageData
 * }
 */
class AnthropicMaxTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
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

        // Fallback: build OAuth auth from the pool manager.
        $pool = PoolManager::getInstance();
        return new AnthropicOAuthRequestAuthentication($pool);
    }

    /**
     * Generates a text result using the Anthropic Messages API.
     *
     * On 429 (rate limit) or 529 (API overloaded) responses, the active account
     * is marked as rate-limited in the pool using the Retry-After header value
     * when present, falling back to the pool's DEFAULT_COOLDOWN_MS.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return GenerativeAiResult The generation result.
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();
        $params          = $this->prepareGenerateTextParams($prompt);
        $headers         = ['Content-Type' => 'application/json'];

        // Add beta header for structured outputs if JSON schema output is requested.
        $config = $this->getConfig();
        if ('application/json' === $config->getOutputMimeType() && $config->getOutputSchema()) {
            $headers['anthropic-beta'] = 'structured-outputs-2025-11-13';
        }

        $request = new Request(
            HttpMethodEnum::POST(),
            AnthropicMaxProvider::url('messages'),
            $headers,
            $params,
            $this->getRequestOptions()
        );

        $auth     = $this->getRequestAuthentication();
        $request  = $auth->authenticateRequest($request);
        $response = $httpTransporter->send($request);

        // On rate-limit or overload responses, mark the active account with the
        // server-specified cooldown before letting the SDK throw its exception.
        // 429 = rate limited; 529 = Anthropic API overloaded.
        $status_code = $response->getStatusCode();
        if ($status_code === 429 || $status_code === 529) {
            $this->handleRateLimitResponse($auth, $response);
        }

        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Marks the active pool account as rate-limited based on the API response.
     *
     * Extracts the Retry-After header (seconds or HTTP-date) and passes it to
     * the pool manager. Falls back to DEFAULT_COOLDOWN_MS when absent.
     *
     * @since 1.0.0
     *
     * @param RequestAuthenticationInterface $auth     The request authentication instance.
     * @param Response                       $response The rate-limit response.
     * @return void
     */
    protected function handleRateLimitResponse(
        RequestAuthenticationInterface $auth,
        Response $response
    ): void {
        // Only act when using our OAuth auth — we need the pool manager.
        if (!($auth instanceof AnthropicOAuthRequestAuthentication)) {
            return;
        }

        $pool  = PoolManager::getInstance();
        $email = $auth->getActiveEmail();

        if ($email === null) {
            return;
        }

        // Parse Retry-After header: integer seconds or HTTP-date.
        $retry_after_secs = $this->parseRetryAfterHeader($response);
        $pool->markRateLimited($email, null, $retry_after_secs);
    }

    /**
     * Parses the Retry-After header from an SDK Response.
     *
     * Supports integer (seconds) and HTTP-date formats per RFC 7231.
     *
     * @since 1.0.0
     *
     * @param Response $response The HTTP response.
     * @return int|null Retry-After in seconds, or null if absent/unparseable.
     */
    protected function parseRetryAfterHeader(Response $response): ?int
    {
        $header = $response->getHeaderAsString('retry-after');
        if ($header === null || $header === '') {
            return null;
        }

        // Integer seconds: "Retry-After: 60"
        if (ctype_digit($header)) {
            return (int) $header;
        }

        // HTTP-date: "Retry-After: Wed, 21 Oct 2025 07:28:00 GMT"
        $timestamp = strtotime($header);
        if ($timestamp !== false && $timestamp > time()) {
            return $timestamp - time();
        }

        return null;
    }

    /**
     * Prepares the API request parameters from the prompt and model configuration.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> The API request parameters.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'model'    => $this->metadata()->getId(),
            'messages' => $this->prepareMessagesParam($prompt),
        ];

        // Anthropic Max OAuth gateway requires the first system block to be the
        // Claude Code identifier — otherwise it returns a misleading
        // 401 "Invalid bearer token". Always prepend it, then append any
        // user-supplied system instruction as a second block.
        $systemBlocks = [
            [
                'type' => 'text',
                'text' => "You are Claude Code, Anthropic's official CLI for Claude.",
            ],
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction) {
            $systemBlocks[] = [
                'type' => 'text',
                'text' => is_string($systemInstruction)
                    ? $systemInstruction
                    : (string) $systemInstruction,
            ];
        }

        $params['system'] = $systemBlocks;

        $maxTokens = $config->getMaxTokens();
        $params['max_tokens'] = $maxTokens ?? 4096;

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $params['top_k'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $params['stop_sequences'] = $stopSequences;
        }

        $outputMimeType = $config->getOutputMimeType();
        $outputSchema   = $config->getOutputSchema();
        if ($outputMimeType === 'application/json' && $outputSchema) {
            $params['output_format'] = [
                'type'   => 'json_schema',
                'schema' => $outputSchema,
            ];
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        $webSearch            = $config->getWebSearch();
        if (is_array($functionDeclarations) || $webSearch) {
            $params['tools'] = $this->prepareToolsParam($functionDeclarations, $webSearch);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[anthropic-max] tools payload: ' . wp_json_encode($params['tools']));
            }
        }

        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
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
     * Prepares the messages parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<Message> $messages The messages.
     * @return list<array<string, mixed>> The formatted messages.
     */
    protected function prepareMessagesParam(array $messages): array
    {
        return array_map(
            function (Message $message): array {
                return [
                    'role'    => $this->getMessageRoleString($message->getRole()),
                    'content' => array_values(array_filter(array_map(
                        [$this, 'getMessagePartData'],
                        $message->getParts()
                    ))),
                ];
            },
            $messages
        );
    }

    /**
     * Maps message roles to Anthropic API role strings.
     *
     * @since 1.0.0
     *
     * @param MessageRoleEnum $role The message role.
     * @return string The API role string.
     */
    protected function getMessageRoleString(MessageRoleEnum $role): string
    {
        if ($role === MessageRoleEnum::model()) {
            return 'assistant';
        }
        return 'user';
    }

    /**
     * Converts a message part to the Anthropic API format.
     *
     * @since 1.0.0
     *
     * @param MessagePart $part The message part.
     * @return ?array<string, mixed> The formatted part data, or null to skip.
     *
     * @throws InvalidArgumentException If the part type is unsupported.
     */
    protected function getMessagePartData(MessagePart $part): ?array
    {
        $type = $part->getType();

        if ($type->isText()) {
            if ($part->getChannel()->isThought()) {
                return [
                    'type'     => 'thinking',
                    'thinking' => $part->getText(),
                ];
            }
            return [
                'type' => 'text',
                'text' => $part->getText(),
            ];
        }

        if ($type->isFile()) {
            return $this->getFilePartData($part);
        }

        if ($type->isFunctionCall()) {
            $functionCall = $part->getFunctionCall();
            if (!$functionCall) {
                throw new RuntimeException('The function_call typed message part must contain a function call.');
            }
            $input = $functionCall->getArgs();
            if ($input === null) {
                $input = new \stdClass();
            }
            return [
                'type'  => 'tool_use',
                'id'    => $functionCall->getId(),
                'name'  => $functionCall->getName(),
                'input' => $input,
            ];
        }

        if ($type->isFunctionResponse()) {
            $functionResponse = $part->getFunctionResponse();
            if (!$functionResponse) {
                throw new RuntimeException('The function_response typed message part must contain a function response.');
            }
            return [
                'type'        => 'tool_result',
                'tool_use_id' => $functionResponse->getId(),
                'content'     => json_encode($functionResponse->getResponse()),
            ];
        }

        throw new InvalidArgumentException(
            sprintf('Unsupported message part type "%s".', $type)
        );
    }

    /**
     * Converts a file message part to the Anthropic API format.
     *
     * @since 1.0.0
     *
     * @param MessagePart $part The file message part.
     * @return array<string, mixed> The formatted file data.
     *
     * @throws InvalidArgumentException If the file type is unsupported.
     */
    protected function getFilePartData(MessagePart $part): array
    {
        $file = $part->getFile();
        if (!$file) {
            throw new RuntimeException('The file typed message part must contain a file.');
        }

        if ($file->isRemote()) {
            $fileUrl = $file->getUrl();
            if (!$fileUrl) {
                throw new RuntimeException('The remote file must contain a URL.');
            }
            if ($file->isDocument()) {
                return [
                    'type'   => 'document',
                    'source' => [
                        'type' => 'url',
                        'url'  => $fileUrl,
                    ],
                ];
            }
            throw new InvalidArgumentException(
                'Unsupported file type: The API only supports inline files for non-document types.'
            );
        }

        $fileBase64Data = $file->getBase64Data();
        if (!$fileBase64Data) {
            throw new RuntimeException('The inline file must contain base64 data.');
        }

        if ($file->isImage()) {
            return [
                'type'   => 'image',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $file->getMimeType(),
                    'data'       => $fileBase64Data,
                ],
            ];
        }

        if ($file->isDocument()) {
            return [
                'type'   => 'document',
                'source' => [
                    'type'       => 'base64',
                    'media_type' => $file->getMimeType(),
                    'data'       => $fileBase64Data,
                ],
            ];
        }

        throw new InvalidArgumentException(
            sprintf('Unsupported MIME type "%s" for inline file message part.', $file->getMimeType())
        );
    }

    /**
     * Prepares the tools parameter for the API request.
     *
     * @since 1.0.0
     *
     * @param list<FunctionDeclaration>|null $functionDeclarations The function declarations.
     * @param WebSearch|null                 $webSearch            The web search config.
     * @return list<array<string, mixed>> The formatted tools parameter.
     */
    protected function prepareToolsParam(?array $functionDeclarations, ?WebSearch $webSearch): array
    {
        $tools = [];

        if (is_array($functionDeclarations)) {
            foreach ($functionDeclarations as $functionDeclaration) {
                $inputSchema = $functionDeclaration->getParameters();
                if ($inputSchema === null) {
                    $inputSchema = [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                    ];
                } else {
                    // Anthropic requires input_schema.properties to be an object,
                    // never an array. An empty PHP array JSON-encodes to [], so
                    // coerce empty/list-style properties to stdClass.
                    $inputSchema = $this->normalizeSchemaProperties($inputSchema);
                }

                $tools[] = array_filter([
                    'name'         => $functionDeclaration->getName(),
                    'description'  => $functionDeclaration->getDescription(),
                    'input_schema' => $inputSchema,
                ]);
            }
        }

        if ($webSearch) {
            $tools[] = array_filter([
                'type'            => 'web_search_20250305',
                'name'            => 'web_search',
                'max_uses'        => 1,
                'allowed_domains' => $webSearch->getAllowedDomains(),
                'blocked_domains' => $webSearch->getDisallowedDomains(),
            ]);
        }

        return $tools;
    }

    /**
     * Recursively ensures that any "properties" key inside a JSON schema is
     * encoded as a JSON object (stdClass) rather than an empty JSON array.
     *
     * Anthropic rejects tools whose input_schema.properties serializes to [].
     *
     * @param mixed $schema The schema node.
     * @return mixed The normalized schema.
     */
    protected function normalizeSchemaProperties($schema)
    {
        if (is_array($schema)) {
            // Draft 2020-12: `items` must be a schema or boolean, never an array.
            // An empty array (legacy tuple form) is invalid; coerce to {} (any).
            if (array_key_exists('items', $schema) && is_array($schema['items']) && array_is_list($schema['items'])) {
                $schema['items'] = new \stdClass();
            }
            if (array_key_exists('properties', $schema)) {
                $props = $schema['properties'];
                if (is_array($props) && count($props) === 0) {
                    $schema['properties'] = new \stdClass();
                } elseif (is_array($props)) {
                    foreach ($props as $k => $v) {
                        $props[$k] = $this->normalizeSchemaProperties($v);
                    }
                    $schema['properties'] = $props;
                }
            }
            foreach ($schema as $k => $v) {
                if ($k === 'properties') {
                    continue;
                }
                if (is_array($v)) {
                    $schema[$k] = $this->normalizeSchemaProperties($v);
                }
            }
        }
        return $schema;
    }

    /**
     * Parses the Anthropic API response into a GenerativeAiResult.
     *
     * @since 1.0.0
     *
     * @param Response $response The API response.
     * @return GenerativeAiResult The parsed result.
     *
     * @throws ResponseException If the response is malformed.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var ResponseData $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['content']) || !$responseData['content']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'content');
        }
        if (!is_array($responseData['content']) || !array_is_list($responseData['content'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'content',
                'The value must be an indexed array.'
            );
        }

        $role = isset($responseData['role']) && 'user' === $responseData['role']
            ? MessageRoleEnum::user()
            : MessageRoleEnum::model();

        $parts = [];
        foreach ($responseData['content'] as $partIndex => $messagePartData) {
            try {
                $newPart = $this->parseResponseContentMessagePart($messagePartData);
                if ($newPart) {
                    $parts[] = $newPart;
                }
            } catch (InvalidArgumentException $e) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "content[{$partIndex}]",
                    $e->getMessage()
                );
            }
        }

        if (!isset($responseData['stop_reason'])) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'stop_reason');
        }

        $finishReason = $this->mapStopReason($responseData['stop_reason']);

        $candidates = [new Candidate(
            new Message($role, $parts),
            $finishReason
        )];

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        $tokenUsage = $this->parseTokenUsage($responseData['usage'] ?? []);

        // Preserve any additional response data as provider-specific metadata.
        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['role'], $additionalData['content'], $additionalData['stop_reason'], $additionalData['usage']);

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
     * Maps an Anthropic stop_reason to a FinishReasonEnum.
     *
     * @since 1.0.0
     *
     * @param string $stopReason The Anthropic stop reason.
     * @return FinishReasonEnum The mapped finish reason.
     *
     * @throws ResponseException If the stop reason is unknown.
     */
    protected function mapStopReason(string $stopReason): FinishReasonEnum
    {
        switch ($stopReason) {
            case 'pause_turn':
            case 'end_turn':
            case 'stop_sequence':
                return FinishReasonEnum::stop();
            case 'max_tokens':
            case 'model_context_window_exceeded':
                return FinishReasonEnum::length();
            case 'refusal':
                return FinishReasonEnum::contentFilter();
            case 'tool_use':
                return FinishReasonEnum::toolCalls();
            default:
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    'stop_reason',
                    sprintf('Invalid stop reason "%s".', $stopReason)
                );
        }
    }

    /**
     * Parses token usage from the API response.
     *
     * @since 1.0.0
     *
     * @param array $usage The usage data from the response.
     * @return TokenUsage The parsed token usage.
     */
    protected function parseTokenUsage(array $usage): TokenUsage
    {
        if (empty($usage)) {
            return new TokenUsage(0, 0, 0);
        }

        $inputTokens = ($usage['input_tokens'] ?? 0)
            + ($usage['cache_creation_input_tokens'] ?? 0)
            + ($usage['cache_read_input_tokens'] ?? 0);

        $outputTokens = $usage['output_tokens'] ?? 0;

        return new TokenUsage($inputTokens, $outputTokens, $inputTokens + $outputTokens);
    }

    /**
     * Parses a message part from the response content.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $partData The part data from the response.
     * @return MessagePart|null The parsed message part, or null to skip.
     *
     * @throws InvalidArgumentException If the part is malformed.
     */
    protected function parseResponseContentMessagePart(array $partData): ?MessagePart
    {
        if (!isset($partData['type'])) {
            throw new InvalidArgumentException('Part is missing a type field.');
        }

        switch ($partData['type']) {
            case 'text':
                if (!isset($partData['text']) || !is_string($partData['text'])) {
                    throw new InvalidArgumentException('Part has an invalid text shape.');
                }
                return new MessagePart($partData['text']);

            case 'thinking':
                if (!isset($partData['thinking']) || !is_string($partData['thinking'])) {
                    throw new InvalidArgumentException('Part has an invalid thinking shape.');
                }
                return new MessagePart($partData['thinking'], MessagePartChannelEnum::thought());

            case 'tool_use':
                if (
                    !isset($partData['id']) || !is_string($partData['id']) ||
                    !isset($partData['name']) || !is_string($partData['name']) ||
                    !isset($partData['input'])
                ) {
                    throw new InvalidArgumentException('Part has an invalid tool_use shape.');
                }
                $args = $partData['input'];
                if (is_array($args) && count($args) === 0) {
                    $args = null;
                }
                return new MessagePart(
                    new FunctionCall($partData['id'], $partData['name'], $args)
                );

            case 'redacted_thinking':
            case 'server_tool_use':
            case 'web_search_tool_result':
                return null;
        }

        throw new InvalidArgumentException('Part has an unexpected type.');
    }
}
