<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\ApiTokens;
use Quasar\Core\Log;
use Quasar\Core\ModuleRegistry;
use Quasar\Core\User;
use Quasar\Hooks\Asteroid;

/**
 * Asteroid · la API del Asteroid antiguo (admin/index.php?view=api&task=…), para los programas que la usaban: el
 * enlace con Factusol lee las horas con el código Factusol del cliente y las marca como facturadas. Mismas tareas,
 * parámetros y JSON que antes; solo cambia la dirección:
 *
 * Lo usa el enlace con Factusol (un proyecto VBA); antes llamaba a index.php?task=api.getHours…, ahora:
 *
 *   index.php?r=public&m=api_legacy&task=getHours&token=…[&billed=0|1|2][&budget=0|1|2][&projectId=N][&limit=N]
 *   index.php?r=public&m=api_legacy&task=setBilled&token=…&id=N&billed=0|1|2
 *   index.php?r=public&m=api_legacy&task=getIssues&token=…[&projectId=N][&limit=N]
 *
 * token: un token personal de la API (Perfil → API, "qs_…") o uno antiguo declarado en config.local.php
 * ('legacy_api' => ['tokens' => ['TOKEN' => ID_USUARIO]]). Se actúa como ese usuario, que tiene que ser del equipo y
 * tener permiso en las horas (ver; editar para setBilled) o en las incidencias.
 * Las respuestas de error son las de antes: 200 con {"result":{"msg":…,"code":"500"}}.
 */
final class LegacyApiPage extends Page
{
    public function index(): void
    {
        $task = (string) $this->param('task', '');
        $task = str_contains($task, '.') ? substr($task, strrpos($task, '.') + 1) : $task; // también "api.getHours"
        $user = $this->tokenUser((string) $this->param('token', ''));
        if ($user === null) {
            $this->result('Invalid token', '500');
            return;
        }
        try {
            match ($task) {
                'getHours' => $this->getHours($user),
                'setBilled' => $this->setBilled($user),
                'getIssues' => $this->getIssues($user),
                default => $this->result('Unknown task', '500'),
            };
        } catch (\Throwable $e) {
            Log::error('API antigua: error en {task}', ['task' => $task, 'exception' => $e]);
            $this->result('Error', '500');
        }
    }

    /** Horas, con el cliente y su código Factusol (remoteClientId). Columnas y formato del Asteroid antiguo. */
    private function getHours(User $user): void
    {
        if (!$this->permits($user, 'hours', 'view')) {
            $this->result('Forbidden', '403');
            return;
        }
        $where = ['1=1'];
        $args = [];
        $billed = (int) $this->param('billed', '0');   // 0 todas · 1 facturadas · 2 pendientes de facturar
        if ($billed === 1) {
            $where[] = 'h.billing = 1';
        } elseif ($billed === 2) {
            $where[] = 'COALESCE(h.billing, 0) = 0';
        }
        $budget = (int) $this->param('budget', '0');   // 0 todas · 1 proyectos con presupuesto · 2 sin presupuesto
        if ($budget === 1) {
            $where[] = 'COALESCE(p.budget, 0) > 0';
        } elseif ($budget === 2) {
            $where[] = 'COALESCE(p.budget, 0) = 0';
        }
        if (($projectId = (int) $this->param('projectId', '0')) > 0) {
            $where[] = 'i.project_id = ?';   // como antes: el proyecto de la incidencia
            $args[] = $projectId;
        }
        $limit = max(0, (int) $this->param('limit', '0'));
        $rows = $this->app->db->fetchAll(
            "SELECT h.id, CONCAT(h.worked_on, ' 00:00:00') AS date,
                    CONCAT(LPAD(FLOOR(h.minutes / 60), 2, '0'), ':', LPAD(MOD(h.minutes, 60), 2, '0'), ':00') AS hours,
                    h.minutes, h.billing AS billed, c.factusol_code AS remoteClientId, h.project_id AS projectId,
                    h.issue_id AS issueId, p.title AS projectTitle, i.title AS issueTitle, h.comment AS comments,
                    i.client_id AS clientId, c.name AS clientName, p.budget AS projectBudget
               FROM hours h
               LEFT JOIN issues i ON i.id = h.issue_id
               LEFT JOIN projects p ON p.id = h.project_id
               LEFT JOIN clients c ON c.id = p.client_id
              WHERE " . implode(' AND ', $where) . '
              ORDER BY i.id DESC' . ($limit > 0 ? ' LIMIT ' . $limit : ''),
            $args
        );
        $this->out(array_map([$this, 'strings'], $rows));
    }

