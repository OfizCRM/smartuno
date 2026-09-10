<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\Llm\AnthropicProvider;
use App\Modules\AI\Services\Llm\GeminiProvider;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\LlmProviderInterface;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\Llm\OpenAiProvider;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Support\Facades\Log;

/**
 * The one door to a paid LLM. Everything that spends provider money goes
 * through here so the call is metered (UsageMeter) and audited (ai_runs).
 * Callers must not reach for LlmManager directly — that path bills nobody and
 * leaves no row.
 *
 * Provider errors are deliberately NOT caught by chat(). A caller that wants to
 * degrade gracefully can do so where it knows what a sensible degradation is;
 * swallowing the exception here would turn a failed, unbilled call into a
 * silent success. structured() is the documented exception, and it does the
 * opposite of swallowing: it converts every failure into a short reason code
 * its caller can store and translate, because a provider's own words carry the
 * API key back out — OpenAI answers a bad key with "Incorrect API key
 * provided: sk-…", which is fine in a log and unacceptable in a column.
 *
 * A failure is now audited as well as a success. Before, chat() and embed()
 * both threw before reaching the ai_runs insert, so the only calls on record
 * were the ones that worked — which is the wrong half. A dead key, a rate limit
 * and a sixty-second timeout each left nothing behind anywhere.
 */
class LlmGateway
{
    /**
     * Output budget for the first structured attempt.
     *
     * ChatbotRunner asks for 512. A structured answer that carries an
     * interpretation of the customer's message, a handful of catalogue lines
     * and one justification each does not fit in 512, and JSON cut in half is
     * not visibly cut in half the way a sentence is: it either fails to decode
     * or, worse, decodes into a shorter well-formed answer that looks complete.
     */
    private const STRUCTURED_TOKENS = 1800;

    /** The floor for the second attempt, when the first one ran out of room. */
    private const STRUCTURED_RETRY_FLOOR = 4000;

    /** How much bigger the retry gets, before the ceiling applies. */
    private const STRUCTURED_RETRY_MULTIPLIER = 3;

    /** Ceiling on any single structured completion. Past this the prompt is wrong, not the budget. */
    private const STRUCTURED_MAX_TOKENS = 8000;

    /** Nothing useful comes back under this, and a tiny budget only manufactures truncations. */
    private const STRUCTURED_MIN_TOKENS = 256;

    /** Two attempts: the original, and one at a larger budget. Then it fails, loudly. */
    private const STRUCTURED_ATTEMPTS = 2;

    /**
     * Near-deterministic. The same enquiry drafted twice should propose the same
     * products; a schema-constrained answer gains nothing from variation, and
     * the person comparing a regenerated draft against the first one loses if it
     * wanders. Read by OpenAI only — the other two providers send no temperature.
     */
    private const STRUCTURED_TEMPERATURE = 0.1;

