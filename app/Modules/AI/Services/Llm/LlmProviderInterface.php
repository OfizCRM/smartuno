<?php

namespace App\Modules\AI\Services\Llm;

interface LlmProviderInterface
{
    /**
     * One chat completion.
     *
     * $system and $schema are declared parameters rather than two more $opts
     * keys, deliberately. Every provider behind this interface reads exactly
     * three keys out of $opts — model, max_tokens, temperature — and drops
     * everything else without a word. That is how EmailAiController spent
     * months handing $opts['system'] to three providers that never passed it
     * to a model and never said so. A declared parameter cannot go missing
     * that way: a provider that does not accept it fails to load at all.
     *
     * @param  array  $messages  [['role' => 'user', 'content' => '...'], ...]
     * @param  array  $opts  model, max_tokens, temperature. Nothing else is read.
     * @param  string|null  $system  System instruction. Merged ahead of any 'system'
     *                               turn already present in $messages — both are sent, neither is dropped.
     * @param  array{name?: string, description?: string, strict?: bool, schema: array<string, mixed>}|null  $schema
     *                                                                                                                A JSON Schema the answer must match. The three providers carry it three
     *                                                                                                                different ways — OpenAI response_format.json_schema, Gemini
     *                                                                                                                generationConfig.responseSchema, Anthropic a forced tool call, because the
     *                                                                                                                Messages API has no response_format at all — but every one of them returns
     *                                                                                                                the result as a JSON string in LlmResponse::$content, so a caller decodes
     *                                                                                                                one shape whatever provider the workspace happens to be on.
     *                                                                                                                Passing this is not a guarantee: still validate what comes back.
     */
    public function chat(array $messages, array $opts = [], ?string $system = null, ?array $schema = null): LlmResponse;

    /** @param string[] $texts */
    public function embed(array $texts): array; // returns array of float[]
}
