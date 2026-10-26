<?php
declare(strict_types=1);

namespace Quasar\Ai;

/** Respuesta de un LlmProvider: el JSON y el consumo (para el coste). */
final class LlmResponse
{
    /** @param array<string,mixed>|null $data JSON de la respuesta; null si el modelo se negó (refusal) */
    public function __construct(
        public readonly ?array $data,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {
    }
}
