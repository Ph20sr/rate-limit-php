<?php

declare(strict_types=1);

namespace Ph20sr\RateLimit;

final class Result
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        /** Segundos até poder tentar de novo (0 se permitido). */
        public readonly int $retryAfter,
        /** Segundos até o balde encher de novo. */
        public readonly int $resetAfter,
    ) {
    }

    /**
     * Cabeçalhos HTTP padrão (RateLimit-* do draft da IETF + Retry-After).
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'RateLimit-Limit' => (string) $this->limit,
            'RateLimit-Remaining' => (string) $this->remaining,
            'RateLimit-Reset' => (string) $this->resetAfter,
        ];
        if (!$this->allowed) {
            $headers['Retry-After'] = (string) $this->retryAfter;
        }
        return $headers;
    }

    /** Envia os cabeçalhos (e o 429, se bloqueado). */
    public function send(): void
    {
        foreach ($this->headers() as $name => $value) {
            header("{$name}: {$value}");
        }
        if (!$this->allowed) {
            http_response_code(429);
        }
    }
}
