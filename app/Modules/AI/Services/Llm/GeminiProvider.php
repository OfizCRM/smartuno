<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class GeminiProvider implements LlmProviderInterface
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * The subset of JSON Schema that generationConfig.responseSchema understands.
     * Anything outside it is dropped by geminiSchema() — see the note there.
     */
    private const SCHEMA_KEYS = [
        'description', 'format', 'nullable', 'enum', 'required',
        'minItems', 'maxItems', 'minimum', 'maximum', 'propertyOrdering',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'gemini-1.5-flash',
        private readonly string $embedModel = 'text-embedding-004',
    ) {}

    public function chat(array $messages, array $opts = [], ?string $system = null, ?array $schema = null): LlmResponse
    {
        $start = microtime(true);
        $model = $opts['model'] ?? $this->chatModel;

        // Extract system instruction separately; remaining turns mapped to user/model
        $systemParts = [];
        if ($system !== null && $system !== '') {
            $systemParts[] = $system;
        }

        $contents = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $systemParts[] = $m['content'];
            } else {
                $contents[] = [
                    'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ];
            }
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $opts['max_tokens'] ?? 1024],
        ];
        if ($systemParts !== []) {
            $body['systemInstruction'] = ['parts' => [['text' => implode("\n\n", $systemParts)]]];
        }

        if ($schema !== null) {
            // responseSchema on its own is ignored: Gemini only honours it when the
            // response MIME type asks for JSON. Setting one without the other is a
            // schema that silently does nothing.
            $body['generationConfig']['responseMimeType'] = 'application/json';
            $body['generationConfig']['responseSchema'] = $this->geminiSchema($schema['schema']);
        }

        $resp = Http::retry(2, 500)->timeout(60)
            ->post(self::BASE."/models/{$model}:generateContent?key={$this->apiKey}", $body);

        if (! $resp->successful()) {
            throw new \RuntimeException('Gemini chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);
        $content = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $meta = $json['usageMetadata'] ?? [];
        $finish = $json['candidates'][0]['finishReason'] ?? null;

        return new LlmResponse(
            content: $content,
            promptTokens: $meta['promptTokenCount'] ?? 0,
            completionTokens: $meta['candidatesTokenCount'] ?? 0,
            model: $model,
            latencyMs: $latency,
            truncated: $finish === 'MAX_TOKENS',
            finishReason: is_string($finish) ? $finish : null,
        );
    }

    public function embed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $requests = array_map(fn ($text) => [
            'model' => 'models/'.$this->embedModel,
            'content' => ['parts' => [['text' => $text]]],
        ], $texts);

        $resp = Http::retry(2, 500)->timeout(60)->post(
            self::BASE."/models/{$this->embedModel}:batchEmbedContents?key={$this->apiKey}",
            ['requests' => $requests]
        );

        if (! $resp->successful()) {
            throw new \RuntimeException('Gemini batch embed failed: '.$resp->body());
        }

        return array_map(
            fn ($e) => $e['values'] ?? [],
            $resp->json('embeddings', [])
        );
    }

    /**
     * responseSchema is not JSON Schema. It is an OpenAPI 3.0 Schema object: a
     * short fixed list of fields, `type` as a protobuf enum name in capitals, and
     * a 400 for anything it does not recognise — $ref, $schema, additionalProperties
     * and patternProperties included, which are exactly what a hand-written JSON
     * Schema tends to carry. So keep the fields Gemini knows and drop the rest,
     * rather than sending a schema the other two providers accept and this one
     * rejects at the worst moment.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function geminiSchema(array $definition): array
    {
        $out = [];

        if (isset($definition['type']) && is_string($definition['type'])) {
            $out['type'] = strtoupper($definition['type']);
        }

        foreach (self::SCHEMA_KEYS as $key) {
            if (array_key_exists($key, $definition)) {
                $out[$key] = $definition[$key];
            }
        }

        if (isset($definition['properties']) && is_array($definition['properties'])) {
            $properties = [];
            foreach ($definition['properties'] as $key => $property) {
                $properties[$key] = is_array($property) ? $this->geminiSchema($property) : $property;
            }
            $out['properties'] = $properties;
        }

        if (isset($definition['items']) && is_array($definition['items'])) {
            $out['items'] = $this->geminiSchema($definition['items']);
        }

        if (isset($definition['anyOf']) && is_array($definition['anyOf'])) {
            $out['anyOf'] = array_map(
                fn ($variant) => is_array($variant) ? $this->geminiSchema($variant) : $variant,
                $definition['anyOf'],
            );
        }

        return $out;
    }
}
