<?php
declare(strict_types=1);

namespace Quasar\Core;

use Quasar\Controllers\ActionController;
use Quasar\Controllers\AdminController;
use Quasar\Controllers\AiController;
use Quasar\Controllers\AuthController;
use Quasar\Controllers\ChatController;
use Quasar\Controllers\ExportController;
use Quasar\Controllers\HomeController;
use Quasar\Controllers\MediaController;
use Quasar\Controllers\ModuleController;
use Quasar\Controllers\NotificationsController;
use Quasar\Controllers\OptionsController;
use Quasar\Controllers\PageController;
use Quasar\Controllers\ProfileController;
use Quasar\Controllers\RemoteController;
use Quasar\Mail\Mail;
use Quasar\Mail\Mailer;
use Quasar\Remote\State;

final class App
{
    /** ruta => [controlador, método, requiere login] */
    private const ROUTES = [
        'login' => [AuthController::class, 'login', false],
        'logout' => [AuthController::class, 'logout', false],
        'verify' => [AuthController::class, 'verify', false],
        'profile' => [ProfileController::class, 'index', true],
        'home' => [HomeController::class, 'index', true],
        'list' => [ModuleController::class, 'list', true],
        'data' => [ModuleController::class, 'data', true],
        'edit' => [ModuleController::class, 'edit', true],
        'save' => [ModuleController::class, 'save', true],
        'delete' => [ModuleController::class, 'delete', true],
        'action' => [ActionController::class, 'run', true],
        'ask' => [AiController::class, 'ask', true],
        'permissions' => [AdminController::class, 'permissions', true],
        'upload' => [MediaController::class, 'upload', true],
        'media' => [MediaController::class, 'show', true],
        'export' => [ExportController::class, 'export', true],
        'page' => [PageController::class, 'dispatch', true],
        'options' => [OptionsController::class, 'index', true],
        'notifications' => [NotificationsController::class, 'handle', true],
        'chat' => [ChatController::class, 'handle', true],
    ];

