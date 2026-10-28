<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\HttpException;
use Quasar\Fields\DurationField;
use Quasar\Hooks\Asteroid;

/**
 * Asteroid · Pissarra (modules/pissarra.json): una tarjeta por proyecto con incidencias pendientes o en curso,
 * con las más urgentes primero, para ver de un vistazo por dónde empezar. Por defecto, las del técnico que
 * mira la página; ?worker=all (todo el equipo), none (sin asignar) o el id de un técnico.
 */
final class PissarraPage extends Page
{
    /** Incidencias que se listan en cada tarjeta. */
    private const TOP = 4;

    public function index(): void
    {
        $issues = $this->otherModule('issues');
        if (!$this->allowed('view', $issues) || Asteroid::isClient($this->user())) {
            throw new HttpException(403, 'pissarra:no_access');
        }

        $workers = $this->workers();
        $me = (int) $this->user()->id();
        $worker = $this->param('worker', isset($workers[$me]) ? (string) $me : 'all');
        if (!in_array($worker, ['all', 'none'], true) && !isset($workers[(int) $worker])) {
            $worker = 'all';
        }

        $where = 'i.status IN (' . Asteroid::STATUS_PENDING . ', ' . Asteroid::STATUS_IN_PROGRESS . ') AND i.project_id IS NOT NULL';
        $params = [];
        if ($worker === 'none') {
            $where .= ' AND i.worker_id IS NULL';
        } elseif ($worker !== 'all') {
            $where .= ' AND i.worker_id = ?';
            $params[] = (int) $worker;
        }

        $rows = $this->app->db->fetchAll(
            "SELECT i.id, i.title, i.project_id, i.status, i.priority, i.due_on, i.estimated_minutes, i.worker_id,
                    p.title AS project, c.name AS client,
                    (SELECT SUM(h.minutes) FROM hours h WHERE h.issue_id = i.id) AS worked_minutes
               FROM issues i
               JOIN projects p ON p.id = i.project_id
               LEFT JOIN clients c ON c.id = p.client_id
              WHERE $where
              ORDER BY i.priority DESC, i.due_on IS NULL, i.due_on, i.status DESC, i.id",
            $params
        );

        $today = date('Y-m-d');
        $cards = [];
        foreach ($rows as $r) {
            $pid = (int) $r['project_id'];
            $card = &$cards[$pid];
            $card ??= [
                'id' => $pid, 'project' => (string) $r['project'], 'client' => (string) ($r['client'] ?? ''),
                'open' => 0, 'pending' => 0, 'in_progress' => 0, 'panic' => 0, 'urgent' => 0,
                'estimated' => 0, 'worked' => 0, 'due' => null, 'overdue' => false, 'issues' => [],
            ];
            $card['open']++;
            $card[(int) $r['status'] === Asteroid::STATUS_PENDING ? 'pending' : 'in_progress']++;
            match ((int) $r['priority']) {
                2 => $card['panic']++,
                1 => $card['urgent']++,
                default => null,
            };
            $card['estimated'] += (int) $r['estimated_minutes'];
            $card['worked'] += (int) $r['worked_minutes'];
            if ($r['due_on'] !== null && ($card['due'] === null || $r['due_on'] < $card['due'])) {
                $card['due'] = (string) $r['due_on'];
                $card['overdue'] = $card['due'] < $today;
            }
            if (count($card['issues']) < self::TOP) {
                $card['issues'][] = [
                    'id' => (int) $r['id'],
                    'title' => (string) $r['title'],
                    'status' => (int) $r['status'],
                    'priority' => (int) $r['priority'],
                    'due' => $r['due_on'],
                    'overdue' => $r['due_on'] !== null && $r['due_on'] < $today,
                    'worker' => $worker === 'all' && $r['worker_id'] !== null ? ($workers[(int) $r['worker_id']] ?? null) : null,
                    'url' => $this->app->url('edit', ['m' => 'issues', 'id' => $r['id']]),
                ];
            }
            unset($card);
        }

        // Primero los que tienen pánico, después urgentes, la fecha límite más próxima y los que tienen más
        usort($cards, static fn (array $a, array $b) => [$b['panic'], $b['urgent'], $a['due'] === null, $a['due'], $b['open']]
            <=> [$a['panic'], $a['urgent'], $b['due'] === null, $b['due'], $a['open']]);

        foreach ($cards as &$card) {
            $filters = ['status' => [Asteroid::STATUS_PENDING, Asteroid::STATUS_IN_PROGRESS], 'project_id' => [$card['id']]];
            if ($worker !== 'all' && $worker !== 'none') {
                $filters['worker_id'] = [(int) $worker];
            }
            $card['url'] = $issues->url(['f' => $filters]);
            $card['estimated'] = $card['estimated'] > 0 ? DurationField::hm($card['estimated']) : null;
            $card['worked'] = $card['worked'] > 0 ? DurationField::hm($card['worked']) : null;
        }
        unset($card);

        $tabs = [['key' => (string) $me, 'label' => $this->text('mine')]];
        foreach ($workers as $id => $name) {
            if ($id !== $me) {
                $tabs[] = ['key' => (string) $id, 'label' => $name];
            }
        }
        $tabs[] = ['key' => 'none', 'label' => $this->text('unassigned')];
        $tabs[] = ['key' => 'all', 'label' => $this->text('all')];
        if (!isset($workers[$me])) {
            array_shift($tabs);
        }
        foreach ($tabs as &$tab) {
            $tab['url'] = $this->pageUrl('index', ['worker' => $tab['key']]);
            $tab['active'] = $tab['key'] === $worker;
        }
        unset($tab);

        $this->view([
            'cards' => $cards,
            'tabs' => $tabs,
            'total' => count($rows),
            'newUrl' => $this->allowed('create', $issues) ? $this->app->url('edit', ['m' => 'issues']) : null,
        ]);
    }

    /** @return array<int,string> técnicos (equipo y administradores activos): id => nombre */
    private function workers(): array
    {
        $ids = Asteroid::teamIds($this->app->db);
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->app->db->fetchAll("SELECT id, name, username FROM users WHERE id IN ($in) ORDER BY name", $ids);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) ($r['name'] ?: $r['username']);
        }
        return $out;
    }
}
