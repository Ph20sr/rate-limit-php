<?php

declare(strict_types=1);

namespace Ph20sr\RateLimit\Store;

interface Store
{
    /**
     * Lê o estado da chave, aplica $fn e grava o resultado de forma ATÔMICA
     * (duas requisições simultâneas não podem ler o mesmo estado).
     *
     * @param callable(?array{tokens: float, ts: float}): array{tokens: float, ts: float} $fn
     * @return array{tokens: float, ts: float}
     */
    public function update(string $key, callable $fn): array;

    public function delete(string $key): void;
}