    private const ASSET_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'webp' => 'image/webp',
        'jpeg' => 'image/jpeg',
    ];

    public readonly Database $db;
    public readonly Auth $auth;
    public readonly Lang $lang;
    public readonly View $view;
    private ?ModuleRegistry $modules = null;
    private ?MediaStorage $media = null;
    private ?Mailer $mailer = null;
    private ?TwoFactor $twoFactor = null;
    private ?Notifier $notifier = null;
    private ?Settings $settings = null;
    private ?ChatService $chat = null;

    public function __construct(public readonly array $config)
    {
        error_reporting(E_ALL);
        ini_set('display_errors', !empty($config['debug']) ? '1' : '0');

        Log::registerErrorHandlers(!empty($config['debug']));
        Mail::setFactory(fn () => $this->mailer());
        Notify::setFactory(fn () => $this->notifier());
        Chat::setFactory(fn () => $this->chat());

        $this->db = new Database($config['db']);
        $this->auth = new Auth($this->db, $config);
        $this->lang = new Lang(QS_ROOT . '/lang', $config['locale'] ?? 'es', $config['fallback_locale'] ?? 'es');
        $this->view = new View($this);
    }

    public function run(): void
    {
        $route = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'home';

        if ($route === 'asset') {
            $this->serveAsset(is_string($_GET['f'] ?? null) ? $_GET['f'] : '');
            return;
        }
        if ($route === 'logo') {
            // Público: se muestra también en la pantalla de login
            $logo = $this->branding()['logo'];
            $logo !== null ? $this->sendStatic($logo) : http_response_code(404);
            return;
        }

        if ($route === 'status' || $route === 'remote') {
            // Panel central (Cosmo): sin sesión ni cookies; autenticado con el token de config 'remote'
            $this->securityHeaders();
            $action = $route === 'status' ? 'status' : (is_string($_GET['a'] ?? null) ? $_GET['a'] : '');
            try {
                (new RemoteController($this))->handle($action);
            } catch (HttpException $e) {
                $this->json(['ok' => false, 'error' => $e->status === 404 ? 'Not Found' : 'HTTP ' . $e->status], $e->status);
            }
            return;
        }

        $maintenance = State::maintenance();
        if ($maintenance !== null) {
            // Actualización en curso: solo responden la API remota y los recursos estáticos
            $this->securityHeaders();
            header('Retry-After: 120');
            $this->renderError(503, $this->lang->get('errors.503'));
            return;
        }

        $this->securityHeaders();
        Session::start($this->config);

        try {
            if (!isset(self::ROUTES[$route])) {
                throw new HttpException(404);
            }
            [$class, $method, $needsAuth] = self::ROUTES[$route];

            $user = $this->auth->user();
            Log::withContext(['user' => $user?->id(), 'r' => $route]);
            $this->lang->setLocale($user?->locale() ?? (string) Session::get('locale', $this->config['locale'] ?? 'es'));

            if ($needsAuth && $user === null) {
                if ($this->wantsJson()) {
                    throw new HttpException(401);
                }
                if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                    Session::set('intended', (string) ($_SERVER['QUERY_STRING'] ?? ''));
                }
                $this->redirect($this->url('login'));
            }

            (new $class($this))->$method();
        } catch (HttpException $e) {
            $this->renderError($e->status, $this->lang->get($e->getMessage()));
        } catch (\Throwable $e) {
            Log::error('Excepción no controlada: {message}', [
                'message' => $e->getMessage(),
                'url' => $_SERVER['REQUEST_URI'] ?? null,
                'exception' => $e,
            ]);
            $this->renderError(500, $this->lang->get('errors.500'), $e);
        }
    }

    public function modules(): ModuleRegistry
    {
        return $this->modules ??= new ModuleRegistry(
            QS_ROOT . '/modules',
            $this->fieldContextFactory(),
            fn (string $feature) => match ($feature) {
                'ai' => \Quasar\Ai\LlmFactory::enabled((array) ($this->config['ai'] ?? [])),
                'reports' => !empty($this->config['reports']['enabled']) && !empty($this->config['reports']['tables']),
                'google' => \Quasar\Google\GoogleClient::configured((array) ($this->config['google'] ?? [])),
                default => false,
            },
        );
    }

    /** @return \Closure(string):FieldContext */
    public function fieldContextFactory(): \Closure
    {
        return fn (string $domain) => new FieldContext(
            $this->db,
            $this->lang,
            $domain,
            fn (string $template, array $vars) => $this->view->render($template, $vars, null),
            fn (string $route, array $params) => $this->url($route, $params),
            $this->media(),
            fn () => $this->auth->user(),
            fn (string $name) => $this->modules()->get($name),
        );
    }

    public function twoFactor(): TwoFactor
    {
        return $this->twoFactor ??= new TwoFactor(
            $this->db,
            $this->lang,
            (array) ($this->config['two_factor'] ?? []),
            (string) ($this->config['app_name'] ?? 'Quasar'),
        );
    }

    public function mailer(): Mailer
    {
        return $this->mailer ??= new Mailer(
            (array) ($this->config['mail'] ?? []),
            Log::channel('mail'),
            $this->view,
            $this->branding(),
            rtrim((string) ($this->config['log']['dir'] ?? QS_ROOT . '/storage/logs'), '/'),
        );
    }

    public function media(): MediaStorage
    {
        return $this->media ??= new MediaStorage(
            $this->db,
            rtrim((string) ($this->config['uploads_dir'] ?? QS_ROOT . '/storage/uploads'), '/'),
            fn (string $route, array $params) => $this->url($route, $params),
        );
    }

    public function template(): string
    {
        $tpl = (string) ($this->config['template'] ?? 'default');
        return preg_match('/^[A-Za-z0-9_-]+$/', $tpl) ? $tpl : 'default';
    }

    public function url(string $route, array $params = []): string
    {
        return 'index.php?' . http_build_query(['r' => $route] + $params);
    }

    /**
     * URL completa de una ruta interna ("index.php?…"), para correos y servicios externos.
     * Usa 'base_url' de la configuración (obligatorio en scripts de consola) o, si no, la petición actual.
     */
    public function absoluteUrl(string $relative = ''): string
    {
        $base = (string) ($this->config['base_url'] ?? '');
        if ($base === '' && isset($_SERVER['HTTP_HOST'])) {
            $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $dir;
        }
        return $base === '' ? $relative : rtrim($base, '/') . '/' . ltrim($relative, '/');
    }

    public function notifier(): Notifier
    {
        return $this->notifier ??= new Notifier(
            $this->db,
            $this->lang,
            (array) ($this->config['notifications'] ?? []),
            fn (string $relative) => $this->absoluteUrl($relative),
        );
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings($this->db);
    }

    public function chat(): ChatService
    {
        return $this->chat ??= new ChatService($this);
    }

    /** Cliente de Google si la instalación tiene 'google' configurado; si no, null. */
    public function google(): ?\Quasar\Google\GoogleClient
    {
        $config = (array) ($this->config['google'] ?? []);
        return \Quasar\Google\GoogleClient::configured($config)
            ? new \Quasar\Google\GoogleClient($config, $this->settings(), $this->absoluteUrl($this->url('page', ['m' => 'integrations', 'a' => 'callback'])))
            : null;
    }

    public function redirect(string $url): never
    {
        header('Location: ' . $url, true, 303);
        exit;
    }

    public function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || isset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    public function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function renderError(int $status, string $message, ?\Throwable $e = null): void
    {
        if ($this->wantsJson()) {
            $this->json(['error' => $message], $status);
            return;
        }
        http_response_code($status);
        $debug = !empty($this->config['debug']) && $e !== null ? (string) $e : null;
        try {
            $user = $status >= 500 ? null : $this->auth->user();
            echo $this->view->render('error', [
                'title' => $this->lang->get('errors.title'),
                'icon' => 'triangle-alert',
                'breadcrumbs' => [[$this->lang->get('home.title'), $this->url('home')], [$this->lang->get('errors.title'), null]],
                'status' => $status,
                'message' => $message,
                'debug' => $debug,
            ], $user ? 'layout' : 'blank');
        } catch (\Throwable $inner) {
            Log::critical('Fallo al mostrar la página de error', ['exception' => $inner]);
            header('Content-Type: text/plain; charset=utf-8');
            echo $status . ' ' . $message . ($debug !== null ? "\n\n" . $debug . "\n\n" . $inner : '');
        }
    }

    private function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; form-action 'self'; frame-ancestors 'self'; base-uri 'self'");
    }

    /**
     * Imagen corporativa ya validada.
     * @return array{logo:?string,logo_pdf:?string,color:?string,company:string}
     */
    public function branding(): array
    {
        $b = (array) ($this->config['branding'] ?? []);
        $logo = $this->brandFile($b['logo'] ?? null, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg']);
        $pdfLogo = $this->brandFile($b['logo_pdf'] ?? null, ['png', 'jpg', 'jpeg'])
            ?? $this->brandFile($b['logo'] ?? null, ['png', 'jpg', 'jpeg']);
        $color = is_string($b['color'] ?? null) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $b['color']) ? $b['color'] : null;
        $company = is_string($b['company'] ?? null) && trim($b['company']) !== '' ? trim($b['company']) : (string) ($this->config['app_name'] ?? 'Quasar');
        return ['logo' => $logo, 'logo_pdf' => $pdfLogo, 'color' => $color, 'company' => $company];
    }

    private function brandFile(mixed $path, array $extensions): ?string
    {
        if (!is_string($path) || $path === '') {
            return null;
        }
        $file = realpath(str_starts_with($path, '/') ? $path : QS_ROOT . '/' . $path);
        $ext = $file ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '';
        return $file && is_file($file) && in_array($ext, $extensions, true) ? $file : null;
    }

    /** Sirve los ficheros de templates/{plantilla}/assets para que la plantilla sea autocontenida. */
    private function serveAsset(string $file): void
    {
        $base = realpath(QS_ROOT . '/templates/' . $this->template() . '/assets');
        $path = $base ? realpath($base . '/' . $file) : false;
        if (!$base || !$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            http_response_code(404);
            return;
        }
        $this->sendStatic($path);
    }

    private function sendStatic(string $path): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::ASSET_TYPES[$ext])) {
            http_response_code(404);
            return;
        }
        $etag = '"' . md5($path . filemtime($path) . filesize($path)) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=604800');
        header('X-Content-Type-Options: nosniff');
        if ($ext === 'svg') {
            // Un SVG abierto directamente no puede ejecutar scripts
            header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:");
        }
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        header('Content-Type: ' . self::ASSET_TYPES[$ext]);
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }
}
