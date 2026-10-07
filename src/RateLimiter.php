<?php

declare(strict_types=1);

namespace Ph20sr\RateLimit;

use Ph20sr\RateLimit\Store\Store;

/**
 *     $limiter = new RateLimiter(new PdoStore($pdo));
 *     $result = $limiter->attempt('login:' . RateLimiter::clientIp($_SERVER['REMOTE_ADDR']), Limit::perMinute(5));
 *     if (!$result->allowed) { $result->send(); exit('Muitas tentativas'); }
 */
final class RateLimiter
{
    /** @var \Closure(): float */
    private \Closure $clock;

    public function __construct(private readonly Store $store, ?callable $clock = null)
    {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): float => microtime(true);
    }

    /**
     * Consome `cost` fichas da chave, se houver. Uma requisição barata pode
     * custar 1 e uma cara (exportação, envio de SMS) custar 10.
     */
    public function attempt(string $key, Limit $limit, int $cost = 1): Result
    {
        if ($cost < 1 || $cost > $limit->capacity) {
            throw new \InvalidArgumentException("cost deve estar entre 1 e {$limit->capacity}");
        }
        $now = ($this->clock)();
        $allowed = false;

        $state = $this->store->update($key, function (?array $s) use ($limit, $cost, $now, &$allowed): array {
            $tokens = $s === null
                ? (float) $limit->capacity
                : min($limit->capacity, $s['tokens'] + max(0, $now - $s['ts']) * $limit->refillPerSecond);
            if ($tokens >= $cost) {
                $tokens -= $cost;
                $allowed = true;
            }
            return ['tokens' => $tokens, 'ts' => $now];
        });

        $tokens = $state['tokens'];
        return new Result(
            $allowed,
            $limit->capacity,
            (int) floor($tokens),
            $allowed ? 0 : (int) ceil(($cost - $tokens) / $limit->refillPerSecond),
            (int) ceil(($limit->capacity - $tokens) / $limit->refillPerSecond),
        );
    }

    /** Zera o limite da chave (ex.: login bem-sucedido limpa as tentativas). */
    public function reset(string $key): void
    {
        $this->store->delete($key);
    }

    /**
     * Normaliza o IP para usar como chave. IPv6 é agrupado por /64, porque
     * uma única conexão costuma ter milhões de endereços e trocar de IP a
     * cada tentativa furaria o limite.
     */
    public static function clientIp(string $ip): string
    {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return 'invalid';
        }
        if (strlen($packed) === 16) {
            return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return inet_ntop($packed);
    }
}
