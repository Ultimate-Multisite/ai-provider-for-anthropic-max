<?php

/**
 * ChatGPT Codex text generation model.
 *
 * Implements the OpenAI Responses API as exposed by the Codex backend
 * (`https://chatgpt.com/backend-api/codex/responses`) using a ChatGPT
 * Plus/Pro/Team OAuth Bearer token from the OAuth account pool.
 *
 * Why this exists instead of reusing the SDK's standard OpenAI model:
 *
 * - ChatGPT-Account OAuth tokens are rejected by `api.openai.com`
 *   ("Missing scopes: api.responses.write"). They only authenticate
 *   against the Codex backend.
 * - The Codex backend speaks the Responses API (not Chat Completions)
 *   and imposes specific constraints not handled by the stock OpenAI
 *   provider: `instructions` is required, `stream: true` is required,
 *   `max_output_tokens` is rejected, the model whitelist is narrow.
 * - Codex responses are SSE-only; we buffer and aggregate them into a
 *   synchronous {@see GenerativeAiResult}, because
 *   {@see TextGenerationModelInterface::generateTextResult()} is
 *   synchronous and the upstream SD AI Agent invokes it that way.
 *
 * @since 1.2.0
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
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use AnthropicMaxAiProvider\Authentication\ChatGptCodexOAuthRequestAuthentication;
use AnthropicMaxAiProvider\OAuthPool\PoolRegistry;
use AnthropicMaxAiProvider\Provider\ChatGptCodexProvider;

/**
 * Text generation model for ChatGPT Codex.
 *
 * @since 1.2.0
 */
class ChatGptCodexTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    /**
     * HTTP request timeout for Codex calls, in seconds.
     *
     * Codex sometimes takes 30+ seconds to first token for the larger
     * models; 120s gives enough headroom for a full response without
     * leaving the request hanging indefinitely if the backend stalls.
     */
    private const REQUEST_TIMEOUT = 120;

    /**
     * Returns the request authentication, using our Codex OAuth class.
     *
     * Mirrors {@see AnthropicMaxTextGenerationModel::getRequestAuthentication()}:
     * prefer the SDK-resolved auth if it already happens to be our
     * class (so the SDK can pass a single pre-configured instance into
     * multiple models), otherwise build one on demand from the OpenAI
     * pool.
     *
     * @since 1.2.0
     *
     * @return RequestAuthenticationInterface
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        try {
            $requestAuthentication = parent::getRequestAuthentication();
        } catch (RuntimeException $e) {
            // The SDK throws when no auth is bound (e.g. callers that
            // construct the model directly without going through
            // AiClient::useProvider()). Fall through to the pool-backed
            // default below.
            $requestAuthentication = null;
        }

        if ($requestAuthentication instanceof ChatGptCodexOAuthRequestAuthentication) {
            return $requestAuthentication;
        }

        $pool = PoolRegistry::pool('openai');
        return new ChatGptCodexOAuthRequestAuthentication($pool);
    }

    /**
     * Generates a text result via the Codex Responses API.
     *
     * Wire-level flow:
     *  1. Build the Responses-API request body from the SDK prompt + config.
     *  2. Authenticate via {@see ChatGptCodexOAuthRequestAuthentication}.
     *  3. POST to Codex with `wp_remote_post` (Codex requires `stream:true`,
     *     so the response body is an SSE event stream that WordPress
     *     buffers as a single string for us).
     *  4. On 429/529 mark the active account rate-limited.
     *  5. Parse the SSE stream into a {@see GenerativeAiResult} containing
     *     the aggregated text and token usage from the `response.completed`
     *     event.
     *
     * @since 1.2.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return GenerativeAiResult
     *
     * @throws RuntimeException If the request fails or the response is unparseable.
     */
    final public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $auth        = $this->getRequestAuthentication();
        $instructions = $this->collectInstructions($prompt);
        $inputItems   = $this->buildInput($prompt);

        if (empty($inputItems)) {
            throw new RuntimeException(
                'ChatGPT Codex request had no user input to send.'
            );
        }

        $config = $this->getConfig();

        $body_params = [
            'model'        => $this->metadata()->getId(),
            'instructions' => $instructions,
            'input'        => $inputItems,
            'stream'       => true,
            'store'        => false,
            // Required when `store=false` for reasoning items to round-trip.
            // Without this, Codex emits reasoning items as bare {id, type, summary[]}
            // references, and any follow-up turn that echoes them back triggers
            // HTTP 404 "Item with id 'rs_...' not found. Items are not persisted
            // when `store` is set to false." Asking for the encrypted_content
            // upfront makes Codex include it on the emitted reasoning item, which
            // we pack into the thought-channel signature so it can be replayed
            // verbatim on the next turn.
            'include'      => ['reasoning.encrypted_content'],
        ];

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $body_params['temperature'] = $temperature;
        }
        $topP = $config->getTopP();
        if ($topP !== null) {
            $body_params['top_p'] = $topP;
        }

        // Probe: forward FunctionDeclarations as a Responses-API `tools` array
        // so the model can call host-side abilities instead of inventing a
        // pseudo `<tool_use>` text protocol when tools are present.
        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations) && !empty($functionDeclarations)) {
            $body_params['tools'] = $this->prepareToolsParam($functionDeclarations);
        }

        // Custom options last-write-wins to allow callers to override
        // anything except the wire-mandatory fields.
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (in_array($key, ['model', 'input', 'stream', 'store', 'include'], true)) {
                continue;
            }
            // Defensive: Codex rejects `max_output_tokens`; drop silently
            // rather than letting a stray custom option blow up the call.
            if ($key === 'max_output_tokens' || $key === 'max_tokens') {
                continue;
            }
            $body_params[$key] = $value;
        }

        // Build a minimal Request DTO purely so the auth class can
        // attach headers via withHeader(); we issue the actual call via
        // wp_remote_post so we can read the raw SSE body. Using the
        // upstream HTTP transporter would force JSON parsing on the body.
        $request = new \WordPress\AiClient\Providers\Http\DTO\Request(
            \WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum::POST(),
            ChatGptCodexProvider::url('responses'),
            [
                'Content-Type' => 'application/json',
                'Accept'       => 'text/event-stream',
            ],
            $body_params
        );
        $request = $auth->authenticateRequest($request);

        // Translate the DTO headers (array<string, list<string>>) into the
        // plain string-keyed array that wp_remote_post expects.
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            // wp_remote_post accepts a scalar string per header; join
            // multiple values with ", " per RFC 7230 §3.2.2.
            $headers[$name] = implode(', ', $values);
        }

        $encoded_body = wp_json_encode($body_params);
        if ($encoded_body === false) {
            throw new RuntimeException(
                'Failed to JSON-encode the Codex request body.'
            );
        }

        $response = wp_remote_post(
            $request->getUri(),
            [
                'headers' => $headers,
                'body'    => $encoded_body,
                'timeout' => self::REQUEST_TIMEOUT,
            ]
        );

        if (is_wp_error($response)) {
            throw new RuntimeException(
                'Network error contacting ChatGPT Codex backend: '
                . $response->get_error_message()
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);

        if ($status === 429 || $status === 529) {
            $this->markActiveAccountRateLimited($auth, $response);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(
                'ChatGPT Codex returned HTTP %d: %s',
                $status,
                $this->extractErrorMessage($body)
            ));
        }

        return $this->parseSseToResult($body);
    }

    /**
     * Marks the active pool account rate-limited based on a Retry-After
     * header, falling back to the pool's default cooldown.
     *
     * @since 1.2.0
     *
     * @param RequestAuthenticationInterface $auth     The auth instance.
     * @param array<string, mixed>           $response The wp_remote_* response array.
     * @return void
     */
    protected function markActiveAccountRateLimited(
        RequestAuthenticationInterface $auth,
        array $response
    ): void {
        if (!($auth instanceof ChatGptCodexOAuthRequestAuthentication)) {
            return;
        }
        $email = $auth->getActiveEmail();
        if ($email === null) {
            return;
        }

        $retry_after_secs = null;
        $header           = wp_remote_retrieve_header($response, 'retry-after');
        if (is_string($header) && $header !== '') {
            if (ctype_digit($header)) {
                $retry_after_secs = (int) $header;
            } else {
                $ts = strtotime($header);
                if ($ts !== false && $ts > time()) {
                    $retry_after_secs = $ts - time();
                }
            }
        }

        PoolRegistry::pool('openai')->markRateLimited($email, null, $retry_after_secs);
    }

    /**
     * Extracts a human-readable error message from a Codex error body.
     *
     * Codex returns either `{"detail":"..."}` for validation errors or
     * an OpenAI-style `{"error":{"message":"..."}}` shape. Falls back
     * to the raw body truncated to 300 chars.
     *
     * @since 1.2.0
     *
     * @param string $body Raw response body.
     * @return string
     */
    protected function extractErrorMessage(string $body): string
    {
        $parsed = json_decode($body, true);
        if (is_array($parsed)) {
            if (!empty($parsed['detail']) && is_string($parsed['detail'])) {
                return $parsed['detail'];
            }
            if (!empty($parsed['detail']) && is_array($parsed['detail'])) {
                return wp_json_encode($parsed['detail']) ?: $body;
            }
            if (!empty($parsed['error']['message']) && is_string($parsed['error']['message'])) {
                return $parsed['error']['message'];
            }
        }
        $trimmed = trim($body);
        return strlen($trimmed) > 300 ? substr($trimmed, 0, 300) . '…' : $trimmed;
    }

    /**
     * Builds the `instructions` string for the Responses API.
     *
     * Codex requires a non-empty `instructions` field (HTTP 400
     * "Instructions are required" otherwise). The AI Client SDK has no
     * SYSTEM message role — system text is supplied through
     * `ModelConfig::getSystemInstruction()` — so we draw exclusively
     * from that field and fall back to a neutral default when it is
     * empty.
     *
     * @since 1.2.0
     *
     * @param list<Message> $prompt Reserved for future role-aware sources.
     * @return string
     */
    protected function collectInstructions(array $prompt): string
    {
        unset($prompt); // No system-role messages exist in the SDK; reserved.
        $sys = $this->getConfig()->getSystemInstruction();
        if (is_string($sys) && $sys !== '') {
            return $sys;
        }
        return 'You are a helpful AI assistant.';
    }

    /**
     * Converts the SDK prompt into the Codex Responses API `input` array.
     *
     * Each Message becomes a sequence of top-level input items:
     *
     * - Thought-channel parts → `reasoning` items (encrypted_content/id/summary
     *   restored from the packed thought signature), emitted FIRST so they
     *   precede any message wrapper they were captured alongside.
     * - FunctionCall content parts → top-level `function_call` items.
     * - FunctionResponse content parts → top-level `function_call_output` items.
     * - Text content parts → accumulated into one `message` wrapper item per
     *   role (USER → `input_text`, MODEL → `output_text`).
     *
     * The Responses API requires function_call / function_call_output items to
     * live at the top level (not nested in message content); reasoning items
     * are top-level too because that is where they were returned in the
     * `response.completed.response.output[]` payload.
     *
     * @since 1.2.0
     *
     * @param list<Message> $prompt
     * @return list<array<string, mixed>>
     */
    protected function buildInput(array $prompt): array
    {
        $input = [];

        foreach ($prompt as $message) {
            $is_user = $message->getRole() !== MessageRoleEnum::model();

            // Emit any reasoning items first so the model sees its prior
            // thought-trace before its prior message content on next turn.
            foreach ($this->getReasoningInputItems($message) as $reasoningItem) {
                $input[] = $reasoningItem;
            }

            $textParts = [];
            foreach ($message->getParts() as $part) {
                $channel = method_exists($part, 'getChannel') ? $part->getChannel() : null;
                if ($channel !== null && $channel->isThought()) {
                    // Already emitted via getReasoningInputItems().
                    continue;
                }

                $type = $part->getType();
                if ($type->isFunctionCall()) {
                    $item = $this->convertFunctionCallPartToInputItem($part);
                    if ($item !== null) {
                        $input[] = $item;
                    }
                    continue;
                }
                if ($type->isFunctionResponse()) {
                    $item = $this->convertFunctionResponsePartToInputItem($part);
                    if ($item !== null) {
                        $input[] = $item;
                    }
                    continue;
                }

                $converted = $this->convertPartToInput($part, $is_user);
                if ($converted !== null) {
                    $textParts[] = $converted;
                }
            }

            if (!empty($textParts)) {
                $input[] = [
                    'role'    => $is_user ? 'user' : 'assistant',
                    'content' => $textParts,
                ];
            }
        }

        return $input;
    }

    /**
     * Converts a single SDK text MessagePart to a Responses-API content entry.
     *
     * Non-text parts (file / function_call / function_response) are handled
     * by their own dedicated converters in {@see self::buildInput()}; this
     * method only emits `input_text` / `output_text` segments and returns
     * null for anything else.
     *
     * @since 1.2.0
     *
     * @param MessagePart $part    The part to convert.
     * @param bool        $is_user Whether the enclosing message is from the user.
     * @return array<string, mixed>|null
     */
    protected function convertPartToInput(MessagePart $part, bool $is_user): ?array
    {
        if (!$part->getType()->isText()) {
            return null;
        }
        $text = $part->getText();
        if (!is_string($text) || $text === '') {
            return null;
        }
        return [
            'type' => $is_user ? 'input_text' : 'output_text',
            'text' => $text,
        ];
    }

    /**
     * Converts a FunctionCall MessagePart into a top-level `function_call`
     * input item, suitable for placing in the `input` array sent to Codex.
     *
     * Empty / unparseable args serialize to `"{}"` per the Responses API spec.
     *
     * @since n.e.x.t
     *
     * @param MessagePart $part A part whose type is functionCall().
     * @return array<string, mixed>|null
     */
    protected function convertFunctionCallPartToInputItem(MessagePart $part): ?array
    {
        $call = $part->getFunctionCall();
        if (!($call instanceof FunctionCall)) {
            return null;
        }
        $name   = $call->getName();
        $callId = $call->getId();
        if (!is_string($name) || $name === '' || !is_string($callId) || $callId === '') {
            return null;
        }
        $args     = $call->getArgs();
        $argsJson = '{}';
        if ($args !== null) {
            $encoded = wp_json_encode($args);
            if (is_string($encoded)) {
                $argsJson = $encoded;
            }
        }
        return [
            'type'      => 'function_call',
            'call_id'   => $callId,
            'name'      => $name,
            'arguments' => $argsJson,
        ];
    }

    /**
     * Converts a FunctionResponse MessagePart into a top-level
     * `function_call_output` input item.
     *
     * The Responses API expects `output` as a string; non-string responses
     * are JSON-encoded so tool result data round-trips losslessly.
     *
     * @since n.e.x.t
     *
     * @param MessagePart $part A part whose type is functionResponse().
     * @return array<string, mixed>|null
     */
    protected function convertFunctionResponsePartToInputItem(MessagePart $part): ?array
    {
        $resp = $part->getFunctionResponse();
        if (!($resp instanceof FunctionResponse)) {
            return null;
        }
        $callId = $resp->getId();
        if (!is_string($callId) || $callId === '') {
            return null;
        }
        $response = $resp->getResponse();
        if (is_string($response)) {
            $output = $response;
        } else {
            $encoded = wp_json_encode($response);
            $output  = is_string($encoded) ? $encoded : '';
        }
        return [
            'type'    => 'function_call_output',
            'call_id' => $callId,
            'output'  => $output,
        ];
    }

    /**
     * Extracts top-level `reasoning` items from a message's thought-channel
     * parts so they can be echoed back as their own input items on the next
     * turn (the Responses API expects reasoning items at the top level, not
     * nested in message content).
     *
     * Mirrors {@see OpenAiTextGenerationModel::getReasoningInputItems()} from
     * the WordPress/ai-provider-for-openai plugin's reasoning round-trip
     * support: the original `{id, encrypted_content, summary}` triple was
     * packed into the MessagePart's thought signature (a single string), so
     * we decode that JSON blob here to restore the full input shape.
     *
     * @since n.e.x.t
     *
     * @param Message $message
     * @return list<array<string, mixed>>
     */
    protected function getReasoningInputItems(Message $message): array
    {
        if (!method_exists(MessagePart::class, 'getThoughtSignature')) {
            return [];
        }

        $items = [];
        foreach ($message->getParts() as $part) {
            $channel = method_exists($part, 'getChannel') ? $part->getChannel() : null;
            if ($channel === null || !$channel->isThought()) {
                continue;
            }
            /** @phpstan-ignore-next-line method.notFound (gated by method_exists check above) */
            $signature = $part->getThoughtSignature();
            if (!is_string($signature) || $signature === '') {
                continue;
            }

            $item    = ['type' => 'reasoning'];
            $decoded = json_decode($signature, true);
            if (is_array($decoded)) {
                if (isset($decoded['id']) && is_string($decoded['id'])) {
                    $item['id'] = $decoded['id'];
                }
                if (isset($decoded['encrypted_content']) && is_string($decoded['encrypted_content'])) {
                    $item['encrypted_content'] = $decoded['encrypted_content'];
                }
                if (isset($decoded['summary']) && is_array($decoded['summary'])) {
                    $item['summary'] = $decoded['summary'];
                }
            } else {
                $item['encrypted_content'] = $signature;
            }
            if (!isset($item['summary'])) {
                $item['summary'] = [];
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Serializes FunctionDeclarations into the Responses-API `tools` array.
     *
     * Mirrors {@see OpenAiTextGenerationModel::prepareToolsParam()} from
     * WordPress/ai-provider-for-openai. The Codex backend (verified via the
     * probe in commit history) accepts the same shape as the public
     * Responses API, so no Codex-specific filtering is required.
     *
     * @since n.e.x.t
     *
     * @param list<FunctionDeclaration> $functionDeclarations
     * @return list<array<string, mixed>>
     */
    protected function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = [];
        foreach ($functionDeclarations as $functionDeclaration) {
            $tools[] = [
                'type'        => 'function',
                'name'        => $functionDeclaration->getName(),
                'description' => $functionDeclaration->getDescription(),
                'parameters'  => $functionDeclaration->getParameters(),
            ];
        }
        return $tools;
    }

    /**
     * Parses the SSE response body into a GenerativeAiResult.
     *
     * Codex's SSE stream follows the OpenAI Responses API event vocabulary
     * but differs from the public Responses API in one crucial way: the
     * final `response.completed` event's `data.response.output` array is
     * empty. The authoritative output items only arrive as `item` payloads
     * inside the incremental `response.output_item.done` events, so we
     * accumulate those during the walk and pass that list into
     * {@see self::parseOutputToParts()} once the stream finishes.
     *
     * As a defensive fallback for partial streams we also aggregate
     * `response.output_text.delta` chunks, so a mid-stream connection drop
     * still surfaces whatever text the model managed to emit.
     *
     * @since 1.2.0
     *
     * @param string $body Raw SSE body.
     * @return GenerativeAiResult
     *
     * @throws RuntimeException If the body contains no usable output.
     */
    protected function parseSseToResult(string $body): GenerativeAiResult
    {
        $delta_text   = '';
        $usage        = [];
        $response_id  = '';
        $output_items = [];

        // Split on event boundaries. SSE allows "\r\n\r\n" or "\n\n";
        // normalise to "\n\n" first.
        $normalised = str_replace("\r\n", "\n", $body);
        $events     = preg_split('/\n\n+/', $normalised);
        if (!is_array($events)) {
            $events = [];
        }

        foreach ($events as $event_block) {
            $event_block = trim($event_block);
            if ($event_block === '') {
                continue;
            }

            $event_name = '';
            $data_lines = [];
            foreach (preg_split('/\n/', $event_block) ?: [] as $line) {
                if (strncmp($line, 'event:', 6) === 0) {
                    $event_name = trim(substr($line, 6));
                } elseif (strncmp($line, 'data:', 5) === 0) {
                    $data_lines[] = ltrim(substr($line, 5));
                }
            }

            if (empty($data_lines)) {
                continue;
            }
            $data_raw = implode("\n", $data_lines);
            if ($data_raw === '[DONE]') {
                continue;
            }
            $data = json_decode($data_raw, true);
            if (!is_array($data)) {
                continue;
            }

            if (
                $event_name === 'response.output_text.delta'
                && isset($data['delta']) && is_string($data['delta'])
            ) {
                $delta_text .= $data['delta'];
            }

            // Codex publishes each output item's final form via
            // `response.output_item.done`. The `response.completed.response.output`
            // array is empty on this backend, so these per-item events are
            // the authoritative source for reasoning and function_call items.
            if (
                $event_name === 'response.output_item.done'
                && isset($data['item']) && is_array($data['item'])
            ) {
                $output_items[] = $data['item'];
            }

            // Token usage and trailing response id live on the completed event.
            if ($event_name === 'response.completed' && isset($data['response']) && is_array($data['response'])) {
                if (isset($data['response']['usage']) && is_array($data['response']['usage'])) {
                    $usage = $data['response']['usage'];
                }
                if ($response_id === '' && isset($data['response']['id']) && is_string($data['response']['id'])) {
                    $response_id = $data['response']['id'];
                }
            }
            if ($response_id === '' && isset($data['response']['id']) && is_string($data['response']['id'])) {
                $response_id = $data['response']['id'];
            }
        }

        $hasFunctionCall = false;
        $parts           = $this->parseOutputToParts($output_items, $hasFunctionCall);

        // Fallback: if no output_item.done events arrived but delta_text did,
        // surface what we have as a single text part. Preserves the partial-
        // stream resilience the original implementation had.
        if (empty($parts) && $delta_text !== '') {
            $parts[] = new MessagePart($delta_text);
        }

        if (empty($parts)) {
            throw new RuntimeException(
                'ChatGPT Codex SSE stream finished without producing any usable output.'
            );
        }

        $finishReason = $hasFunctionCall
            ? FinishReasonEnum::toolCalls()
            : FinishReasonEnum::stop();

        $message    = new Message(MessageRoleEnum::model(), $parts);
        $candidates = [new Candidate($message, $finishReason)];

        return new GenerativeAiResult(
            $response_id !== '' ? $response_id : ('codex-' . bin2hex(random_bytes(6))),
            $candidates,
            $this->parseTokenUsage($usage),
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Converts a list of completed output items (collected from Codex's
     * `response.output_item.done` events) into a list of {@see MessagePart}s.
     *
     * Item handling:
     *
     * - `reasoning` → thought-channel MessagePart whose signature is a packed
     *   JSON blob of `{id, encrypted_content, summary}` so the original triple
     *   round-trips losslessly on the next outbound turn.
     * - `function_call` → MessagePart wrapping a {@see FunctionCall} DTO; sets
     *   the byref `$hasFunctionCall` flag so the caller can pick the right
     *   {@see FinishReasonEnum}.
     * - `message` → all `output_text` segments concatenated into one text
     *   MessagePart.
     * - Unknown types → skipped (forward compatibility).
     *
     * Parts come back in the order Codex emitted them, so reasoning naturally
     * precedes the message / function_call it preceded in the stream — which
     * is what the agent loop needs in order to surface preamble text before
     * tool calls.
     *
     * @since 1.2.0
     *
     * @param list<array<string, mixed>> $outputItems     Completed output items in stream order.
     * @param bool                       $hasFunctionCall Out: set true if at least one function_call was parsed.
     * @return list<MessagePart>
     */
    protected function parseOutputToParts(array $outputItems, bool &$hasFunctionCall): array
    {
        $parts = [];
        foreach ($outputItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? '';

            if ($type === 'reasoning') {
                $part = $this->parseReasoningOutputToPart($item);
                if ($part !== null) {
                    $parts[] = $part;
                }
                continue;
            }

            if ($type === 'function_call') {
                $part = $this->parseFunctionCallOutputToPart($item);
                if ($part !== null) {
                    $parts[] = $part;
                    $hasFunctionCall = true;
                }
                continue;
            }

            if ($type === 'message') {
                $text = '';
                $content = $item['content'] ?? [];
                if (is_array($content)) {
                    foreach ($content as $segment) {
                        if (!is_array($segment)) {
                            continue;
                        }
                        if (
                            ($segment['type'] ?? '') === 'output_text'
                            && isset($segment['text']) && is_string($segment['text'])
                        ) {
                            $text .= $segment['text'];
                        }
                    }
                }
                if ($text !== '') {
                    $parts[] = new MessagePart($text);
                }
                continue;
            }
        }

        return $parts;
    }

    /**
     * Parses a `reasoning` output item into a thought-channel MessagePart.
     *
     * Mirrors {@see OpenAiTextGenerationModel::parseReasoningOutputToPart()}:
     * pack `{id, encrypted_content, summary}` into the MessagePart's thought
     * signature so the next outbound turn can rebuild the full reasoning
     * input item from a single SDK-recognised string.
     *
     * Returns null when the SDK lacks thought-channel support, when the item
     * carries no useful payload, or when JSON encoding fails.
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $outputItem
     * @return MessagePart|null
     */
    protected function parseReasoningOutputToPart(array $outputItem): ?MessagePart
    {
        if (!method_exists(MessagePart::class, 'getThoughtSignature')) {
            return null;
        }

        $summary = isset($outputItem['summary']) && is_array($outputItem['summary'])
            ? $outputItem['summary']
            : [];

        $signaturePayload = [];
        if (isset($outputItem['id']) && is_string($outputItem['id'])) {
            $signaturePayload['id'] = $outputItem['id'];
        }
        if (isset($outputItem['encrypted_content']) && is_string($outputItem['encrypted_content'])) {
            $signaturePayload['encrypted_content'] = $outputItem['encrypted_content'];
        }
        if (!empty($summary)) {
            $signaturePayload['summary'] = $summary;
        }

        if (empty($signaturePayload)) {
            return null;
        }

        $signature = wp_json_encode($signaturePayload);
        if (!is_string($signature)) {
            return null;
        }

        // Surface the visible summary text (if any) as the part's content so
        // tools that inspect message text see the model's reasoning summary,
        // not an empty string.
        $summaryText = '';
        foreach ($summary as $summaryItem) {
            if (is_array($summaryItem) && isset($summaryItem['text']) && is_string($summaryItem['text'])) {
                $summaryText .= $summaryItem['text'];
            }
        }

        /** @phpstan-ignore-next-line arguments.count (gated by method_exists check above) */
        return new MessagePart($summaryText, MessagePartChannelEnum::thought(), $signature);
    }

    /**
     * Parses a `function_call` output item into a MessagePart wrapping a
     * {@see FunctionCall} DTO.
     *
     * Codex returns arguments as a JSON string. An empty object `"{}"`
     * decodes to an empty array, which semantically means "no arguments"
     * and is normalised to null so the SDK does not echo `{}` on the next
     * turn (which some providers reject).
     *
     * @since n.e.x.t
     *
     * @param array<string, mixed> $outputItem
     * @return MessagePart|null
     */
    protected function parseFunctionCallOutputToPart(array $outputItem): ?MessagePart
    {
        $callId = $outputItem['call_id'] ?? null;
        $name   = $outputItem['name'] ?? null;
        if (!is_string($callId) || !is_string($name) || $callId === '' || $name === '') {
            return null;
        }

        $args = null;
        if (isset($outputItem['arguments']) && is_string($outputItem['arguments'])) {
            $decoded = json_decode($outputItem['arguments'], true);
            if (is_array($decoded) && count($decoded) > 0) {
                $args = $decoded;
            }
        }

        return new MessagePart(new FunctionCall($callId, $name, $args));
    }

    /**
     * Builds a TokenUsage DTO from a Responses-API `usage` object.
     *
     * @since 1.2.0
     *
     * @param array<string, mixed> $usage
     * @return TokenUsage
     */
    protected function parseTokenUsage(array $usage): TokenUsage
    {
        $input  = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $total  = (int) ($usage['total_tokens'] ?? ($input + $output));
        return new TokenUsage($input, $output, $total);
    }
}
