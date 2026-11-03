<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Fields\DurationField;

/**
 * Asteroid · Pendent de facturar (modules/pending_billing.json, solo equip): las horas pendientes (hours.billing = 0)
 * agrupadas por cliente y proyecto, con su importe si el proyecto tiene precio por hora. Desde aquí se crea la orden de
 * facturación (borrador) de un proyecto o de todo un cliente, con una línea por incidencia, o se marcan como no
 * facturables (proyectos con presupuesto cerrado). ?until=AAAA-MM-DD limita a las horas hasta esa fecha.
 * Crear órdenes o marcar horas exige el permiso "bill" de Hores y "create" de Facturació.
 */
final class PendingBillingPage extends Page
{
    public function index(): void
    {
        $until = $this->until();
        $rows = $this->app->db->fetchAll(
            "SELECT p.id AS project_id, p.title AS project, p.hourly_rate, COALESCE(p.budget, 0) AS budget,
                    c.id AS client_id, c.name AS client,
                    SUM(h.n) AS entries, SUM(h.minutes) AS minutes, MIN(h.first_on) AS first_on, MAX(h.last_on) AS last_on,
                    SUM(h.line_amount) AS line_amount
               FROM (
                     -- Horas por incidencia redondeadas a 2 decimales, como las líneas de la orden (así el importe coincide)
                     SELECT hh.project_id, hh.issue_id, COUNT(*) AS n, SUM(hh.minutes) AS minutes, MIN(hh.worked_on) AS first_on,
                            MAX(hh.worked_on) AS last_on,
                            ROUND(ROUND(SUM(hh.minutes) / 60, 2) * COALESCE(MAX(pp.hourly_rate), 0), 2) AS line_amount
                       FROM hours hh
                       LEFT JOIN projects pp ON pp.id = hh.project_id
                      WHERE hh.billing = 0" . ($until ? ' AND hh.worked_on <= ?' : '') . "
                      GROUP BY hh.project_id, hh.issue_id
                    ) h
               LEFT JOIN projects p ON p.id = h.project_id
               LEFT JOIN clients c ON c.id = p.client_id
              GROUP BY p.id, p.title, p.hourly_rate, p.budget, c.id, c.name
              ORDER BY c.name IS NULL, c.name, p.title",
            $until ? [$until] : []
        );

        $clients = [];
        $totals = ['minutes' => 0, 'amount' => 0.0, 'no_rate' => 0, 'budget_minutes' => 0];
        foreach ($rows as $r) {
            $minutes = (int) $r['minutes'];
            $rate = $r['hourly_rate'] !== null && (float) $r['hourly_rate'] > 0 ? (float) $r['hourly_rate'] : null;
            $isBudget = (float) $r['budget'] > 0;
            $amount = $rate !== null && !$isBudget ? round((float) $r['line_amount'], 2) : null;
            $key = $r['client_id'] !== null ? (int) $r['client_id'] : 0;
            $client = &$clients[$key];
            $client ??= ['id' => $key ?: null, 'name' => (string) ($r['client'] ?? ''), 'minutes' => 0, 'amount' => 0.0, 'projects' => []];
            $client['projects'][] = [
                'id' => $r['project_id'] !== null ? (int) $r['project_id'] : null,
                'title' => (string) ($r['project'] ?? ''),
                'entries' => (int) $r['entries'],
                'minutes' => $minutes,
                'hours' => DurationField::hm($minutes),
                'rate' => $rate,
                'budget' => $isBudget ? (float) $r['budget'] : null,
                'amount' => $amount,
                'first_on' => (string) $r['first_on'],
                'last_on' => (string) $r['last_on'],
                'hoursUrl' => $this->app->url('list', ['m' => 'hours', 'f' => array_filter([
                    'billing' => ['0'],
                    'project_id' => $r['project_id'] !== null ? [(string) $r['project_id']] : null,
                    'worked_on' => $until ? ['to' => $until] : null,
                ])]),
                'projectUrl' => $r['project_id'] !== null ? $this->app->url('edit', ['m' => 'projects', 'id' => $r['project_id']]) : null,
            ];
            $client['minutes'] += $minutes;
            $client['amount'] += (float) $amount;
            $totals['minutes'] += $minutes;
            $totals['amount'] += (float) $amount;
            if ($isBudget) {
                $totals['budget_minutes'] += $minutes;
            } elseif ($rate === null) {
                $totals['no_rate']++;
            }
            unset($client);
        }
        foreach ($clients as &$c) {
            $c['hours'] = DurationField::hm($c['minutes']);
        }
        unset($c);

        $canBill = $this->canBill();
        $this->view([
            'clients' => array_values($clients),
            'totals' => $totals + ['hours' => DurationField::hm($totals['minutes']), 'budget_hours' => DurationField::hm($totals['budget_minutes'])],
            'until' => $until,
            'lastMonthEnd' => date('Y-m-d', strtotime('last day of previous month')),
            'urls' => [
                'all' => $this->pageUrl(),
                'lastMonth' => $this->pageUrl('index', ['until' => date('Y-m-d', strtotime('last day of previous month'))]),
                'create' => $canBill ? $this->pageUrl('create') : null,
                'notBillable' => $canBill ? $this->pageUrl('notBillable') : null,
            ],
        ]);
    }

