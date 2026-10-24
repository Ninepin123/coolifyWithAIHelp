<?php

namespace App\Services\Ai;

class ChatCompletionResult
{
    /**
     * @param  array<string, mixed>  $data  The JSON object in the answer.
     */
    public function __construct(
        public readonly array $data,
        public readonly string $content,
        public readonly ?string $model,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
    ) {}
}
