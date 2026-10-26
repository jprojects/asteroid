<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Ai\Usage;
use Quasar\Core\Action;
use Quasar\Core\ModuleHooks;

/**
 * Registro de consumo de la IA (modules/ai_usage.json): botón con el resumen del mes.
 */
final class AiUsageHooks extends ModuleHooks
{
    public function month(Action $a): string
    {
        $state = (new Usage($this->db, (array) $a->config('ai', [])))->state();
        $count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM ai_usage WHERE created_at >= ?', [date('Y-m-01 00:00:00')]);
        $num = fn (float $n) => number_format($n, 2, $a->t('format.decimal_point'), $a->t('format.thousands_sep'));
        return $state['limit'] > 0
            ? $a->t('summary.with_limit', ['used' => $num($state['used']), 'limit' => $num($state['limit']), 'percent' => (int) floor(($state['ratio'] ?? 0) * 100), 'count' => $count])
            : $a->t('summary.no_limit', ['used' => $num($state['used']), 'count' => $count]);
    }
}
