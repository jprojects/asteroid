<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/** Facturación: importe de cada línea (cantidad × precio) y total. */
final class BillingHooks extends ModuleHooks
{
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        if (!array_key_exists('lines', $data)) {
            return null;
        }
        $lines = json_decode((string) ($data['lines'] ?? '[]'), true) ?: [];
        $total = 0.0;
        foreach ($lines as &$line) {
            $line['amount'] = round((float) ($line['quantity'] ?? 0) * (float) ($line['price'] ?? 0), 2);
            $total += $line['amount'];
        }
        unset($line);
        $data['lines'] = json_encode(array_values($lines), JSON_UNESCAPED_UNICODE);
        $data['total'] = number_format($total, 2, '.', '');
        return null;
    }
}
