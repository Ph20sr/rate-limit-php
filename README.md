# rate-limit-php

[![CI](https://github.com/Ph20sr/rate-limit-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Ph20sr/rate-limit-php/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/php-%3E%3D8.1-777bb4)
![license](https://img.shields.io/badge/license-MIT-blue)

Limite de requisições para **APIs e sites em PHP**, usando o algoritmo *token bucket*. Protege login contra força bruta, formulários contra spam e endpoints caros contra abuso. Funciona em **hospedagem compartilhada**: guarda os dados no próprio MySQL/MariaDB ou SQLite, **sem Redis**.

## Por que token bucket

Uma janela fixa ("60 por minuto") deixa passar 120 requisições em 2 segundos na virada do minuto. O token bucket **aceita rajadas curtas e mantém a média**. É o algoritmo de APIs como Stripe e AWS.

```
capacidade = 5 fichas, reabastece 1 a cada 12 s  (= Limit::perMinute(5))
cada requisição gasta 1 ficha; sem ficha → 429 + Retry-After
```

## Uso

```php
use Ph20sr\RateLimit\{RateLimiter, Limit};
use Ph20sr\RateLimit\Store\PdoStore;

$store = new PdoStore($pdo);
$store->install();                       // cria a tabela rate_limits (uma vez)
$limiter = new RateLimiter($store);

// Login: 5 tentativas por minuto por IP
$ip = RateLimiter::clientIp($_SERVER['REMOTE_ADDR']);
$result = $limiter->attempt("login:{$ip}", Limit::perMinute(5));

if (!$result->allowed) {
    $result->send();                     // 429 + RateLimit-* + Retry-After
    exit(json_encode(['error' => "Muitas tentativas. Tente em {$result->retryAfter}s."]));
}

if (login_ok()) {
    $limiter->reset("login:{$ip}");      // sucesso limpa as tentativas
}
```

### Receitas

```php
// API: 600/min por chave, aceitando rajadas de até 50
$limiter->attempt("api:{$apiKeyId}", Limit::perMinute(600)->withBurst(50));

// Operação cara custa mais fichas
$limiter->attempt("api:{$apiKeyId}", Limit::perMinute(600), cost: 20);   // exportação

// Formulário de contato: 3 por hora por IP
$limiter->attempt("contato:{$ip}", Limit::perHour(3));

// Envio de SMS ou WhatsApp de verificação: 5 por dia por telefone
$limiter->attempt("otp:{$telefone}", Limit::perDay(5));
```

### IPv6

`RateLimiter::clientIp()` agrupa endereços IPv6 por **/64**. Cada conexão residencial costuma receber bilhões de endereços IPv6, então limitar por endereço exato deixaria um atacante trocar de IP a cada tentativa.

> Atrás de proxy ou CDN (Cloudflare, load balancer), use o IP real do cliente (`CF-Connecting-IP` ou `X-Forwarded-For` **confiável**), não o `REMOTE_ADDR`.

## Armazenamento

| store | quando usar |
| --- | --- |
| `PdoStore` | sites e APIs PHP tradicionais (FPM, hospedagem compartilhada). MySQL/MariaDB com `SELECT ... FOR UPDATE`; SQLite com `BEGIN IMMEDIATE` |
| `MemoryStore` | testes e processos de longa duração (workers, Swoole) |
| sua classe | implemente `Store::update()` de forma atômica (ex.: Redis com Lua) |

Rode `$store->prune()` num cron diário para apagar baldes parados.

## Testes

```bash
composer install
vendor/bin/phpunit
```

Os testes usam um relógio controlado: rajada, reabastecimento, custo, chaves independentes, persistência via PDO, rollback em erro e agrupamento IPv6.

## Licença

MIT
