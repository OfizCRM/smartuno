<?php

namespace Tests\Unit;

use App\Modules\AI\Services\Llm\AnthropicProvider;
use App\Modules\AI\Services\Llm\GeminiProvider;
use App\Modules\AI\Services\Llm\OpenAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Structured output, three ways.
 *
 * The point of these tests is that a schema handed to chat() actually lands in
 * the request body — in the one place that provider reads it from. Nothing in
 * this codebase used to check that, which is how EmailAiController passed a
 * system prompt into $opts for months without a single test noticing that all
 * three providers dropped it on the floor.
 */
class LlmStructuredOutputTest extends TestCase
{
    /** The definition a caller hands over: plain JSON Schema, warts and all. */
    private function definition(): array
    {
        return [
            'type' => 'object',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'catalog_item_id' => ['type' => 'integer'],
                            'qty' => ['type' => 'number'],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
                'reason' => ['type' => 'string', 'description' => 'De ce aceste produse.'],
            ],
        ];
    }

    /** @param array<string, mixed> $extra */
    private function schema(array $extra = []): array
    {
        return $extra + [
            // Deliberately not a legal schema/tool name: both APIs 400 on a space
            // or a bang, and the providers are supposed to scrub it.
            'name' => 'offer draft!',
            'description' => 'The drafted offer.',
            'schema' => $this->definition(),
        ];
    }

    /**
     * Fake one endpoint and hand back the decoded request body.
     *
     * @param  array<string, mixed>  $reply
     */
    private function capture(string $url, array $reply, callable $call): array
    {
        $sent = null;

        Http::fake([$url => function ($request) use (&$sent, $reply) {
            $sent = json_decode($request->body(), true);

            return Http::response($reply);
        }]);

        $call();

        $this->assertIsArray($sent, 'the provider never sent a request');

        return $sent;
    }

    private function openAi(): OpenAiProvider
    {
        return new OpenAiProvider('sk-test', 'gpt-4o-mini');
    }

    private function anthropic(): AnthropicProvider
    {
        return new AnthropicProvider('sk-ant-test', 'claude-sonnet-4-5');
    }

    private function gemini(): GeminiProvider
    {
        return new GeminiProvider('g-test', 'gemini-1.5-flash');
    }

    /** @param array<string, mixed> $overrides */
    private function openAiReply(array $overrides = []): array
    {
        return $overrides + [
            'choices' => [['message' => ['content' => '{"lines":[]}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'model' => 'gpt-4o-mini',
        ];
    }

    // ---------------------------------------------------------------- OpenAI

    public function test_openai_puts_the_schema_in_response_format(): void
    {
        $sent = $this->capture(
            'api.openai.com/v1/chat/completions',
            $this->openAiReply(),
            fn () => $this->openAi()->chat([['role' => 'user', 'content' => 'hi']], [], null, $this->schema()),
        );

        $this->assertSame('json_schema', $sent['response_format']['type']);
        $this->assertSame($this->definition(), $sent['response_format']['json_schema']['schema']);
        $this->assertSame('The drafted offer.', $sent['response_format']['json_schema']['description']);

        // A space and a bang would be a 400. Scrubbed, not passed through.
        $this->assertSame('offer_draft_', $sent['response_format']['json_schema']['name']);

        // Strict is opt-in: an untightened schema must not claim to be strict.
        $this->assertFalse($sent['response_format']['json_schema']['strict']);
    }

    public function test_openai_strict_mode_closes_every_object_in_the_schema(): void
    {
        $sent = $this->capture(
            'api.openai.com/v1/chat/completions',
            $this->openAiReply(),
            fn () => $this->openAi()->chat([['role' => 'user', 'content' => 'hi']], [], null, $this->schema(['strict' => true])),
        );

        $schema = $sent['response_format']['json_schema']['schema'];

        $this->assertTrue($sent['response_format']['json_schema']['strict']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['lines', 'reason'], $schema['required']);

        // ...all the way down, including through an array's items.
        $line = $schema['properties']['lines']['items'];
        $this->assertFalse($line['additionalProperties'], 'strict mode 400s on a nested open object');
        $this->assertSame(['catalog_item_id', 'qty'], $line['required']);
    }

    public function test_openai_sends_the_system_parameter_as_the_first_turn(): void
    {
        $sent = $this->capture(
            'api.openai.com/v1/chat/completions',
            $this->openAiReply(),
            fn () => $this->openAi()->chat(
                [['role' => 'system', 'content' => 'from the caller'], ['role' => 'user', 'content' => 'hi']],
                [],
                'from the parameter',
            ),
        );

        // Both survive. A caller that already puts a system turn in $messages —
        // CatalogDescriber, ChatbotRunner — keeps working untouched.
        $this->assertSame(['system', 'system', 'user'], array_column($sent['messages'], 'role'));
        $this->assertSame('from the parameter', $sent['messages'][0]['content']);
        $this->assertSame('from the caller', $sent['messages'][1]['content']);
    }

    public function test_openai_reports_a_truncated_answer(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAiReply([
            'choices' => [['message' => ['content' => '{"lines":['], 'finish_reason' => 'length']],
        ]))]);

        $response = $this->openAi()->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($response->truncated);
        $this->assertSame('length', $response->finishReason);
    }

    // ------------------------------------------------------------- Anthropic

    public function test_anthropic_forces_a_single_tool_because_there_is_no_response_format(): void
    {
        $sent = $this->capture(
            'api.anthropic.com/v1/messages',
            [
                'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'offer_draft_', 'input' => ['lines' => []]]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                'model' => 'claude-sonnet-4-5',
                'stop_reason' => 'tool_use',
            ],
            fn () => $this->anthropic()->chat([['role' => 'user', 'content' => 'hi']], [], null, $this->schema()),
        );

        $this->assertArrayNotHasKey('response_format', $sent, 'the Messages API has no such field');
        $this->assertCount(1, $sent['tools']);
        $this->assertSame('offer_draft_', $sent['tools'][0]['name']);
        $this->assertSame($this->definition(), $sent['tools'][0]['input_schema']);
        $this->assertSame('The drafted offer.', $sent['tools'][0]['description']);

        // Offering the tool is not enough — the model has to be left no other move.
        $this->assertSame(['type' => 'tool', 'name' => 'offer_draft_'], $sent['tool_choice']);
    }

    public function test_anthropic_returns_the_tool_input_as_a_json_string(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'offer_draft_', 'input' => [
                'lines' => [['catalog_item_id' => 7, 'qty' => 2]],
                'reason' => 'Se potrivește cerinței.',
            ]]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'model' => 'claude-sonnet-4-5',
            'stop_reason' => 'tool_use',
        ])]);

        $response = $this->anthropic()->chat([['role' => 'user', 'content' => 'hi']], [], null, $this->schema());

        // The block holds a decoded object, not text: content[0]['text'] is absent.
        // Callers must see the same JSON string every other provider hands back.
        $this->assertSame(
            ['lines' => [['catalog_item_id' => 7, 'qty' => 2]], 'reason' => 'Se potrivește cerinței.'],
            json_decode($response->content, true),
        );

        // A forced call ends on stop_reason tool_use. That is a finished answer.
        $this->assertFalse($response->truncated);
        $this->assertSame('tool_use', $response->finishReason);
    }

    public function test_anthropic_merges_the_system_parameter_with_a_system_turn(): void
    {
        $sent = $this->capture(
            'api.anthropic.com/v1/messages',
            [
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                'model' => 'claude-sonnet-4-5',
                'stop_reason' => 'end_turn',
            ],
            fn () => $this->anthropic()->chat(
                [['role' => 'system', 'content' => 'from the caller'], ['role' => 'user', 'content' => 'hi']],
                [],
                'from the parameter',
            ),
        );

        $this->assertSame("from the parameter\n\nfrom the caller", $sent['system']);
        $this->assertSame([['role' => 'user', 'content' => 'hi']], $sent['messages']);
    }

    public function test_anthropic_reports_a_truncated_answer(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"lines":[']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 512],
            'model' => 'claude-sonnet-4-5',
            'stop_reason' => 'max_tokens',
        ])]);

        $response = $this->anthropic()->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($response->truncated);
        $this->assertSame('max_tokens', $response->finishReason);
    }

    // ---------------------------------------------------------------- Gemini

    public function test_gemini_puts_the_schema_in_generation_config_with_a_json_mime_type(): void
    {
        $sent = $this->capture(
            'generativelanguage.googleapis.com/*',
            [
                'candidates' => [['content' => ['parts' => [['text' => '{"lines":[]}']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ],
            fn () => $this->gemini()->chat([['role' => 'user', 'content' => 'hi']], [], null, $this->schema()),
        );

        $config = $sent['generationConfig'];

        // Without the MIME type the schema is accepted and then quietly ignored.
        $this->assertSame('application/json', $config['responseMimeType']);

        $schema = $config['responseSchema'];

        // Not JSON Schema — an OpenAPI Schema object: enum names in capitals, and
        // a 400 for any field it does not know.
        $this->assertSame('OBJECT', $schema['type']);
        $this->assertArrayNotHasKey('$schema', $schema);
        $this->assertSame('ARRAY', $schema['properties']['lines']['type']);
        $this->assertSame('OBJECT', $schema['properties']['lines']['items']['type']);
        $this->assertArrayNotHasKey('additionalProperties', $schema['properties']['lines']['items']);
        $this->assertSame('INTEGER', $schema['properties']['lines']['items']['properties']['catalog_item_id']['type']);

        // Fields Gemini does understand are kept.
        $this->assertSame('De ce aceste produse.', $schema['properties']['reason']['description']);
    }

    public function test_gemini_sends_the_system_parameter_as_a_system_instruction(): void
    {
        $sent = $this->capture(
            'generativelanguage.googleapis.com/*',
            [
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
            fn () => $this->gemini()->chat(
                [['role' => 'system', 'content' => 'from the caller'], ['role' => 'user', 'content' => 'hi']],
                [],
                'from the parameter',
            ),
        );

        $this->assertSame(
            "from the parameter\n\nfrom the caller",
            $sent['systemInstruction']['parts'][0]['text'],
        );
        $this->assertSame([['role' => 'user', 'parts' => [['text' => 'hi']]]], $sent['contents']);
    }

    public function test_gemini_reports_a_truncated_answer(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"lines":[']]], 'finishReason' => 'MAX_TOKENS']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 1024],
        ])]);

        $response = $this->gemini()->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertTrue($response->truncated);
        $this->assertSame('MAX_TOKENS', $response->finishReason);
    }

    // -------------------------------------------------------- no regressions

    public function test_a_plain_call_sends_exactly_what_it_sent_before(): void
    {
        $openAi = $this->capture(
            'api.openai.com/v1/chat/completions',
            $this->openAiReply(),
            fn () => $this->openAi()->chat([['role' => 'user', 'content' => 'hi']], ['max_tokens' => 512]),
        );

        $this->assertSame([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'max_tokens' => 512,
            'temperature' => 0.7,
        ], $openAi, 'an existing caller must send byte-for-byte what it sent before');

        $anthropic = $this->capture(
            'api.anthropic.com/v1/messages',
            [
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                'model' => 'claude-sonnet-4-5',
                'stop_reason' => 'end_turn',
            ],
            fn () => $this->anthropic()->chat([['role' => 'user', 'content' => 'hi']], ['max_tokens' => 512]),
        );

        $this->assertSame([
            'model' => 'claude-sonnet-4-5',
            'max_tokens' => 512,
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ], $anthropic);

        $gemini = $this->capture(
            'generativelanguage.googleapis.com/*',
            [
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
            ],
            fn () => $this->gemini()->chat([['role' => 'user', 'content' => 'hi']], ['max_tokens' => 512]),
        );

        $this->assertSame([
            'contents' => [['role' => 'user', 'parts' => [['text' => 'hi']]]],
            'generationConfig' => ['maxOutputTokens' => 512],
        ], $gemini);
    }

    public function test_a_plain_call_is_not_reported_as_truncated(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->openAiReply())]);

        $response = $this->openAi()->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertFalse($response->truncated);
        $this->assertSame('stop', $response->finishReason);
        $this->assertSame('{"lines":[]}', $response->content);
    }
}
