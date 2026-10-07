<?php

declare(strict_types=1);

namespace Ph20sr\RateLimit\Tests;

use PDO;
use Ph20sr\RateLimit\Limit;
use Ph20sr\RateLimit\RateLimiter;
use Ph20sr\RateLimit\Store\MemoryStore;
use Ph20sr\RateLimit\Store\PdoStore;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    private float $now = 1_000_000.0;

    private function limiter(?\Ph20sr\RateLimit\Store\Store $store = null): RateLimiter
    {
        return new RateLimiter($store ?? new MemoryStore(), fn (): float => $this->now);
    }

    public function testAllowsUpToCapacityThenBlocks(): void
    {
        $limiter = $this->limiter();
        $limit = Limit::perMinute(5);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->attempt('login:1.2.3.4', $limit)->allowed, "tentativa {$i}");
        }
        $blocked = $limiter->attempt('login:1.2.3.4', $limit);
        $this->assertFalse($blocked->allowed);
        $this->assertSame(0, $blocked->remaining);
        $this->assertSame(12, $blocked->retryAfter, '5/min = 1 ficha a cada 12s');
        $this->assertSame('12', $blocked->headers()['Retry-After']);
    }

    public function testRefillsOverTime(): void
    {
        $limiter = $this->limiter();
        $limit = Limit::perMinute(5);
        for ($i = 0; $i < 5; $i++) {
            $limiter->attempt('k', $limit);
        }
        $this->now += 11.9;
        $this->assertFalse($limiter->attempt('k', $limit)->allowed);
        $this->now += 0.2;
        $this->assertTrue($limiter->attempt('k', $limit)->allowed);
        $this->now += 3600;
        $this->assertSame(4, $limiter->attempt('k', $limit)->remaining, 'nunca passa da capacidade');
    }

    public function testKeysAreIndependentAndCanBeReset(): void
    {
        $limiter = $this->limiter();
        $limit = Limit::perHour(1);
        $this->assertTrue($limiter->attempt('a', $limit)->allowed);
        $this->assertFalse($limiter->attempt('a', $limit)->allowed);
        $this->assertTrue($limiter->attempt('b', $limit)->allowed);
        $limiter->reset('a');
        $this->assertTrue($limiter->attempt('a', $limit)->allowed);
    }

    public function testCostAndBurst(): void
    {
        $limiter = $this->limiter();
        $limit = Limit::perMinute(600)->withBurst(20); // 10/s em média, rajada de 20
        $this->assertSame(20, $limit->capacity);
        $this->assertTrue($limiter->attempt('export', $limit, cost: 15)->allowed);
        $denied = $limiter->attempt('export', $limit, cost: 10);
        $this->assertFalse($denied->allowed, 'sobram 5, precisa de 10');
        $this->assertSame(1, $denied->retryAfter, 'faltam 5 fichas a 10/s = 0,5s → 1s');
        $this->expectException(\InvalidArgumentException::class);
        $limiter->attempt('export', $limit, cost: 21);
    }

    public function testHeaders(): void
    {
        $result = $this->limiter()->attempt('k', Limit::perMinute(60));
        $this->assertSame(['RateLimit-Limit' => '60', 'RateLimit-Remaining' => '59', 'RateLimit-Reset' => '1'], $result->headers());
    }

    public function testPdoStorePersistsBetweenInstances(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $store = new PdoStore($pdo);
        $store->install();
        $limit = Limit::perMinute(2);

        $this->assertTrue($this->limiter($store)->attempt('ip', $limit)->allowed);
        // Outra "requisição" (nova instância) vê o mesmo balde
        $this->assertTrue($this->limiter(new PdoStore($pdo))->attempt('ip', $limit)->allowed);
        $this->assertFalse($this->limiter(new PdoStore($pdo))->attempt('ip', $limit)->allowed);

        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM rate_limits')->fetchColumn());
        $this->assertSame(1, $store->prune(60, $this->now + 3600));
    }

    public function testPdoStoreRollsBackOnError(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $store = new PdoStore($pdo);
        $store->install();
        try {
            $store->update('k', function (): array {
                throw new \RuntimeException('falhou no meio');
            });
        } catch (\RuntimeException) {
        }
        // Depois do erro, a conexão não ficou presa numa transação
        $this->assertSame(['tokens' => 1.0, 'ts' => 2.0], $store->update('k', fn (): array => ['tokens' => 1.0, 'ts' => 2.0]));
    }

    public function testClientIpGroupsIpv6By64(): void
    {
        $this->assertSame('203.0.113.7', RateLimiter::clientIp('203.0.113.7'));
        $this->assertSame('2001:db8:abcd:12::/64', RateLimiter::clientIp('2001:db8:abcd:12:1111:2222:3333:4444'));
        $this->assertSame(RateLimiter::clientIp('2001:db8:abcd:12::1'), RateLimiter::clientIp('2001:db8:abcd:12::ffff'));
        $this->assertSame('invalid', RateLimiter::clientIp('não é ip'));
    }

    public function testRejectsInvalidLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Limit(0, 1);
    }
}
