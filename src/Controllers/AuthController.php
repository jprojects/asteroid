<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\HttpException;
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
            'error' => $error ? $this->t($error, ['minutes' => $this->app->auth->lockMinutes()]) : null,
            'locked' => $error === 'login.locked',
            'username' => $username,
            'loginField' => $loginField,
            'canReset' => $this->app->passwordReset()->enabled(),
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
                    Session::flash('error', $this->t($result, ['minutes' => $this->app->auth->lockMinutes()]));
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

    /** «He olvidado la contraseña»: pide el usuario o el email y envía un enlace (ver PasswordReset). */
    public function forgot(): void
    {
        $reset = $this->app->passwordReset();
        if (!$reset->enabled()) {
            throw new HttpException(404);
        }
        $loginField = $this->app->auth->loginField();
        $error = null;
        $sent = false;
        $send = null;
        $login = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $login = is_string($_POST['login'] ?? null) ? mb_substr(trim($_POST['login']), 0, 150) : '';
            if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
                $error = 'errors.419';
            } elseif ($login === '') {
                $error = 'validation.required';
            } else {
                // La respuesta es la misma tanto si el usuario existe como si no
                $send = $reset->request($login, $loginField);
                $sent = true;
            }
        }
        $this->render('forgot', [
            'title' => $this->t('reset.title'),
            'error' => $error ? $this->t($error) : null,
            'sent' => $sent,
            'login' => $login,
            'loginField' => $loginField,
        ], 'blank');
        if ($send !== null) {
            // El correo se envía después de responder: el tiempo de respuesta no delata si el usuario existe
            session_write_close();
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            $send();
        }
    }

    /**
     * Contraseña nueva con el enlace del correo. El token pasa a la sesión y se quita de la URL
     * (no queda en el historial ni en los registros del servidor después de la primera visita).
     */
    public function reset(): void
    {
        $reset = $this->app->passwordReset();
        if (!$reset->enabled()) {
            throw new HttpException(404);
        }
        if (is_string($_GET['token'] ?? null)) {
            Session::set('reset_token', $_GET['token']);
            $this->app->redirect($this->app->url('reset'));
        }
        $token = (string) Session::get('reset_token', '');
        $row = $reset->userForToken($token);
        if ($row === null) {
            Session::remove('reset_token');
            Session::flash('error', $this->t('reset.invalid_link'));
            $this->app->redirect($this->app->url('forgot'));
        }

        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $confirm = is_string($_POST['password_confirm'] ?? null) ? $_POST['password_confirm'] : '';
            if (!Session::checkCsrf($_POST['_csrf'] ?? null)) {
                $error = $this->t('errors.419');
            } elseif (mb_strlen($password) < $reset->minLength() || strlen($password) > 255) {
                $error = $this->t('validation.minlength', ['min' => $reset->minLength()]);
            } elseif ($password !== $confirm) {
                $error = $this->t('reset.mismatch');
            } elseif (!$reset->complete($token, $password, $this->app->twoFactor())) {
                $error = $this->t('reset.invalid_link');
            } else {
                Session::remove('reset_token');
                Session::regenerate();
                Session::flash('success', $this->t('reset.done'));
                $this->app->redirect($this->app->url('login'));
            }
        }
        $this->render('reset', [
            'title' => $this->t('reset.title'),
            'error' => $error,
            'name' => (string) ($row['name'] ?: $row['username']),
            'minLength' => $reset->minLength(),
        ], 'blank', $error ? 422 : 200);
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
