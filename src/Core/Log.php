<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Fachada de log utilizable desde cualquier punto del código:
 *
 *   use Quasar\Core\Log;
 *
 *   Log::debug('Precio calculado {total}', ['total' => $total, 'lineas' => $lineas]);
 *   Log::error('Fallo al sincronizar', ['exception' => $e]);
 *   Log::channel('facturacion')->info('Factura {num} emitida', ['num' => $num]);
 *
 * Niveles: debug < info < notice < warning < error < critical. Solo se escribe
 * lo que alcanza el nivel configurado en config 'log.level'.
 */
final class Log
{
    private static array $config = [];
    /** @var array<string,Logger> */
    private static array $channels = [];
    /** @var array<string,scalar|null> */
    private static array $extra = [];

    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$channels = [];
        self::$extra['req'] = substr(bin2hex(random_bytes(4)), 0, 6);
        if (PHP_SAPI === 'cli') {
            self::$extra['cli'] = basename((string) ($_SERVER['argv'][0] ?? 'php'));
        } else {
            self::$extra['ip'] = $_SERVER['REMOTE_ADDR'] ?? null;
        }
    }

    /** Añade datos a todas las líneas siguientes (p. ej. el usuario autenticado). */
    public static function withContext(array $extra): void
    {
        self::$extra = array_merge(self::$extra, $extra);
    }

    public static function channel(string $name = 'app'): Logger
    {
        $name = preg_match('/^[a-z0-9_-]{1,40}$/', $name) ? $name : 'app';
        return self::$channels[$name] ??= new Logger(
            rtrim((string) (self::$config['dir'] ?? sys_get_temp_dir() . '/quasar-logs'), '/'),
            $name,
            Logger::LEVELS[self::$config['level'] ?? 'info'] ?? Logger::LEVELS['info'],
            max(1, (int) (self::$config['max_files'] ?? 30)),
            static fn () => self::$extra,
        );
    }

    public static function debug(string $message, array $context = []): void
    {
        self::channel()->debug($message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::channel()->info($message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        self::channel()->notice($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::channel()->warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::channel()->error($message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        self::channel()->critical($message, $context);
    }

    /**
     * Captura avisos de PHP (warnings, notices, deprecated) y errores fatales.
     * Respeta el operador @ y error_reporting().
     */
    public static function registerErrorHandlers(bool $debug): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line) use ($debug): bool {
            if (!(error_reporting() & $no)) {
                return false; // suprimido con @
            }
            $level = match ($no) {
                E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning',
                E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
                default => 'notice', // notices y deprecated
            };
            self::channel()->log($level, 'PHP: {msg} en {file}:{line}', ['msg' => $msg, 'file' => $file, 'line' => $line]);
            return !$debug; // en modo debug PHP también lo muestra
        });

        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::channel()->critical('Error fatal de PHP: {msg} en {file}:{line}', [
                    'msg' => $e['message'],
                    'file' => $e['file'],
                    'line' => $e['line'],
                ]);
            }
        });
    }
}
