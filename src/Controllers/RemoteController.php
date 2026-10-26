<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\Migrator;
use Quasar\Core\Version;
use Quasar\Remote\Backups;
use Quasar\Remote\CoreFiles;
use Quasar\Remote\LogReader;
use Quasar\Remote\State;
use Quasar\Remote\Updater;
use Quasar\Remote\UpdateException;

/**
 * API para el panel central de instalaciones (Cosmo): ?r=remote&a=acción  (y ?r=status = a=status).
 *
 * Solo responde si config 'remote.token' tiene al menos 32 caracteres; el panel lo envía en la
 * cabecera X-Quasar-Token (o Authorization: Bearer …). Sin token válido responde 404.
 * Las acciones que cambian algo exigen POST. No abre sesión ni envía cookies.
 */
final class RemoteController extends Controller
{
    /** Versión de esta API; Cosmo la usa para saber qué puede pedir */
    public const API = 1;

    /** acción => [método HTTP, función] */
    private const ACTIONS = [
        'status' => ['GET', 'status'],
        'logs' => ['GET', 'logs'],
        'integrity' => ['GET', 'integrity'],
        'backups' => ['GET', 'backups'],
        'download' => ['GET', 'download'],
        'backup' => ['POST', 'backup'],
        'backup-delete' => ['POST', 'backupDelete'],
        'update' => ['POST', 'update'],
        'migrate' => ['POST', 'migrate'],
        'maintenance' => ['POST', 'maintenance'],
    ];