    /**
     * The model structured() pins, per provider family.
     *
     * Two reasons this is not left to the workspace's own chat model.
     *
     * The first is a capability floor. LlmManager::build() defaults Anthropic to
     * claude-3-haiku-20240307 for every workspace that never opened the provider
     * settings screen — and that screen offers gpt-3.5-turbo, claude-3-* and
     * gemini-1.0-pro, none of which handle a JSON schema the way the current
     * models do. Reading a customer's message against a price list with
     * minimums, exclusions and bundles is not a job for the cheapest model in
     * the family.
     *
     * The second is the token budget, and it is why the Anthropic pin is a
     * Sonnet rather than something larger. AnthropicProvider sends model,
     * max_tokens, messages, system and the forced tool, and nothing else — there
     * is no parameter for thinking. On a model where thinking is on unless it is
     * switched off, those tokens come out of the same max_tokens the truncation
     * check below polices, so every draft would arrive looking cut off and every
     * draft would cost two calls. claude-sonnet-4-6 leaves thinking off unless
     * it is asked for.
     *
     * A caller that passes opts['model'] overrides all of this on purpose. An
     * unrecognised provider gets no pin at all — its own default stands, rather
     * than a guessed model id that would 404.
     */
    private const STRUCTURED_MODELS = [
        OpenAiProvider::class => 'gpt-4o',
        AnthropicProvider::class => 'claude-sonnet-4-6',
        GeminiProvider::class => 'gemini-1.5-pro',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $opts
     */
    public function chat(
        int $workspaceId,
        array $messages,
        array $opts = [],
        ?int $chatbotId = null,
        ?int $conversationId = null,
    ): LlmResponse {
        $provider = LlmManager::forWorkspace($workspaceId);

        $model = is_string($opts['model'] ?? null) && $opts['model'] !== '' ? $opts['model'] : null;
        $start = microtime(true);

        try {
            $response = $provider->chat($messages, $opts);
        } catch (\Throwable $e) {
            // The row the audit was missing. A failed call is exactly the one
            // worth having a record of — it is the shape of a dead key, a rate
            // limit or a timeout — and until now it left none.
            $this->recordFailure($workspaceId, $model, $start, $chatbotId, $conversationId, $e);

            throw $e;
        }

        $this->meterAndRecord($workspaceId, $response, $chatbotId, $conversationId);

        Log::channel('json')->info('llm.chat', [
            'workspace_id' => $workspaceId,
            'chatbot_id' => $chatbotId,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $response->latencyMs,
        ]);

        return $response;
    }

    /**
     * One metered, audited call that must come back as JSON matching $schema.
     *
     * THE SCHEMA IS A PARAMETER, NOT AN OPTION, and that is the whole point.
     * Every provider behind LlmProviderInterface reads exactly three keys out of
     * $opts and drops the rest without a word — which is how EmailAiController
     * spent months passing $opts['system'] to three providers that never sent it
     * to a model. A response_format key would have died the same silent death.
     * $schema and $system are declared parameters on chat(), carried three
     * different ways (OpenAI response_format.json_schema, Gemini
     * generationConfig.responseSchema, Anthropic a single forced tool, because
     * the Messages API has no response_format at all), and every provider hands
     * the result back as a JSON string in LlmResponse::$content so there is one
     * shape to decode here.
     *
     * WHAT IT ADDS ON TOP OF chat():
     *
     * - a tolerant decode — fence stripping, then the outermost {…} block — the
     *   shape WorkflowGenerator has been running in production and
     *   CatalogDescriber copied;
     * - truncation detection, because a JSON answer that ran out of budget is
     *   the one failure that can pass for a success. The provider's own stop
     *   reason leads (LlmResponse::$truncated), a completion that reached its
     *   cap is the backstop, and unbalanced brackets are the last resort. One
     *   retry at a larger budget, then it fails;
     * - a pinned model, because the workspace's configured chat model may not be
     *   able to honour a schema at all. See STRUCTURED_MODELS.
     *
     * FAILURE IS LOUD, AND SAFE TO STORE. Nothing here degrades into a plausible
     * answer. Every exception thrown carries one of these as its message, and
     * nothing else:
     *
     *   ai.structured.no_provider       no LLM provider configured for the workspace
     *   ai.structured.provider_failed   the call threw — network, auth, quota, timeout
     *   ai.structured.empty             the provider answered with no content
     *   ai.structured.cut_off           truncated, and still truncated after the retry
     *   ai.structured.unreadable        arrived whole, but is not JSON
     *
     * They are translation keys, they fit a string(64) column, and they cannot
     * contain a secret. The provider's own message is kept as the previous
     * exception so report() still records it in full, and is never repeated in
     * the message this method throws.
     *
     * The parameter order deliberately mirrors LlmProviderInterface::chat() —
     * schema, opts, system — so the two read the same way. Callers are expected
     * to use named arguments.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array{name?: string, description?: string, strict?: bool, schema: array<string, mixed>}  $schema
     * @param  array<string, mixed>  $opts  max_tokens, temperature and model each override a default above
     * @return array{data: array<mixed>, response: LlmResponse} the decoded payload, and the call that produced it
     *
     * @throws \RuntimeException with one of the reason codes above
     */
    public function structured(
        int $workspaceId,
        array $messages,
        array $schema,
        array $opts = [],
        ?string $system = null,
        ?int $conversationId = null,
    ): array {
        try {
            $provider = LlmManager::forWorkspace($workspaceId);
        } catch (\Throwable $e) {
            // Nothing was sent and nothing was billed, so there is no call to
            // audit: ai_runs records calls. Recording the attempt is the
            // caller's job, which is what offer_draft_attempts exists for.
            throw new \RuntimeException('ai.structured.no_provider', previous: $e);
        }

        $model = is_string($opts['model'] ?? null) && $opts['model'] !== ''
            ? $opts['model']
            : $this->pinnedModel($provider);

        $budget = $this->clampBudget((int) ($opts['max_tokens'] ?? self::STRUCTURED_TOKENS));

        for ($attempt = 1; $attempt <= self::STRUCTURED_ATTEMPTS; $attempt++) {
            $response = $this->callStructured($workspaceId, $provider, $messages, $schema, $opts, $system, $model, $budget, $conversationId);

            $raw = trim($response->content);

            // A completion that stopped because it ran out of room is not read
            // at all: truncated JSON can decode into a shorter, perfectly
            // well-formed object, and half an offer presented as a whole one is
            // the failure this method exists to prevent.
            if (! $this->ranOutOfRoom($response, $budget)) {
                // An empty answer is not a short answer — a larger budget cannot
                // fill it, so it fails here rather than costing a second call.
                if ($raw === '') {
                    throw new \RuntimeException('ai.structured.empty');
                }

                $data = $this->decode($raw);

                if ($data !== null) {
                    return ['data' => $data, 'response' => $response];
                }

                // It did not decode, and neither the stop reason nor the token
                // count says it was cut off, so the brackets get the last word.
                // Balanced means the model answered with something that is
                // simply not JSON, and no budget fixes prose.
                if (! $this->unbalanced($raw)) {
                    throw new \RuntimeException('ai.structured.unreadable');
                }
            }

            $budget = $this->retryBudget($budget);
        }

        throw new \RuntimeException('ai.structured.cut_off');
    }

    /**
     * @param  string[]  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(int $workspaceId, array $texts): array
    {
        // Use embed-specific provider (skips Anthropic which has no embedding support)
        $provider = LlmManager::forWorkspaceEmbed($workspaceId);
        $tokenEstimate = array_sum(array_map(fn ($t) => (int) ceil(strlen($t) / 4), $texts));
        $start = microtime(true);

        try {
            $embeddings = $provider->embed($texts);
        } catch (\Throwable $e) {
            $this->recordFailure($workspaceId, 'embed', $start, null, null, $e);

            throw $e;
        }

        UsageMeter::track($workspaceId, 'ai_tokens', $tokenEstimate);

        $this->recordRun($workspaceId, [
            'chatbot_id' => null,
            'conversation_id' => null,
            'prompt_tokens' => $tokenEstimate,
            'completion_tokens' => 0,
            'cost_cents' => 0,
            'latency_ms' => (int) ((microtime(true) - $start) * 1000),
            'model' => 'embed',
            'status' => 'ok',
        ]);

        return $embeddings;
    }

    /**
     * One structured attempt: metered and audited exactly like chat(), whether
     * it succeeds or not.
     *
     * Both attempts are paid for, so both are metered and both leave a row. A
     * retry that was invisible in ai_runs would make the feature look half as
     * expensive as it is, and this one runs on the inbound message path.
     *
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array{name?: string, description?: string, strict?: bool, schema: array<string, mixed>}  $schema
     * @param  array<string, mixed>  $opts
     */
    private function callStructured(
        int $workspaceId,
        LlmProviderInterface $provider,
        array $messages,
        array $schema,
        array $opts,
        ?string $system,
        ?string $model,
        int $budget,
        ?int $conversationId,
    ): LlmResponse {
        $callOpts = $opts;
        $callOpts['max_tokens'] = $budget;
        $callOpts['temperature'] = $opts['temperature'] ?? self::STRUCTURED_TEMPERATURE;

        if ($model !== null) {
            $callOpts['model'] = $model;
        } else {
            unset($callOpts['model']);
        }

        $start = microtime(true);

        try {
            $response = $provider->chat($messages, $callOpts, system: $system, schema: $schema);
        } catch (\Throwable $e) {
            $this->recordFailure($workspaceId, $model, $start, null, $conversationId, $e);

            throw new \RuntimeException('ai.structured.provider_failed', previous: $e);
        }

        $this->meterAndRecord($workspaceId, $response, null, $conversationId);

        Log::channel('json')->info('llm.structured', [
            'workspace_id' => $workspaceId,
            'conversation_id' => $conversationId,
            'model' => $response->model,
            'max_tokens' => $budget,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'finish_reason' => $response->finishReason,
            'truncated' => $response->truncated,
            'latency_ms' => $response->latencyMs,
        ]);

        return $response;
    }

    /** The model structured() insists on for this provider, or null to leave the provider's own. */
    private function pinnedModel(LlmProviderInterface $provider): ?string
    {
        return self::STRUCTURED_MODELS[$provider::class] ?? null;
    }

    private function clampBudget(int $budget): int
    {
        return max(self::STRUCTURED_MIN_TOKENS, min(self::STRUCTURED_MAX_TOKENS, $budget));
    }

    private function retryBudget(int $budget): int
    {
        return $this->clampBudget(max(
            self::STRUCTURED_RETRY_FLOOR,
            $budget * self::STRUCTURED_RETRY_MULTIPLIER,
        ));
    }

    /**
     * The answer stopped because it ran out of budget, not because it was done.
     *
     * The provider's own stop reason leads — OpenAI finish_reason 'length',
     * Anthropic stop_reason 'max_tokens', Gemini finishReason 'MAX_TOKENS', all
     * three now carried on LlmResponse. The token count stays as a backstop for
     * the response that arrives with no finish reason at all: a completion that
     * reached its cap did not choose to stop there.
     */
    private function ranOutOfRoom(LlmResponse $response, int $budget): bool
    {
        return $response->truncated
            || ($response->completionTokens > 0 && $response->completionTokens >= $budget);
    }

    /**
     * More brackets opened than closed: the answer stops mid-structure.
     *
     * The last resort, for a provider that reports neither a stop reason nor a
     * usable token count. It is only ever asked about an answer that has already
     * failed to decode, so a bracket inside a string value cannot turn a good
     * draft into a retry — the worst it does is send a piece of malformed prose
     * round once more before failing as "cut off" instead of "unreadable".
     */
    private function unbalanced(string $raw): bool
    {
        return substr_count($raw, '{') + substr_count($raw, '[')
             > substr_count($raw, '}') + substr_count($raw, ']');
    }

    /**
     * Read the JSON out of the model's reply, tolerating a code fence or a
     * sentence wrapped around it — the shape WorkflowGenerator has been running
     * in production, and CatalogDescriber after it.
     *
     * @return array<mixed>|null
     */
    private function decode(string $raw): ?array
    {
        $clean = (string) preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $clean = trim((string) preg_replace('/\s*```$/', '', $clean));

        $decoded = json_decode($clean, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to the outermost {...} block.
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($clean, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /** Bill the tokens and file the run that came back. */
    private function meterAndRecord(int $workspaceId, LlmResponse $response, ?int $chatbotId, ?int $conversationId): void
    {
        UsageMeter::track($workspaceId, 'ai_tokens', $response->promptTokens + $response->completionTokens);

        $this->recordRun($workspaceId, [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cost_cents' => 0,
            'latency_ms' => $response->latencyMs,
            'model' => $response->model,
            'status' => 'ok',
        ]);
    }

    /**
     * File the run that did not come back.
     *
     * Nothing is metered: no provider here reports tokens on a failed call, and
     * a guessed number in the admin AI-cost report is worse than a missing one.
     * The row still carries the latency, which is what separates an instant 401
     * from a sixty-second timeout.
     *
     * The exception message is deliberately absent from the log line. OpenAI
     * answers a bad key with "Incorrect API key provided: sk-…" — the key
     * itself, in the body — and this channel goes wherever the json log goes.
     * The class name and the latency say what happened; the message reaches
     * report() through the exception that is still being thrown.
     */
    private function recordFailure(
        int $workspaceId,
        ?string $model,
        float $start,
        ?int $chatbotId,
        ?int $conversationId,
        \Throwable $e,
    ): void {
        $latency = (int) ((microtime(true) - $start) * 1000);

        $this->recordRun($workspaceId, [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'cost_cents' => 0,
            'latency_ms' => $latency,
            'model' => $model,
            'status' => 'error',
        ]);

        Log::channel('json')->warning('llm.failed', [
            'workspace_id' => $workspaceId,
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'model' => $model,
            'exception' => $e::class,
            'latency_ms' => $latency,
        ]);
    }

    /**
     * Write the audit row for one provider call, attributed to its tenant.
     *
     * workspace_id is force-filled rather than passed with the rest, because it
     * is not in AiRun::$fillable and tenant attribution must not depend on that
     * list. Before this column existed the only way to tell whose spend a run
     * was came from joining chatbot_id to ai_chatbots — which attributed
     * nothing at all for embeddings or for any non-chatbot caller.
     *
     * cost_cents stays 0. Nothing in this codebase holds per-model prices — no
     * config, no table, no provider response field — and inventing a rate would
     * put a number that looks authoritative into the admin AI-cost report and
     * the weekly digest. A zero is visibly missing data; a guess is not. When
     * real prices are configured, this is the single place that fills it in.
     *
     * model is cut to the column's 64 characters here rather than trusted to
     * arrive short: it can now be a model id a caller chose, and a long one
     * would abort on a call that has already been paid for.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function recordRun(int $workspaceId, array $attributes): void
    {
        if (is_string($attributes['model'] ?? null)) {
            $attributes['model'] = mb_substr($attributes['model'], 0, 64);
        }

        (new AiRun($attributes))
            ->forceFill(['workspace_id' => $workspaceId])
            ->save();
    }
}
