<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\FormHandler;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Hooks\Asteroid;
use Quasar\WebAudit\WebAudit;

/**
 * Asteroid · «Salut de la web» (modules/web_audit.json): el resumen SEO y GEO (visibilidad en las IA) de las webs de
 * los proyectos con «Informe SEO/GEO» marcado, que analiza Pulsar (tools/web-audit.php). El cliente ve los suyos y
 * puede pedir que se resuelvan los problemas que elija (crea una incidencia en ese proyecto); si aún no tiene ninguno,
 * puede pedir que se le revise la web. El equipo ve todos, las soluciones, el informe de Pulsar y lo puede actualizar.
 */
final class WebAuditPage extends Page
{
    public function index(): void
    {
        $user = $this->user();
        $client = Asteroid::isClient($user);
        $projects = $this->projects();
        $id = ctype_digit((string) $this->param('project', '')) ? (int) $this->param('project') : null;
        if ($id === null && $client && count($projects) === 1) {
            $id = (int) $projects[0]['id'];
        }
        if ($id === null) {
            $this->view([
                'mode' => 'list',
                'projects' => array_map(fn (array $p) => $p + ['audits' => WebAudit::latest($this->app->db, (int) $p['id']), 'url' => $this->pageUrl('index', ['project' => $p['id']])], $projects),
                'isClient' => $client,
                'offer' => $client ? $this->openRequest('web_audit.offer.' . (int) $user->get('client_id')) : null,
                'canRequest' => $this->canCreateIssues(),
                'offerUrl' => $this->pageUrl('offer'),
                'enabled' => WebAudit::enabled($this->app->config),
            ]);
            return;
        }
        $project = $this->project($id);
        $this->view([
            'mode' => 'detail',
            'project' => $project,
            'audits' => WebAudit::latest($this->app->db, $id),
            'last' => WebAudit::lastAttempt($this->app->db, $id),
            'isClient' => $client,
            'request' => $this->openRequest('web_audit.request.' . $id),
            'canRequest' => $this->canCreateIssues(),
            'requestUrl' => $this->pageUrl('request', ['project' => $id]),
            'refreshUrl' => !$client && WebAudit::enabled($this->app->config) ? $this->pageUrl('refresh', ['project' => $id]) : null,
            'listUrl' => !$client || count($projects) > 1 ? $this->pageUrl() : null,
            'reportUrl' => fn (array $audit, bool $pdf = false) => $client ? null : WebAudit::reportUrl($this->app->config, $audit, $pdf),
        ]);
    }

    /** POST (equipo): vuelve a analizar la web ahora. */
    public function actionRefresh(): void
    {
        $this->requirePost();
        if (Asteroid::isClient($this->user()) || !WebAudit::enabled($this->app->config)) {
            throw new HttpException(403);
        }
        $project = $this->project((int) $this->param('project', '0'));
        @set_time_limit(300);
        $rows = WebAudit::run($this->app->db, $this->app->config, $project);
        $failed = array_filter($rows, static fn ($r) => $r['score'] === null);
        $this->flash($failed ? 'error' : 'success', $failed ? $this->text('refresh_failed', ['error' => (string) reset($failed)['error']]) : $this->text('refreshed'));
        $this->app->redirect($this->pageUrl('index', ['project' => $project['id']]));
    }

    /** POST: incidencia en el proyecto con los problemas elegidos («Vull que ho resolgueu»). */
    public function actionRequest(): void
    {
        $this->requirePost();
        if (!$this->canCreateIssues()) {
            throw new HttpException(403);
        }
        $project = $this->project((int) $this->param('project', '0'));
        $back = $this->pageUrl('index', ['project' => $project['id']]);
        $key = 'web_audit.request.' . (int) $project['id'];
        if (($open = $this->openRequest($key)) !== null) {
            $this->flash('info', $this->text('requested_already', ['id' => $open['id']]));
            $this->app->redirect($back);
        }
        $chosen = array_flip(array_map('strval', (array) ($_POST['issues'] ?? [])));
        $lines = [];
        foreach (WebAudit::latest($this->app->db, (int) $project['id']) as $kind => $a) {
            foreach ($a['current']['issues'] ?? [] as $issue) {
                if (isset($chosen[$kind . ':' . $issue['id']])) {
                    $lines[] = '<li><strong>' . $this->text('kinds.' . $kind) . ':</strong> ' . htmlspecialchars((string) $issue['title'], ENT_QUOTES)
                        . ($issue['detail'] !== '' ? ' — ' . htmlspecialchars((string) $issue['detail'], ENT_QUOTES) : '') . '</li>';
                }
            }
        }
        if (!$lines) {
            $this->flash('error', $this->text('choose_some'));
            $this->app->redirect($back);
        }
        $user = $this->user();
        $result = (new FormHandler($this->otherModule('issues'), $this->app->db))->process([
            'title' => $this->text('request_title', ['site' => WebAudit::label((string) $project['website'])]),
            'project_id' => (string) $project['id'],
            'priority' => '0',
            'description' => '<p>' . htmlspecialchars($this->text('request_body', ['user' => $user->name()]), ENT_QUOTES) . '</p><ul>' . implode('', $lines) . '</ul>',
        ], null, $user, true);
        $this->finishRequest($result, $key, $back);
    }

