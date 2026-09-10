<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class AnthropicProvider implements LlmProviderInterface
{
    private const BASE = 'https://api.anthropic.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'claude-3-haiku-20240307',
    ) {}

    public function chat(array $messages, array $opts = [], ?string $system = null, ?array $schema = null): LlmResponse
    {
        $start = microtime(true);

        // Anthropic separates the system turn from the conversation turns
        $systemParts = [];
        if ($system !== null && $system !== '') {
            $systemParts[] = $system;
        }

        $turns = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $systemParts[] = $m['content'];
            } else {
                $turns[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }

        $body = [
            'model' => $opts['model'] ?? $this->chatModel,
            'max_tokens' => $opts['max_tokens'] ?? 1024,
            'messages' => $turns,
        ];
        if ($systemParts !== []) {
            $body['system'] = implode("\n\n", $systemParts);
        }

        $toolName = null;
        if ($schema !== null) {
            $toolName = $this->toolName($schema['name'] ?? 'respond');
            $body['tools'] = [$this->tool($toolName, $schema)];
            $body['tool_choice'] = ['type' => 'tool', 'name' => $toolName];
        }

        $resp = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->retry(2, 500)->timeout(60)->post(self::BASE.'/messages', $body);

        if (! $resp->successful()) {
            throw new \RuntimeException('Anthropic chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);
        $blocks = is_array($json['content'] ?? null) ? $json['content'] : [];
        $content = $toolName !== null ? $this->toolInput($blocks, $toolName) : $this->text($blocks);

        // stop_reason 'tool_use' is a finished answer, not a cut-off one: with
        // tool_choice forced it is the *expected* ending. Only 'max_tokens' means
        // the budget ran out mid-answer.
        $stop = $json['stop_reason'] ?? null;

        return new LlmResponse(
            content: $content,
            promptTokens: $json['usage']['input_tokens'] ?? 0,
            completionTokens: $json['usage']['output_tokens'] ?? 0,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
            truncated: $stop === 'max_tokens',
            finishReason: is_string($stop) ? $stop : null,
        );
    }

    public function embed(array $texts): array
    {
        throw new \RuntimeException('Anthropic does not support embeddings natively. Use OpenAI or Gemini.');
    }

    /**
     * The Messages API has no response_format and no json mode. The way to make
     * Claude return a fixed shape is to declare one tool whose input_schema *is*
     * the schema and then force tool_choice to it, so the only move left to the
     * model is to call that tool with a conforming argument object.
     *
     * @param  array{name?: string, description?: string, strict?: bool, schema: array<string, mixed>}  $schema
     * @return array<string, mixed>
     */
    private function tool(string $toolName, array $schema): array
    {
        $tool = [
            'name' => $toolName,
            'input_schema' => $schema['schema'],
        ];

        // The description is how the model is told what the tool is for; with a
        // forced call it is the only prose steering the arguments.
        if (! empty($schema['description'])) {
            $tool['description'] = $schema['description'];
        }

        return $tool;
    }

    /**
     * A forced tool call arrives as a tool_use block carrying the answer as a
     * decoded object, never as text — $json['content'][0]['text'] is simply absent.
     * Re-encode it so callers see the same JSON string OpenAI and Gemini hand back
     * and decode one shape.
     *
     * @param  array<mixed>  $blocks
     */
    private function toolInput(array $blocks, string $toolName): string
    {
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
                continue;
            }
            if (($block['name'] ?? null) !== $toolName) {
                continue;
            }

            $encoded = json_encode($block['input'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? '' : $encoded;
        }

        // No tool block came back, so the model answered in prose despite being
        // forced. Return that prose rather than an empty string: the caller's
        // decode then fails with something readable in front of it.
        return $this->text($blocks);
    }

    /**
     * Join every text block. A single-block reply — which is all this codebase has
     * ever seen — gives exactly the same string as reading content[0] did.
     *
     * @param  array<mixed>  $blocks
     */
    private function text(array $blocks): string
    {
        $text = '';
        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? 'text') !== 'text') {
                continue;
            }
            if (isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * Anthropic accepts [a-zA-Z0-9_-]{1,64} for a tool name and 400s on anything
     * else. OpenAiProvider keeps its own copy for schema names — the character
     * classes agree today, but they are two vendors' rules, not one.
     */
    private function toolName(string $name): string
    {
        $safe = substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $name), 0, 64);

        return $safe === '' ? 'respond' : $safe;
    }
}
