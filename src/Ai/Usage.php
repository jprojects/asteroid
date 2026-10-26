<?php
declare(strict_types=1);

namespace Quasar\Ai;

use Quasar\Core\Database;

/**
 * Consumo de la IA de una instalación: coste de cada consulta en euros (precios de config
 * 'ai.prices' en dólares por millón de tokens × 'ai.eur_per_usd'), registro en ai_usage,
 * límite mensual, límite por usuario y caché de frases idénticas (ai_cache).
 */
final class Usage
{
    /** @param array<string,mixed> $config config['ai'] */
    public function __construct(private readonly Database $db, private readonly array $config)
    {
    }

    /** Gasto del mes en curso en euros. */
    public function monthCostEur(): float
    {
        return (float) $this->db->fetchValue(
            'SELECT COALESCE(SUM(cost_eur), 0) FROM ai_usage WHERE created_at >= ?',
            [date('Y-m-01 00:00:00')]
        );
    }

    public function limitEur(): float
    {
        return max(0.0, (float) ($this->config['monthly_limit_eur'] ?? 0));
    }

    /**
     * Situación respecto al límite mensual: ok, warn (a partir de 'warn_at') o blocked (agotado).
     * @return array{state:string,used:float,limit:float,ratio:?float}
     */
    public function state(): array
    {
        $used = $this->monthCostEur();
        $limit = $this->limitEur();
        $ratio = $limit > 0 ? $used / $limit : null;
        $state = match (true) {
            $ratio === null => 'ok',
            $ratio >= 1 => 'blocked',
            $ratio >= (float) ($this->config['warn_at'] ?? 0.8) => 'warn',
            default => 'ok',
        };
        return ['state' => $state, 'used' => round($used, 4), 'limit' => $limit, 'ratio' => $ratio === null ? null : round($ratio, 4)];
    }

    /** Si el usuario ha llegado a 'ai.user_per_hour' consultas en la última hora. */
    public function userOverRate(int $userId): bool
    {
        $max = (int) ($this->config['user_per_hour'] ?? 60);
        if ($max <= 0) {
            return false;
        }
        $n = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM ai_usage WHERE user_id = ? AND created_at >= ? AND status <> 'limit'",
            [$userId, date('Y-m-d H:i:s', time() - 3600)]
        );
        return $n >= $max;
    }

    /** Coste en euros de una respuesta según los precios del modelo (0 si el modelo no tiene precio). */
    public function costEur(string $model, LlmResponse $r): float
    {
        $p = (array) ($this->config['prices'][$model] ?? []);
        $usd = ($r->inputTokens * (float) ($p['input'] ?? 0)
            + $r->outputTokens * (float) ($p['output'] ?? 0)
            + $r->cacheWriteTokens * (float) ($p['cache_write'] ?? $p['input'] ?? 0)
            + $r->cacheReadTokens * (float) ($p['cache_read'] ?? 0)) / 1_000_000;
        return $usd * (float) ($this->config['eur_per_usd'] ?? 1);
    }

    /**
     * @param array{user_id:?int,module:string,provider:string,model:string,status:string,prompt:?string,result:mixed,duration_ms:int} $row
     */
    public function log(array $row, ?LlmResponse $r = null): void
    {
        $logText = !empty($this->config['log_text'] ?? true);
        $this->db->insert('ai_usage', [
            'user_id' => $row['user_id'],
            'module' => $row['module'],
            'provider' => $row['provider'],
            'model' => $row['model'],
            'status' => $row['status'],
            'prompt' => $logText && $row['prompt'] !== null ? mb_substr($row['prompt'], 0, 500) : null,
            'result' => $logText && $row['result'] !== null
                ? mb_substr(is_string($row['result']) ? $row['result'] : json_encode($row['result'], JSON_UNESCAPED_UNICODE), 0, 60000)
                : null,
            'input_tokens' => $r?->inputTokens ?? 0,
            'output_tokens' => $r?->outputTokens ?? 0,
            'cache_write_tokens' => $r?->cacheWriteTokens ?? 0,
            'cache_read_tokens' => $r?->cacheReadTokens ?? 0,
            'cost_eur' => $r !== null ? round($this->costEur($row['model'], $r), 6) : 0,
            'duration_ms' => $row['duration_ms'],
        ]);
    }

    // ---------------------------------------------------------------- caché de frases

    /** @return array<string,mixed>|null */
    public function cacheGet(string $hash): ?array
    {
        $ttl = (int) ($this->config['cache_ttl'] ?? 0);
        if ($ttl <= 0) {
            return null;
        }
        $raw = $this->db->fetchValue('SELECT response FROM ai_cache WHERE hash = ? AND created_at >= ?', [$hash, date('Y-m-d H:i:s', time() - $ttl)]);
        $data = $raw !== null ? json_decode((string) $raw, true) : null;
        return is_array($data) ? $data : null;
    }

    public function cachePut(string $hash, array $data): void
    {
        $ttl = (int) ($this->config['cache_ttl'] ?? 0);
        if ($ttl <= 0) {
            return;
        }
        $this->db->query('DELETE FROM ai_cache WHERE created_at < ?', [date('Y-m-d H:i:s', time() - $ttl)]);
        $this->db->query(
            'REPLACE INTO ai_cache (hash, response, created_at) VALUES (?, ?, ?)',
            [$hash, json_encode($data, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]
        );
    }
}