    /** POST (cliente sin informes): pide que AFI le revise la web. */
    public function actionOffer(): void
    {
        $this->requirePost();
        $user = $this->user();
        if (!Asteroid::isClient($user) || !$this->canCreateIssues()) {
            throw new HttpException(403);
        }
        $clientId = (int) $user->get('client_id');
        $key = 'web_audit.offer.' . $clientId;
        if (($open = $this->openRequest($key)) !== null) {
            $this->flash('info', $this->text('requested_already', ['id' => $open['id']]));
            $this->app->redirect($this->pageUrl());
        }
        $project = $this->app->db->fetchValue('SELECT id FROM projects WHERE client_id = ? AND active = 1 ORDER BY id LIMIT 1', [$clientId]);
        if ($project === null) {
            $this->flash('error', $this->text('no_project'));
            $this->app->redirect($this->pageUrl());
        }
        $result = (new FormHandler($this->otherModule('issues'), $this->app->db))->process([
            'title' => $this->text('offer_title'),
            'project_id' => (string) $project,
            'priority' => '0',
            'description' => '<p>' . htmlspecialchars($this->text('offer_body', ['user' => $user->name()]), ENT_QUOTES) . '</p>',
        ], null, $user, true);
        $this->finishRequest($result, $key, $this->pageUrl());
    }

    private function finishRequest(array $result, string $key, string $back): void
    {
        if (!$result['ok']) {
            Log::warning('Salut de la web: no se ha podido crear la incidencia', ['errors' => $result['errors']]);
            $this->flash('error', $this->text('request_failed'));
            $this->app->redirect($back);
        }
        $this->app->settings()->set($key, (int) $result['id']);
        Log::info('Salut de la web: incidencia {id} ({key})', ['id' => $result['id'], 'key' => $key]);
        $this->flash('success', $this->text('requested', ['id' => $result['id']]));
        $this->app->redirect($back);
    }

    /** Proyectos con informe que puede ver el usuario: los de su cliente, o todos (equipo). @return list<array> */
    private function projects(): array
    {
        $user = $this->user();
        $client = Asteroid::isClient($user);
        return $this->app->db->fetchAll(
            "SELECT p.id, p.title, p.website, p.client_id, c.name AS client FROM projects p LEFT JOIN clients c ON c.id = p.client_id
              WHERE p.active = 1 AND p.web_audit = 1 AND COALESCE(p.website, '') <> ''" . ($client ? ' AND p.client_id = ?' : '') . '
              ORDER BY c.name, p.title',
            $client ? [(int) $user->get('client_id')] : []
        );
    }

    /** El proyecto, si el usuario lo puede ver aquí (el cliente, solo los suyos con informe). */
    private function project(int $id): array
    {
        $user = $this->user();
        $row = $this->app->db->fetch(
            'SELECT p.*, c.name AS client FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE p.id = ?',
            [$id]
        );
        if ($row === null || (Asteroid::isClient($user) && ((int) $row['client_id'] !== (int) $user->get('client_id') || !(int) $row['web_audit'] || !(int) $row['active']))) {
            throw new HttpException(404);
        }
        return $row;
    }

    private function canCreateIssues(): bool
    {
        return $this->allowed('create', $this->otherModule('issues'));
    }

    /** La incidencia guardada con esa clave, si sigue pendiente o en curso. */
    private function openRequest(string $key): ?array
    {
        $id = $this->app->settings()->get($key);
        return is_int($id) ? $this->app->db->fetch('SELECT id, title FROM issues WHERE id = ? AND status IN (1, 2)', [$id]) : null;
    }
}
