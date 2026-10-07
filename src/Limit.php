<?php

declare(strict_types=1);

namespace Ph20sr\RateLimit;

/**
 * Regra de limite como balde de fichas: cabem `capacity` fichas (a rajada
 * máxima) e ele reabastece `refillPerSecond` fichas por segundo (a média).
 */
final class Limit
{
    public function __construct(
        public readonly int $capacity,
        public readonly float $refillPerSecond,
    ) {
        if ($capacity < 1 || $refillPerSecond <= 0) {
            throw new \InvalidArgumentException('capacity deve ser >= 1 e refillPerSecond > 0');
        }
    }

    public static function perSecond(int $n): self
    {
        return new self($n, $n);
    }

    public static function perMinute(int $n): self
    {
        return new self($n, $n / 60);
    }

    public static function perHour(int $n): self
    {
        return new self($n, $n / 3600);
    }

    public static function perDay(int $n): self
    {
        return new self($n, $n / 86400);
    }

    /** Ex.: 100 por minuto, mas aceita rajadas de até 20 de uma vez. */
    public function withBurst(int $burst): self
    {
        return new self($burst, $this->refillPerSecond);
    }
}
