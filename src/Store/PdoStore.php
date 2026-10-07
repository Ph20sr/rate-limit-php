<?php

declare(strict_types=1);

namespace Ph20sr\RateLimit\Store;

use PDO;

/**
 * Armazena os baldes numa tabela (MySQL/MariaDB ou SQLite). Funciona em
 * hospedagem compartilhada, sem Redis. A leitura e a escrita acontecem na
 * mesma transação, com a linha travada.
 */
final class PdoStore implements Store
{
    private readonly string $driver;

    public function __construct(private readonly PDO $pdo, private readonly string $table = 'rate_limits')
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Nome de tabela inválido: {$table}");
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function install(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (
            k VARCHAR(190) NOT NULL PRIMARY KEY,
            tokens DOUBLE NOT NULL,
            ts DOUBLE NOT NULL
        )");
    }

    public function update(string $key, callable $fn): array
    {
        $key = mb_substr($key, 0, 190);
        $this->begin();
        try {
            $lock = $this->driver === 'mysql' ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare("SELECT tokens, ts FROM {$this->table} WHERE k = ?{$lock}");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $current = $row ? ['tokens' => (float) $row['tokens'], 'ts' => (float) $row['ts']] : null;

            $next = $fn($current);

            $upsert = $this->driver === 'mysql'
                ? "INSERT INTO {$this->table} (k, tokens, ts) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE tokens = VALUES(tokens), ts = VALUES(ts)"
                : "INSERT INTO {$this->table} (k, tokens, ts) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET tokens = excluded.tokens, ts = excluded.ts";
            $this->pdo->prepare($upsert)->execute([$key, $next['tokens'], $next['ts']]);
            $this->commit();
            return $next;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    public function delete(string $key): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE k = ?")->execute([mb_substr($key, 0, 190)]);
    }

    /** Remove baldes parados há mais de $seconds (rode num cron). */
    public function prune(int $seconds = 86400, ?float $now = null): int
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE ts < ?");
        $stmt->execute([($now ?? microtime(true)) - $seconds]);
        return $stmt->rowCount();
    }

    // SQLite: BEGIN IMMEDIATE pega o lock de escrita já na leitura
    // (o BEGIN padrão do PDO é DEFERRED e permitiria duas leituras iguais).
    private function begin(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
    }

    private function commit(): void
    {
        $this->driver === 'sqlite' ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
    }

    private function rollBack(): void
    {
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('ROLLBACK');
        } elseif ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