    /**
     * POST: orden de facturación (borrador) con las horas pendientes de un proyecto o, sin proyecto, de todo el cliente.
     * Una línea por incidencia (y otra para las horas sin incidencia) al precio por hora de su proyecto.
     */
    public function actionCreate(): void
    {
        $this->requirePost();
        if (!$this->canBill()) {
            throw new HttpException(403);
        }
        [$clientId, $projectId, $until] = $this->target();
        $hours = $this->pendingHours($clientId, $projectId, $until);
        if (!$hours) {
            $this->flash('error', $this->text('nothing'));
            $this->app->redirect($this->pageUrl('index', array_filter(['until' => $until])));
        }

        $lines = [];
        foreach ($hours as $h) {
            $key = $h['project_id'] . '|' . ($h['issue_id'] ?? '');
            $lines[$key] ??= [
                'description' => $this->lineText($h, $projectId === null),
                'minutes' => 0,
                'price' => (float) ($h['hourly_rate'] ?? 0),
            ];
            $lines[$key]['minutes'] += (int) $h['minutes'];
        }
        $lines = array_map(static function (array $l): array {
            $quantity = round($l['minutes'] / 60, 2);
            return ['description' => $l['description'], 'quantity' => $quantity, 'price' => $l['price'], 'amount' => round($quantity * $l['price'], 2)];
        }, array_values($lines));

        $ids = array_map(static fn ($h) => (int) $h['id'], $hours);
        $billingId = $this->app->db->transaction(function ($db) use ($clientId, $projectId, $lines, $ids) {
            $billingId = (int) $db->insert('billing', [
                'billed_on' => date('Y-m-d'),
                'client_id' => $clientId,
                'project_id' => $projectId,
                'status' => 0,
                'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE),
                'total' => number_format(array_sum(array_column($lines, 'amount')), 2, '.', ''),
            ]);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $db->query("UPDATE hours SET billing = 1, billing_id = ? WHERE billing = 0 AND id IN ($ph)", [$billingId, ...$ids]);
            return $billingId;
        });
        Log::info('Pendent de facturar: orden {billing} con {n} horas (cliente {client}, proyecto {project})', [
            'billing' => $billingId, 'n' => count($ids), 'client' => $clientId, 'project' => $projectId,
        ]);
        $this->flash('success', $this->text('created', ['n' => count($ids)]));
        $this->app->redirect($this->app->url('edit', ['m' => 'billing', 'id' => $billingId]));
    }

    /** POST: marca como no facturables las horas pendientes de un proyecto (p. ej. de presupuesto cerrado). */
    public function actionNotBillable(): void
    {
        $this->requirePost();
        if (!$this->canBill()) {
            throw new HttpException(403);
        }
        [$clientId, $projectId, $until] = $this->target();
        if ($projectId === null) {
            throw new HttpException(400);
        }
        $ids = array_map(static fn ($h) => (int) $h['id'], $this->pendingHours($clientId, $projectId, $until));
        if ($ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $this->app->db->query("UPDATE hours SET billing = 2 WHERE billing = 0 AND id IN ($ph)", $ids);
            Log::info('Pendent de facturar: {n} horas del proyecto {project} marcadas como no facturables', ['n' => count($ids), 'project' => $projectId]);
        }
        $this->flash('success', $this->text('marked_not_billable', ['n' => count($ids)]));
        $this->app->redirect($this->pageUrl('index', array_filter(['until' => $until])));
    }

    private function canBill(): bool
    {
        return $this->allowed('bill', $this->otherModule('hours')) && $this->allowed('create', $this->otherModule('billing'));
    }

    /** @return array{0:int,1:?int,2:?string} cliente, proyecto (o null = todo el cliente) y fecha límite */
    private function target(): array
    {
        $clientId = (int) ($_POST['client'] ?? 0);
        $projectId = isset($_POST['project']) && $_POST['project'] !== '' ? (int) $_POST['project'] : null;
        if ($clientId <= 0 || !$this->app->db->fetchValue('SELECT 1 FROM clients WHERE id = ?', [$clientId])) {
            throw new HttpException(400);
        }
        if ($projectId !== null && !$this->app->db->fetchValue('SELECT 1 FROM projects WHERE id = ? AND client_id = ?', [$projectId, $clientId])) {
            throw new HttpException(400);
        }
        $until = is_string($_POST['until'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['until']) ? $_POST['until'] : null;
        return [$clientId, $projectId, $until];
    }

    /** @return list<array<string,mixed>> horas pendientes con su proyecto e incidencia */
    private function pendingHours(int $clientId, ?int $projectId, ?string $until): array
    {
        $sql = 'SELECT h.id, h.minutes, h.project_id, h.issue_id, p.title AS project, p.hourly_rate, i.title AS issue
                  FROM hours h
                  JOIN projects p ON p.id = h.project_id
                  LEFT JOIN issues i ON i.id = h.issue_id
                 WHERE h.billing = 0 AND p.client_id = ?';
        $params = [$clientId];
        if ($projectId !== null) {
            $sql .= ' AND h.project_id = ?';
            $params[] = $projectId;
        }
        if ($until !== null) {
            $sql .= ' AND h.worked_on <= ?';
            $params[] = $until;
        }
        return $this->app->db->fetchAll($sql . ' ORDER BY p.title, h.issue_id IS NULL, h.issue_id, h.worked_on', $params);
    }

    /** "#3644 Extreure informes" (con el proyecto delante si la orden es de todo el cliente) o "Altres tasques". */
    private function lineText(array $h, bool $withProject): string
    {
        $text = $h['issue_id'] !== null
            ? '#' . $h['issue_id'] . ' ' . (string) $h['issue']
            : $this->text('line_other');
        return mb_substr(($withProject ? $h['project'] . ' · ' : '') . $text, 0, 200);
    }

    private function until(): ?string
    {
        $until = $this->param('until');
        return $until !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) && strtotime($until) ? $until : null;
    }
}
