<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\FormHandler;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Hooks\Asteroid;
use Quasar\Webchat\Webchat;

/**
 * Asteroid · «El meu xat web» (modules/webchat_settings.json): cada cliente activa y personaliza su chat (nombre,
 * color, saludo, posición y dominios donde se puede usar) y copia el código para su web. Los administradores pueden
 * configurar el de cualquier cliente (eligen cuál; ?client=ID). El servicio lo activa AFI en la ficha del cliente; si no
 * lo tiene, el cliente lo puede pedir con un botón que crea una incidencia (actionRequest).
 */
final class WebchatSettingsPage extends Page
{
    public function index(): void
    {
        $user = $this->user();
        if (!Asteroid::isClient($user) && $user->isAdmin() && !ctype_digit((string) $this->param('client', ''))) {
            // Administradores: primero, de qué cliente
            $this->view(['picker' => $this->app->db->fetchAll(
                'SELECT c.id, c.name, c.webchat, s.active FROM clients c LEFT JOIN webchat_sites s ON s.client_id = c.id
                  WHERE c.active = 1 ORDER BY c.webchat DESC, c.name'
            ), 'pickUrl' => $this->pageUrl()]);
            return;
        }
        $clientId = $this->clientId();
        $client = $this->app->db->fetch('SELECT id, name, webchat FROM clients WHERE id = ?', [$clientId]) ?? throw new HttpException(404);
        if (!(int) $client['webchat']) {
            $this->view([
                'enabled' => false,
                'client' => $client,
                'request' => $this->pendingRequest($clientId),
                'canRequest' => Asteroid::isClient($user) && $this->allowed('create', $this->otherModule('issues')),
                'requestUrl' => $this->pageUrl('request'),
            ]);
            return;
        }
        $site = Webchat::siteForClient($this->app->db, $clientId);
        $errors = [];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->requirePost();
            [$data, $errors] = $this->validate($_POST);
            if (!$errors) {
                $this->app->db->update('webchat_sites', $data, 'id', (int) $site['id']);
                Log::info('Chat web del cliente {client} actualizado', ['client' => $clientId, 'active' => $data['active']]);
                $this->flash('success', $this->text('saved'));
                $this->app->redirect($this->pageUrl('index', $this->user()->isAdmin() ? ['client' => $clientId] : []));
            }
            $site = $data + $site;
        }
        $loader = $this->app->absoluteUrl($this->app->url('public', ['m' => 'webchat_widget', 'a' => 'loader', 'k' => $site['public_key']]));
        $this->view([
            'enabled' => true,
            'client' => $client,
            'site' => $site,
            'errors' => $errors,
            'snippet' => '<script src="' . $loader . '" async></script>',
            'previewUrl' => $this->app->url('public', ['m' => 'webchat_widget', 'k' => $site['public_key']]),
            'inboxUrl' => $this->otherModule('webchat')->url(),
            'action' => $this->pageUrl('index', $this->user()->isAdmin() ? ['client' => $clientId] : []),
        ]);
    }

    /**
     * POST: el cliente pide el chat web a AFI. Crea una incidencia (visible para él, en su primer proyecto activo)
     * con los avisos de siempre al equipo. Si ya hay una abierta, no repite.
     */
    public function actionRequest(): void
    {
        $this->requirePost();
        $user = $this->user();
        $issues = $this->otherModule('issues');
        if (!Asteroid::isClient($user) || !$this->allowed('create', $issues)) {
            throw new HttpException(403);
        }
        $clientId = (int) $user->get('client_id');
        if (($open = $this->pendingRequest($clientId)) !== null) {
            $this->flash('info', $this->text('requested_already', ['id' => $open['id']]));
            $this->app->redirect($this->pageUrl());
        }
        $project = $this->app->db->fetchValue('SELECT id FROM projects WHERE client_id = ? AND active = 1 ORDER BY id LIMIT 1', [$clientId]);
        if ($project === null) {
            $this->flash('error', $this->text('request_no_project'));
            $this->app->redirect($this->pageUrl());
        }
        $result = (new FormHandler($issues, $this->app->db))->process([
            'title' => $this->text('request_title'),
            'project_id' => (string) $project,
            'priority' => '0',
            'description' => '<p>' . htmlspecialchars($this->text('request_body', ['user' => $user->name()]), ENT_QUOTES) . '</p>',
        ], null, $user, true);
        if (!$result['ok']) {
            Log::warning('Chat web: no se ha podido crear la solicitud del cliente {client}', ['client' => $clientId, 'errors' => $result['errors']]);
            $this->flash('error', $this->text('request_failed'));
            $this->app->redirect($this->pageUrl());
        }
        $this->app->settings()->set('webchat.request.' . $clientId, (int) $result['id']);
        Log::info('Chat web: el cliente {client} lo pide (incidencia {id})', ['client' => $clientId, 'id' => $result['id']]);
        $this->flash('success', $this->text('requested', ['id' => $result['id']]));
        $this->app->redirect($this->pageUrl());
    }

    /** La incidencia con la que el cliente pidió el chat, si sigue pendiente o en curso. */
    private function pendingRequest(int $clientId): ?array
    {
        $id = $this->app->settings()->get('webchat.request.' . $clientId);
        return is_int($id) ? $this->app->db->fetch('SELECT id, title FROM issues WHERE id = ? AND status IN (1, 2)', [$id]) : null;
    }

    /** El cliente del usuario; un administrador puede elegir cualquiera (?client=ID). */
    private function clientId(): int
    {
        $user = $this->user();
        if (Asteroid::isClient($user)) {
            return (int) $user->get('client_id');
        }
        if ($user->isAdmin() && ctype_digit((string) $this->param('client', ''))) {
            return (int) $this->param('client');
        }
        throw new HttpException(403);
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function validate(array $in): array
    {
        $errors = [];
        $color = strtolower(trim((string) ($in['color'] ?? '')));
        if (!Webchat::validColor($color)) {
            $errors['color'] = $this->text('errors.color');
        }
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            $errors['name'] = $this->text('errors.name');
        }
        $greeting = trim((string) ($in['greeting'] ?? ''));
        if (mb_strlen($greeting) > 500) {
            $errors['greeting'] = $this->text('errors.greeting');
        }
        $domains = Webchat::domains(['domains' => (string) ($in['domains'] ?? '')]);
        $data = [
            'active' => !empty($in['active']) ? 1 : 0,
            'name' => $name,
            'color' => $color,
            'greeting' => $greeting,
            'domains' => implode("\n", $domains),
            'position' => ($in['position'] ?? '') === 'left' ? 'left' : 'right',
        ];
        return [$data, $errors];
    }
}