    /** Marca unas horas: 0 no facturadas · 1 facturadas · 2 no facturables. */
    private function setBilled(User $user): void
    {
        $id = ctype_digit((string) $this->param('id', '')) ? (int) $this->param('id') : 0;
        $state = (string) $this->param('billed', '1');
        $state = ctype_digit($state) ? (int) $state : -1;
        if (!$this->permits($user, 'hours', 'edit')) {
            $this->result('Forbidden', '403');
            return;
        }
        if ($id <= 0 || !in_array($state, [0, 1, 2], true)) {
            $this->result('Invalid parameters', '500');
            return;
        }
        $this->app->db->update('hours', ['billing' => $state], 'id', $id);
        Log::info('API antigua: horas {id} → facturación {state} ({user})', ['id' => $id, 'state' => $state, 'user' => $user->id()]);
        $this->result('Ok', '200');
    }

    /** Incidencias con los nombres de columna del Asteroid antiguo. */
    private function getIssues(User $user): void
    {
        if (!$this->permits($user, 'issues', 'view')) {
            $this->result('Forbidden', '403');
            return;
        }
        $args = [];
        $where = '1=1';
        if (($projectId = (int) $this->param('projectId', '0')) > 0) {
            $where = 'i.project_id = ?';
            $args[] = $projectId;
        }
        $limit = max(0, (int) $this->param('limit', '0'));
        $rows = $this->app->db->fetchAll(
            "SELECT i.id, i.client_id AS clientId, i.project_id AS projectId, i.title AS issue_title,
                    i.description AS issue_desc, i.priority AS issue_priority, i.status AS issue_status,
                    i.created_at AS issue_date, i.starts_on AS issue_date_in, i.resolved_at AS issue_date_out,
                    i.worker_id AS issue_worker, i.created_by_client AS created_from_client
               FROM issues i
              WHERE $where
              ORDER BY i.id DESC" . ($limit > 0 ? ' LIMIT ' . $limit : ''),
            $args
        );
        $this->out(array_map([$this, 'strings'], $rows));
    }

    /** Usuario del token (personal de la API o antiguo de la configuración), del equipo; la petición actúa como él. */
    private function tokenUser(string $token): ?User
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $row = null;
        $tokenId = 0;
        if (str_starts_with($token, ApiTokens::PREFIX)) {
            $auth = (new ApiTokens($this->app->db))->authenticate($token);
            [$row, $tokenId] = $auth !== null ? [$auth['user'], (int) $auth['token']['id']] : [null, 0];
        } else {
            foreach ((array) ($this->app->config['legacy_api']['tokens'] ?? []) as $legacy => $userId) {
                if (is_string($legacy) && strlen($legacy) >= 8 && hash_equals($legacy, $token)) {
                    $row = $this->app->db->fetch('SELECT * FROM users WHERE id = ? AND active = 1', [(int) $userId]);
                    break;
                }
            }
        }
        if ($row === null) {
            Log::warning('API antigua: token no válido', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            return null;
        }
        $this->app->auth->actAs($row, $tokenId);
        $user = $this->app->auth->user();
        return $user !== null && !Asteroid::isClient($user) ? $user : null;
    }

    private function permits(User $user, string $module, string $action): bool
    {
        $m = $this->app->modules()->get($module);
        return $m !== null && ModuleRegistry::canAccess($user, $m, $action);
    }

    /** Como devolvía el Asteroid antiguo (mysqli): todos los valores como texto, o null. */
    private function strings(array $row): array
    {
        return array_map(static fn ($v) => $v === null ? null : (string) $v, $row);
    }

    private function result(string $msg, string $code): void
    {
        $this->out(['result' => ['msg' => $msg, 'code' => $code]]);
    }

    /** JSON como el del Asteroid antiguo (json_encode sin opciones: acentos escapados, solo ASCII, cómodo para VBA). */
    private function out(array $data): void
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data);
    }
}
