<?php

declare(strict_types=1);

namespace Ph20sr\RateLimit\Store;

/** Para testes e processos de longa duração (workers, Swoole, ReactPHP). */
final class MemoryStore implements Store
{
    /** @var array<string, array{tokens: float, ts: float}> */
    private array $data = [];

    public function update(string $key, callable $fn): array
    {
        return $this->data[$key] = $fn($this->data[$key] ?? null);
    }

    public function delete(string $key): void
    {
        unset($this->data[$key]);
    }
}
