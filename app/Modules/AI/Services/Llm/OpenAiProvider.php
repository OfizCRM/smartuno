<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class OpenAiProvider implements LlmProviderInterface
{
    private const BASE = 'https://api.openai.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'gpt-4o-mini',
        private readonly string $embedModel = 'text-embedding-3-small',
        private readonly ?string $organization = null,
    ) {}

    public function chat(array $messages, array $opts = [], ?string $system = null, ?array $schema = null): LlmResponse
    {
        $start = microtime(true);
        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        // OpenAI has no separate system field: the instruction is the first turn.
        // Callers that already put a 'system' role in $messages keep theirs — this
        // one goes in front of it.
        if ($system !== null && $system !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $system]);
        }

        $body = [
            'model' => $opts['model'] ?? $this->chatModel,
            'messages' => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 1024,
            'temperature' => $opts['temperature'] ?? 0.7,
        ];

        if ($schema !== null) {
            $body['response_format'] = $this->responseFormat($schema);
        }

        $resp = Http::withHeaders($headers)->retry(2, 500)->timeout(60)->post(self::BASE.'/chat/completions', $body);

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenAI chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);
        $finish = $json['choices'][0]['finish_reason'] ?? null;

        return new LlmResponse(
            content: $json['choices'][0]['message']['content'] ?? '',
            promptTokens: $json['usage']['prompt_tokens'] ?? 0,
            completionTokens: $json['usage']['completion_tokens'] ?? 0,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
            truncated: $finish === 'length',
            finishReason: is_string($finish) ? $finish : null,
        );
    }

    public function embed(array $texts): array
    {
        $resp = Http::withToken($this->apiKey)->retry(2, 500)->timeout(30)->post(self::BASE.'/embeddings', [
            'model' => $this->embedModel,
            'input' => $texts,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenAI embed failed: '.$resp->body());
        }

        return array_column($resp->json()['data'] ?? [], 'embedding');
    }

    /**
     * response_format is where OpenAI takes a schema. json_schema mode conditions
     * the answer on it; strict mode makes the API enforce it, at the price of a
     * schema shape it will otherwise reject outright — so strict is opt-in and
     * tighten() prepares the schema for it.
     *
     * @param  array{name?: string, description?: string, strict?: bool, schema: array<string, mixed>}  $schema
     * @return array<string, mixed>
     */
    private function responseFormat(array $schema): array
    {
        $strict = (bool) ($schema['strict'] ?? false);

        $jsonSchema = [
            'name' => $this->schemaName($schema['name'] ?? 'response'),
            'schema' => $strict ? $this->tighten($schema['schema']) : $schema['schema'],
            'strict' => $strict,
        ];

        if (! empty($schema['description'])) {
            $jsonSchema['description'] = $schema['description'];
        }

        return ['type' => 'json_schema', 'json_schema' => $jsonSchema];
    }

    /**
     * Strict mode refuses any object that does not close itself: additionalProperties
     * must be false and every declared property must appear in required. Applying that
     * here rather than leaving it to the caller is the difference between a schema that
     * works and a 400 in front of a customer. The pass only tightens — it never adds,
     * renames or removes a property, so what the model may return is unchanged.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function tighten(array $definition): array
    {
        if (isset($definition['properties']) && is_array($definition['properties'])) {
            $definition['additionalProperties'] = false;
            $definition['required'] = array_keys($definition['properties']);

            foreach ($definition['properties'] as $key => $property) {
                if (is_array($property)) {
                    $definition['properties'][$key] = $this->tighten($property);
                }
            }
        }

        if (isset($definition['items']) && is_array($definition['items'])) {
            $definition['items'] = $this->tighten($definition['items']);
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $branch) {
            if (! isset($definition[$branch]) || ! is_array($definition[$branch])) {
                continue;
            }
            foreach ($definition[$branch] as $index => $variant) {
                if (is_array($variant)) {
                    $definition[$branch][$index] = $this->tighten($variant);
                }
            }
        }

        return $definition;
    }

    /**
     * OpenAI accepts [a-zA-Z0-9_-]{1,64} for a schema name and 400s on anything
     * else. AnthropicProvider carries its own copy of this for tool names: the
     * two APIs happen to agree on the character class today, and one shared copy
     * would tie two independent vendor rules together.
     */
    private function schemaName(string $name): string
    {
        $safe = substr((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $name), 0, 64);

        return $safe === '' ? 'response' : $safe;
    }
}
