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
 */
final class IntegrationsPage extends Page
{
    private const STATE = 'google_oauth_state';

    public function index(): void
    {
        $google = $this->google();
        $this->view([
            'connection' => $google->connection(),
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
        $this->app->redirect($this->google()->authUrl($state));
    }

    /** Vuelta de Google con el código (o el error) de la autorización. */
    public function actionCallback(): void
    {
        $google = $this->google();
        $expected = Session::get(self::STATE);
        Session::remove(self::STATE);
        $state = $this->param('state');
        if (!is_string($expected) || $state === null || !hash_equals($expected, $state)) {
            throw new HttpException(400);
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
