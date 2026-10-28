<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\Log;
use Quasar\Core\Session;
use Quasar\Core\TwoFactor;

final class AuthController extends Controller
{
    public function login(): void
    {
        $locales = (array) ($this->app->config['locales'] ?? []);
        $lang = $_GET['lang'] ?? $_POST['lang'] ?? null;
        if (is_string($lang) && isset($locales[$lang])) {
            Session::set('locale', $lang);
            $this->app->lang->setLocale($lang);
        }

        if ($this->app->auth->user() !== null) {
            $this->app->redirect($this->app->url('home'));
        }

        $loginField = $this->app->auth->loginField();
        $error = null;
        $username = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

            // Token caducado (p. ej. pestaña abierta mucho tiempo): se vuelve a mostrar el formulario
            $result = match (true) {
                !Session::checkCsrf($_POST['_csrf'] ?? null) => ['error' => 'errors.419', 'row' => null],
                $username === '' || $password === '' => ['error' => 'login.invalid', 'row' => null],
                default => $this->app->auth->verifyCredentials($username, $password),
            };
            $error = $result['error'];
            $row = $result['row'];

            if ($row !== null) {
                $twoFactor = $this->app->twoFactor();
                if ($twoFactor->appliesTo($row) && !$twoFactor->isTrusted($row)) {
                    $error = $twoFactor->start($row);
                    if ($error === null) {
                        $this->app->redirect($this->app->url('verify'));
                    }
                } else {
                    $this->finishLogin($row);
                }
            }
            Log::warning('Login fallido para "{username}": {reason}', ['username' => $username, 'reason' => $error]);
        }

        // "Usuario o contraseña incorrectos" → "Email o contraseña…" si se entra con el email
        if ($error === 'login.invalid' && $loginField !== 'username') {
            $error = 'login.invalid_' . $loginField;
        }
        $this->render('login', [
            'title' => $this->t('login.title'),
            'error' => $error ? $this->t($error) : null,
            'username' => $username,
            'loginField' => $loginField,
        ], 'blank', $error ? 401 : 200);
    }

    /** Segundo paso: código recibido por email. */
    public function verify(): void
    {
        $twoFactor = $this->app->twoFactor();
        $row = $twoFactor->pendingUser();
        if ($row === null) {
            $twoFactor->clear();
            Session::flash('info', $this->t('two_factor.expired'));
            $this->app->redirect($this->app->url('login'));
        }

        $error = null;
        $notice = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
                $error = 'errors.419';
            } elseif (isset($_POST['_resend'])) {
                $error = $twoFactor->resend();
                $notice = $error === null ? 'two_factor.resent' : null;
            } else {
                $result = $twoFactor->verify(is_string($_POST['code'] ?? null) ? $_POST['code'] : '', $this->app->auth);
                if ($result === 'ok') {
                    if (!empty($_POST['remember'])) {
                        $twoFactor->trustDevice((int) $row['id']);
                    }
                    $this->finishLogin($row);
                }
                if (in_array($result, ['login.locked', 'two_factor.expired'], true)) {
                    Session::flash('error', $this->t($result));
                    $this->app->redirect($this->app->url('login'));
                }
                $error = $result;
            }
        }

        $wait = $twoFactor->secondsToResend();
        $this->render('verify', [
            'title' => $this->t('two_factor.title'),
            'email' => TwoFactor::maskEmail((string) $row['email']),
            'error' => $error ? $this->t($error, ['seconds' => $wait]) : null,
            'notice' => $notice ? $this->t($notice) : null,
            'rememberDays' => $twoFactor->rememberDays(),
            'resendWait' => $wait,
            'codeLength' => min(10, max(4, (int) ($this->app->config['two_factor']['code_length'] ?? 6))),
        ], 'blank', $error ? 401 : 200);
    }

    public function logout(): void
    {
        $this->requirePost();
        Log::info('Logout');
        $this->app->auth->logout();
        Session::flash('info', $this->t('login.logged_out'));
        $this->app->redirect($this->app->url('login'));
    }

    private function finishLogin(array $row): never
    {
        $this->app->auth->completeLogin($row);
        Log::withContext(['user' => (int) $row['id']]);
        Log::info('Login correcto: {username}', ['username' => $row['username']]);
        $intended = (string) Session::get('intended', '');
        Session::remove('intended');
        $this->app->redirect($this->safeIntended($intended) ?? $this->app->url('home'));
    }

    /** Solo se permite volver a rutas internas de navegación. */
    private function safeIntended(string $query): ?string
    {
        parse_str($query, $params);
        if (!$params) {
            return null;
        }
        $route = $params['r'] ?? 'home';
        if (!is_string($route) || !in_array($route, ['home', 'list', 'edit', 'page', 'permissions', 'profile'], true)) {
            return null;
        }
        return 'index.php?' . http_build_query($params);
    }
}
