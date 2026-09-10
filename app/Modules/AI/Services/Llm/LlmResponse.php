<?php

namespace App\Modules\AI\Services\Llm;

class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $model,
        public readonly int $latencyMs,
        /**
         * The answer stopped because it ran out of token budget, not because it
         * was finished. Every provider says so — OpenAI finish_reason 'length',
         * Anthropic stop_reason 'max_tokens', Gemini finishReason 'MAX_TOKENS' —
         * and until now nothing here read any of them.
         *
         * It matters most for a JSON answer: prose cut in half is visibly cut in
         * half, but JSON cut in half either fails to decode or, worse, decodes
         * into a shorter well-formed answer that looks complete. A caller that
         * asked for $schema should treat this as a failure and retry with a
         * larger budget rather than trust a half-answer.
         */
        public readonly bool $truncated = false,
        /** The provider's own stop reason, verbatim, for logs. */
        public readonly ?string $finishReason = null,
    ) {}
}
