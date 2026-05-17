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
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
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
        ];

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $body_params['temperature'] = $temperature;
        }
        $topP = $config->getTopP();
        if ($topP !== null) {
            $body_params['top_p'] = $topP;
        }

        // Custom options last-write-wins to allow callers to override
        // anything except the wire-mandatory fields.
        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (in_array($key, ['model', 'input', 'stream', 'store'], true)) {
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
     * USER messages become `input_text` parts; MODEL messages become
     * `output_text` parts so multi-turn conversations preserve history.
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
            $parts   = [];

            foreach ($message->getParts() as $part) {
                $converted = $this->convertPartToInput($part, $is_user);
                if ($converted !== null) {
                    $parts[] = $converted;
                }
            }

            if (empty($parts)) {
                continue;
            }

            $input[] = [
                'role'    => $is_user ? 'user' : 'assistant',
                'content' => $parts,
            ];
        }

        return $input;
    }

    /**
     * Converts a single SDK MessagePart to a Responses-API content entry.
     *
     * Only text parts are supported in this first revision; file and
     * function parts return null (silently skipped) so a prompt with
     * mixed content still sends its text portions instead of failing.
     *
     * @since 1.2.0
     *
     * @param MessagePart $part   The part to convert.
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
     * Parses the SSE response body into a GenerativeAiResult.
     *
     * The SSE stream from Codex follows the OpenAI Responses API spec:
     * a series of `event:`/`data:` pairs, ending with a
     * `response.completed` event whose `data.response` carries the full
     * final response object (output items, usage, status). We walk the
     * stream once, locate that event, and build the result from it. As
     * a defensive fallback for partial streams we also aggregate
     * `response.output_text.delta` chunks so we can still surface
     * something useful when the completed event is absent (e.g. mid-stream
     * connection drop).
     *
     * @since 1.2.0
     *
     * @param string $body Raw SSE body.
     * @return GenerativeAiResult
     *
     * @throws RuntimeException If the body contains no usable text.
     */
    protected function parseSseToResult(string $body): GenerativeAiResult
    {
        $delta_text         = '';
        $completed_response = null;
        $response_id        = '';

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

            if ($event_name === 'response.output_text.delta'
                && isset($data['delta']) && is_string($data['delta'])
            ) {
                $delta_text .= $data['delta'];
            }
            if ($event_name === 'response.completed' && isset($data['response'])) {
                $completed_response = $data['response'];
            }
            if ($response_id === '' && isset($data['response']['id']) && is_string($data['response']['id'])) {
                $response_id = $data['response']['id'];
            }
        }

        $final_text  = '';
        $token_usage = new TokenUsage(0, 0, 0);

        if (is_array($completed_response)) {
            $final_text  = $this->extractTextFromCompletedResponse($completed_response);
            $token_usage = $this->parseTokenUsage(
                is_array($completed_response['usage'] ?? null) ? $completed_response['usage'] : []
            );
        }
        if ($final_text === '' && $delta_text !== '') {
            $final_text = $delta_text;
        }

        if ($final_text === '') {
            throw new RuntimeException(
                'ChatGPT Codex SSE stream finished without producing any text output.'
            );
        }

        $message    = new Message(
            MessageRoleEnum::model(),
            [new MessagePart($final_text)]
        );
        $candidates = [new Candidate($message, FinishReasonEnum::stop())];

        return new GenerativeAiResult(
            $response_id !== '' ? $response_id : ('codex-' . bin2hex(random_bytes(6))),
            $candidates,
            $token_usage,
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Walks the `output[]` array from a `response.completed` payload
     * and concatenates every `output_text` segment in order.
     *
     * @since 1.2.0
     *
     * @param array<string, mixed> $completed
     * @return string
     */
    protected function extractTextFromCompletedResponse(array $completed): string
    {
        $text = '';
        $output = $completed['output'] ?? [];
        if (!is_array($output)) {
            return '';
        }
        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }
            $content = $item['content'] ?? [];
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $segment) {
                if (!is_array($segment)) {
                    continue;
                }
                if (($segment['type'] ?? '') === 'output_text' && isset($segment['text']) && is_string($segment['text'])) {
                    $text .= $segment['text'];
                }
            }
        }
        return $text;
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
