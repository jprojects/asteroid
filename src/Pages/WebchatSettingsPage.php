<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Hooks\Asteroid;
use Quasar\Webchat\Webchat;

/**
 * Asteroid · «El meu xat web» (modules/webchat_settings.json): cada cliente activa y personaliza su chat (nombre,
 * color, saludo, posición y dominios donde se puede usar) y copia el código para su web. Los administradores pueden
 * configurar el de cualquier cliente con ?client=ID. El servicio lo activa AFI en la ficha del cliente.
 */
final class WebchatSettingsPage extends Page
{
    public function index(): void
    {
        $clientId = $this->clientId();
        $client = $this->app->db->fetch('SELECT id, name, webchat FROM clients WHERE id = ?', [$clientId]) ?? throw new HttpException(404);
        if (!(int) $client['webchat']) {
            $this->view(['enabled' => false, 'client' => $client]);
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
