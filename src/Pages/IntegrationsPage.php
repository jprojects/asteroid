<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\Session;
use Quasar\Google\GoogleClient;
use Quasar\Google\GoogleException;

/**
 * Administración → Integraciones (modules/integrations.json): conectar la instalación con una cuenta
 * de Google para crear videollamadas de Google Meet desde las conversaciones. Solo administradores.
 * También es la vuelta de Google (la única dirección registrada) de la conexión personal de cada usuario con su
 * Google Calendar, que se inicia desde el perfil (mine, mineoff): esas acciones las puede usar cualquier usuario.
 */
final class IntegrationsPage extends Page
{
    public const USER_ACTIONS = ['mine', 'mineoff', 'callback'];
    private const STATE = 'google_oauth_state';
    private const MODE = 'google_oauth_mode';

    public function index(): void
    {
        $google = $this->google();
        $this->view([
            'connection' => $google->connection(),
            'calendarEnabled' => $google->calendarEnabled(),
            'redirectUri' => $google->redirectUri(),
            'connectUrl' => $this->pageUrl('connect'),
            'disconnectUrl' => $this->pageUrl('disconnect'),
        ]);
    }

    /** Va a la pantalla de consentimiento de Google. */
    public function actionConnect(): void
    {
        $state = bin2hex(random_bytes(16));
        Session::set(self::STATE, $state);
        Session::set(self::MODE, 'install');
        $this->app->redirect($this->google()->authUrl($state));
    }

    /** Cualquier usuario: conectar su propio Google Calendar (desde el perfil). */
    public function actionMine(): void
    {
        $google = $this->google();
        if (!$google->calendarFor($this->user())) {
            throw new HttpException(404);
        }
        $state = bin2hex(random_bytes(16));
        Session::set(self::STATE, $state);
        Session::set(self::MODE, 'user');
        $this->app->redirect($google->authUrl($state, true));
    }

    /** POST, cualquier usuario: desconectar su Google Calendar. */
    public function actionMineoff(): void
    {
        $this->requirePost();
        $this->google()->disconnectUser($this->user()->id());
        $this->flash('success', $this->text('google.mine_disconnected'));
        $this->app->redirect($this->app->url('profile') . '#google');
    }

    /** Vuelta de Google con el código (o el error) de la autorización. */
    public function actionCallback(): void
    {
        $google = $this->google();
        $expected = Session::get(self::STATE);
        $mode = Session::get(self::MODE);
        Session::remove(self::STATE);
        Session::remove(self::MODE);
        $state = $this->param('state');
        if (!is_string($expected) || $state === null || !hash_equals($expected, $state)) {
            throw new HttpException(400);
        }
        if ($mode === 'user') {
            // Conexión personal: la de quien ha iniciado la sesión
            if (($error = $this->param('error')) !== null) {
                $this->flash('error', $this->text('google.denied', ['error' => $error]));
            } else {
                try {
                    $c = $google->connectUser($this->user()->id(), (string) $this->param('code', ''));
                    $this->flash('success', $this->text('google.mine_connected', ['email' => $c['email']]));
                } catch (GoogleException $e) {
                    Log::error('Google: no se ha podido conectar el calendario personal', ['exception' => $e]);
                    $this->flash('error', $e->getMessage());
                }
            }
            $this->app->redirect($this->app->url('profile') . '#google');
        }
        if (!$this->user()->isAdmin()) {
            throw new HttpException(403); // la conexión de la instalación, solo administradores
        }
        if (($error = $this->param('error')) !== null) {
            $this->flash('error', $this->text('google.denied', ['error' => $error]));
        } else {
            try {
                $connection = $google->connect((string) $this->param('code', ''));
                $this->flash('success', $this->text('google.connected', ['email' => $connection['email']]));
            } catch (GoogleException $e) {
                Log::error('Google: no se ha podido conectar la cuenta', ['exception' => $e]);
                $this->flash('error', $e->getMessage());
            }
        }
        $this->app->redirect($this->pageUrl());
    }

    public function actionDisconnect(): void
    {
        $this->requirePost();
        $this->google()->disconnect();
        $this->flash('success', $this->text('google.disconnected'));
        $this->app->redirect($this->pageUrl());
    }

    private function google(): GoogleClient
    {
        return $this->app->google() ?? throw new HttpException(404);
    }
}
