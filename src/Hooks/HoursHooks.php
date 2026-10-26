<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\Action;
use Quasar\Core\ActionResult;
use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/**
 * Horas: por defecto son del usuario que las apunta y del proyecto de la incidencia. Al imputar se puede
 * cambiar el estado de la incidencia (campo virtual issue_status). Acciones para facturar.
 */
final class HoursHooks extends ModuleHooks
{
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        if ($id === null && empty($data['user_id'])) {
            $data['user_id'] = $user->id();
        }
        if (!empty($data['issue_id']) && empty($data['project_id'])) {
            $data['project_id'] = $this->db->fetchValue('SELECT project_id FROM issues WHERE id = ?', [(int) $data['issue_id']]);
        }
        if (empty($data['project_id']) && empty($existing['project_id'])) {
            return 'errors.project_required';
        }
        return null;
    }

    public function afterSave(string $id, array $data, bool $isNew, User $user): void
    {
        $status = $this->values['issue_status'] ?? '';
        $issueId = $this->db->fetchValue('SELECT issue_id FROM hours WHERE id = ?', [(int) $id]);
        if ($status !== '' && $status !== null && $issueId !== null) {
            Asteroid::setStatus($this->db, $issueId, (int) $status, $user);
        }
    }

    public function markBilled(Action $a): string
    {
        $a->db->update('hours', ['billing' => 1], 'id', (int) $a->id);
        return $a->t('flash.marked_billed');
    }

    public function markNotBillable(Action $a): string
    {
        $a->db->update('hours', ['billing' => 2], 'id', (int) $a->id);
        return $a->t('flash.marked_not_billable');
    }

    /** Orden de facturación (borrador) con una línea por estas horas al precio del proyecto. */
    public function createBilling(Action $a): ActionResult
    {
        $hour = $a->db->fetch(
            'SELECT h.*, p.title AS project_title, p.client_id, p.hourly_rate FROM hours h LEFT JOIN projects p ON p.id = h.project_id WHERE h.id = ?',
            [(int) $a->id]
        );
        if ($hour === null || $hour['client_id'] === null) {
            return ActionResult::error($a->t('errors.no_client'));
        }
        $quantity = round((int) $hour['minutes'] / 60, 2);
        $price = (float) ($hour['hourly_rate'] ?? 0);
        $description = trim($hour['project_title'] . ($hour['comment'] ? ' · ' . preg_replace('/\s+/', ' ', (string) $hour['comment']) : ''));
        $billingId = $a->db->transaction(static function ($db) use ($hour, $quantity, $price, $description) {
            $billingId = $db->insert('billing', [
                'billed_on' => date('Y-m-d'),
                'client_id' => (int) $hour['client_id'],
                'project_id' => $hour['project_id'],
                'status' => 0,
                'lines' => json_encode([[
                    'description' => mb_substr($description, 0, 200),
                    'quantity' => $quantity,
                    'price' => $price,
                    'amount' => round($quantity * $price, 2),
                ]], JSON_UNESCAPED_UNICODE),
                'total' => round($quantity * $price, 2),
            ]);
            $db->update('hours', ['billing' => 1, 'billing_id' => (int) $billingId], 'id', (int) $hour['id']);
            return $billingId;
        });
        return ActionResult::redirect($a->url('edit', ['m' => 'billing', 'id' => $billingId]), $a->t('flash.billing_created'));
    }
}