    public function handle(string $action): void
    {
        $this->authenticate();
        if (!isset(self::ACTIONS[$action])) {
            throw new HttpException(404);
        }
        [$method, $fn] = self::ACTIONS[$action];
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
            throw new HttpException(405);
        }
        if ($method === 'POST') {
            // Las tareas largas siguen aunque el panel corte la conexión
            ignore_user_abort(true);
            @set_time_limit(0);
            Log::info('Acción remota {action}', ['action' => $action, 'ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        }
        $this->$fn();
    }

    private function authenticate(): void
    {
        $remote = (array) ($this->app->config['remote'] ?? []);
        $token = (string) ($remote['token'] ?? '');
        if (strlen($token) < 32) {
            throw new HttpException(404);
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $allowed = (array) ($remote['allow_ips'] ?? []);
        $sent = $this->sentToken();
        if ($sent === '' || !hash_equals($token, $sent) || ($allowed && !in_array($ip, $allowed, true))) {
            Log::warning('Consulta remota con token no válido o IP no permitida', ['ip' => $ip]);
            throw new HttpException(404);
        }
    }

    private function sentToken(): string
    {
        $header = (string) ($_SERVER['HTTP_X_QUASAR_TOKEN'] ?? '');
        if ($header !== '') {
            return trim($header);
        }
        // Algunos hostings (PHP como CGI/FPM) solo la pasan como REDIRECT_HTTP_AUTHORIZATION
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : '';
    }

    // ------------------------------------------------------------------ lectura

    /** Filtrado con IA: si está activo, modelo y consumo del mes (para facturar y vigilar el límite). */
    private function aiStatus(array $ai): array
    {
        if (!\Quasar\Ai\LlmFactory::enabled($ai)) {
            return ['enabled' => false];
        }
        $out = ['enabled' => true, 'provider' => $ai['provider'] ?? 'anthropic', 'model' => $ai['model'] ?? null];
        try {
            $state = (new \Quasar\Ai\Usage($this->app->db, $ai))->state();
            $month = $this->app->db->fetch(
                "SELECT COUNT(*) AS queries, COALESCE(SUM(status = 'error'), 0) AS errors,
                        COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens
                 FROM ai_usage WHERE created_at >= ?",
                [date('Y-m-01 00:00:00')]
            );
            $out += [
                'month_cost_eur' => $state['used'],
                'monthly_limit_eur' => $state['limit'],
                'limit_state' => $state['state'],
                'queries' => (int) $month['queries'],
                'errors' => (int) $month['errors'],
                'input_tokens' => (int) $month['input_tokens'],
                'output_tokens' => (int) $month['output_tokens'],
            ];
        } catch (\Throwable $e) {
            $out['error'] = $e->getMessage(); // p. ej. falta migrar (no existe ai_usage)
        }
        return $out;
    }

    private function status(): void
    {
        $config = $this->app->config;
        $db = ['ok' => false, 'version' => null, 'size' => null, 'tables' => null, 'error' => null];
        $users = null;
        try {
            $db['version'] = (string) $this->app->db->fetchValue('SELECT VERSION()');
            $row = $this->app->db->fetch(
                'SELECT COUNT(*) AS tables, COALESCE(SUM(data_length + index_length), 0) AS size
                 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
            );
            $db['tables'] = (int) ($row['tables'] ?? 0);
            $db['size'] = (int) ($row['size'] ?? 0);
            $db['ok'] = true;
            $u = $this->app->db->fetch(
                'SELECT COUNT(*) AS total, COALESCE(SUM(active), 0) AS active, COALESCE(SUM(is_admin), 0) AS admins,
                        MAX(last_login) AS last_login FROM users'
            );
            $users = [
                'total' => (int) $u['total'],
                'active' => (int) $u['active'],
                'admins' => (int) $u['admins'],
                'last_login' => $u['last_login'],
            ];
        } catch (\Throwable $e) {
            $db['error'] = $e->getMessage();
        }

        $backups = $this->backupsService()->list();
        $integrity = CoreFiles::check();
        $logDir = rtrim((string) ($config['log']['dir'] ?? QS_ROOT . '/storage/logs'), '/');
        $disk = @disk_free_space(QS_ROOT);
        $total = @disk_total_space(QS_ROOT);

        $this->app->json([
            'app' => 'quasar',
            'api' => self::API,
            'version' => Version::current(),
            'name' => (string) ($config['app_name'] ?? 'Quasar'),
            'php' => PHP_VERSION,
            'db' => $db,
            'time' => date(DATE_ATOM),
            'timezone' => date_default_timezone_get(),
            'server' => [
                'software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
                'os' => PHP_OS_FAMILY,
                'sapi' => PHP_SAPI,
                'disk_free' => $disk === false ? null : (int) $disk,
                'disk_total' => $total === false ? null : (int) $total,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'writable' => is_writable(QS_ROOT . '/src') && is_writable(QS_ROOT . '/public'),
            ],
            'extensions' => array_combine(
                $ext = ['pdo_mysql', 'zip', 'gd', 'fileinfo', 'mbstring', 'openssl', 'curl', 'intl', 'Zend OPcache'],
                array_map('extension_loaded', $ext)
            ),
            'config' => [
                'debug' => !empty($config['debug']),
                'locale' => $config['locale'] ?? null,
                'template' => $config['template'] ?? 'default',
                'two_factor' => $config['two_factor']['mode'] ?? 'off',
                'mail_transport' => $config['mail']['transport'] ?? null,
                'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'allow_ips' => !empty($config['remote']['allow_ips']),
                'backups_keep' => max(1, (int) ($config['remote']['backups_keep'] ?? 5)),
            ],
            'modules' => count(glob(QS_ROOT . '/modules/*.json') ?: []),
            'users' => $users,
            'ai' => $this->aiStatus((array) ($config['ai'] ?? [])),
            'maintenance' => State::maintenance(),
            'backups' => ['count' => count($backups), 'last' => $backups[0] ?? null],
            'logs' => (new LogReader($logDir))->counts24h(),
            'integrity' => $integrity === null ? null : [
                'modified' => count($integrity['modified']),
                'missing' => count($integrity['missing']),
            ],
            'last_update' => State::updates()[0] ?? null,
        ]);
    }

    private function logs(): void
    {
        $level = is_string($_GET['level'] ?? null) ? $_GET['level'] : 'warning';
        $limit = min(500, max(1, (int) ($_GET['limit'] ?? 100)));
        $days = min(30, max(1, (int) ($_GET['days'] ?? 7)));
        $dir = rtrim((string) ($this->app->config['log']['dir'] ?? QS_ROOT . '/storage/logs'), '/');
        $this->app->json(['ok' => true, 'entries' => (new LogReader($dir))->recent($level, $limit, $days)]);
    }

    private function integrity(): void
    {
        $check = CoreFiles::check();
        $this->app->json([
            'ok' => true,
            'manifest' => $check !== null,
            'version' => CoreFiles::manifest()['version'] ?? null,
            'modified' => $check['modified'] ?? [],
            'missing' => $check['missing'] ?? [],
            'updates' => State::updates(),
        ]);
    }

    private function backups(): void
    {
        $service = $this->backupsService();
        $this->app->json(['ok' => true, 'backups' => $service->list(), 'disk_free' => @disk_free_space($service->dir()) ?: null]);
    }

    private function download(): void
    {
        $file = $this->backupsService()->path(is_string($_GET['file'] ?? null) ? $_GET['file'] : '');
        if ($file === null) {
            throw new HttpException(404);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Backup-Sha256: ' . hash_file('sha256', $file));
        header('Cache-Control: no-store');
        readfile($file);
    }

    // ------------------------------------------------------------------ acciones

    private function backup(): void
    {
        $type = is_string($_POST['type'] ?? null) ? $_POST['type'] : 'full';
        if (!in_array($type, Backups::TYPES, true)) {
            throw new HttpException(400);
        }
        $label = is_string($_POST['label'] ?? null) ? $_POST['label'] : '';
        $this->exclusive(fn () => $this->app->json(['ok' => true, 'backup' => $this->backupsService()->create($type, $label)]));
    }

    private function backupDelete(): void
    {
        $name = is_string($_POST['file'] ?? null) ? $_POST['file'] : '';
        if (!$this->backupsService()->delete($name)) {
            throw new HttpException(404);
        }
        $this->app->json(['ok' => true]);
    }

    /**
     * POST multipart: package (ZIP), sha256, version, force (0|1), backup (0|1).
     * Deja la aplicación en mantenimiento: lo quita la acción migrate (que ya corre con el código nuevo).
     */
    private function update(): void
    {
        $upload = $_FILES['package'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            $this->fail('No ha llegado el paquete (código de subida ' . ($upload['error'] ?? 'sin fichero') . ', upload_max_filesize=' . ini_get('upload_max_filesize') . ')', 400);
            return;
        }
        $sha = is_string($_POST['sha256'] ?? null) ? $_POST['sha256'] : '';
        $version = is_string($_POST['version'] ?? null) ? $_POST['version'] : '';
        $force = ($_POST['force'] ?? '') === '1';
        $withBackup = ($_POST['backup'] ?? '1') === '1';
        // Se cargan ya: durante la copia sus ficheros se sustituyen por los de la versión nueva
        class_exists(UpdateException::class);
        class_exists(Updater::class);

        $this->exclusive(function () use ($upload, $sha, $version, $force, $withBackup): void {
            $backup = null;
            if ($withBackup) {
                try {
                    $backup = $this->backupsService()->create('full', 'Antes de actualizar a ' . $version);
                } catch (\Throwable $e) {
                    $this->fail('No se pudo hacer la copia previa: ' . $e->getMessage(), 500);
                    return;
                }
            }
            $wasInMaintenance = State::maintenance() !== null;
            State::setMaintenance(true, 'Actualización a ' . $version);
            try {
                $selfTest = ($this->app->config['remote']['self_test'] ?? true) ? fn (string $v) => $this->selfTest($v) : null;
                $report = (new Updater())->install($upload['tmp_name'], $sha, $version, $force, $selfTest);
            } catch (UpdateException $e) {
                if (!$wasInMaintenance) {
                    State::setMaintenance(false);
                }
                $this->fail($e->getMessage(), 422, $e->data + ['backup' => $backup]);
                return;
            }
            $this->app->json(['ok' => true, 'report' => $report, 'backup' => $backup, 'maintenance' => true]);
        });
    }

    private function migrate(): void
    {
        $this->exclusive(function (): void {
            try {
                $steps = (new Migrator($this->app->db, (string) $this->app->config['db']['name']))->run(false);
            } catch (\Throwable $e) {
                Log::error('Migración remota fallida', ['exception' => $e]);
                $this->fail('Error en la migración: ' . $e->getMessage(), 500, ['maintenance' => State::maintenance() !== null]);
                return;
            }
            $keep = ($_POST['keep_maintenance'] ?? '') === '1';
            if (!$keep) {
                State::setMaintenance(false);
            }
            $this->app->json(['ok' => true, 'steps' => $steps, 'version' => Version::current(), 'maintenance' => $keep]);
        });
    }

    private function maintenance(): void
    {
        $on = ($_POST['on'] ?? '') === '1';
        State::setMaintenance($on, is_string($_POST['reason'] ?? null) ? mb_substr($_POST['reason'], 0, 200) : '');
        $this->app->json(['ok' => true, 'maintenance' => State::maintenance()]);
    }

    // ------------------------------------------------------------------ utilidades

    /**
     * Pide el estado a esta misma instalación (por la misma URL que ha usado el panel), que ya
     * carga el código nuevo: así se sabe si la versión instalada arranca.
     * @return array{reachable:bool,ok:bool,error:?string}
     */
    private function selfTest(string $version): array
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/index.php'), PHP_URL_PATH);
        if ($host === '' || !preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $host)) {
            return ['reachable' => false, 'ok' => false, 'error' => 'host desconocido'];
        }
        $url = ($https ? 'https' : 'http') . '://' . $host . $path . '?r=remote&a=status';
        $headers = ['X-Quasar-Token: ' . $this->app->config['remote']['token'], 'Accept: application/json'];

        $body = false;
        $code = 0;
        $error = null;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $body === false ? curl_error($ch) : null;
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 30, 'ignore_errors' => true]]);
            $body = @file_get_contents($url, false, $ctx);
            $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s?/', $http_response_header[0], $m) ? (int) $m[1] : 0;
            $error = $body === false ? 'sin respuesta' : null;
        }
        if ($body === false || $code === 0) {
            return ['reachable' => false, 'ok' => false, 'error' => (string) $error];
        }
        $data = json_decode((string) $body, true);
        if ($code !== 200 || !is_array($data) || ($data['app'] ?? null) !== 'quasar') {
            return ['reachable' => true, 'ok' => false, 'error' => "HTTP $code: " . mb_substr(trim(strip_tags((string) $body)), 0, 300)];
        }
        if (($data['version'] ?? null) !== $version) {
            return ['reachable' => true, 'ok' => false, 'error' => 'responde la versión ' . ($data['version'] ?? '?') . " en lugar de $version"];
        }
        return ['reachable' => true, 'ok' => true, 'error' => null];
    }

    private function backupsService(): Backups
    {
        return new Backups($this->app->config);
    }

    /** Una sola tarea pesada (copia, actualización, migración) a la vez. */
    private function exclusive(\Closure $task): void
    {
        $lock = fopen(State::dir() . '/task.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->fail('Ya hay otra tarea en curso en esta instalación', 409);
            return;
        }
        try {
            $task();
        } catch (\Throwable $e) {
            Log::error('Acción remota fallida', ['exception' => $e]);
            $this->fail($e->getMessage(), 500);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function fail(string $message, int $status, array $data = []): void
    {
        $this->app->json(['ok' => false, 'error' => $message] + ($data ? ['data' => $data] : []), $status);
    }
}
