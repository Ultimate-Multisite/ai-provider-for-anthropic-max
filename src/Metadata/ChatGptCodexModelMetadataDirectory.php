<?php
/**
 * Model metadata directory for ChatGPT Codex.
 *
 * The Codex backend has NO public model-list endpoint. We hardcode the
 * model whitelist that live-tested 200 OK against
 * `https://chatgpt.com/backend-api/codex/responses` with a
 * ChatGPT-Account OAuth token.
 *
 * @since 1.2.0
 *
 * @package AnthropicMaxAiProvider
 */

declare(strict_types=1);

namespace AnthropicMaxAiProvider\Metadata;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Hardcoded Codex model directory.
 *
 * @since 1.2.0
 */
class ChatGptCodexModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory
{
    /**
     * Model whitelist proven to return 200 OK from the Codex backend.
     *
     * Tested against `https://chatgpt.com/backend-api/codex/responses`
     * with a real ChatGPT Plus/Pro/Team OAuth token. The full set of
     * model ids the upstream opencode CLI references is
     * `gpt-5.5, gpt-5.5-fast, gpt-5.5-pro, gpt-5.4, gpt-5.4-fast,
     * gpt-5.4-mini, gpt-5.4-mini-fast, gpt-5.3-codex,
     * gpt-5.3-codex-spark, gpt-5.2`, but the OAuth path rejects the
     * `*-fast`, `*-pro`, `gpt-5.3-codex`, and `gpt-5.2` ids as "not
     * supported when using Codex with a ChatGPT account". Only the working
     * ids below are exposed; if future Codex deployments unlock more ids, add
     * them via the `anthropic_max_chatgpt_codex_models` filter. GPT-5.5 is
     * listed first so SDK consumers that default to the first advertised model
     * prefer it when the site has not saved an explicit model choice.
     *
     * @var array<int, array{id: string, name: string}>
     */
    private const MODELS = [
        ['id' => 'gpt-5.5',      'name' => 'GPT-5.5 (Codex)'],
        ['id' => 'gpt-5.4',      'name' => 'GPT-5.4 (Codex)'],
        ['id' => 'gpt-5.4-mini', 'name' => 'GPT-5.4 mini (Codex)'],
        [
            'id'   => 'gpt-5.3-codex-spark',
            'name' => 'GPT-5.3 Codex Spark (Codex)',
        ],
    ];

    /**
     * Returns the map of model id to ModelMetadata. Synchronous and
     * network-free — the SDK availability check therefore succeeds the
     * moment the OpenAI OAuth pool has at least one account.
     *
     * @since 1.2.0
     *
     * @return array<string, ModelMetadata>
     */
    protected function sendListModelsRequest(): array
    {
        $capabilities = [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];

        // Codex Responses API supports a narrow subset of options.
        // Notably absent: maxTokens (Codex rejects `max_output_tokens`
        // as Unsupported), stopSequences (silently ignored), and
        // structured outputs (no public schema today).
        $options = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(
                OptionEnum::inputModalities(),
                [
                    [ModalityEnum::text()],
                ]
            ),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];

        $models = self::MODELS;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('anthropic_max_chatgpt_codex_models', $models);
            if (is_array($filtered) && !empty($filtered)) {
                $models = $filtered;
            }
        }

        $map = [];
        foreach ($models as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $id          = (string) $row['id'];
            $name        = (string) ($row['name'] ?? $id);
            $map[$id]    = new ModelMetadata($id, $name, $capabilities, $options);
        }

        return $map;
    }
}
