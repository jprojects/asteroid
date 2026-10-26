<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Escribe en storage/logs/{canal}-{AAAA-MM-DD}.log, un fichero por día,
 * borrando los más antiguos que max_files. Se usa a través de la fachada Log.
 *
 * Formato de línea:
 *   [2026-10-19 11:30:12.123] ERROR [req:3fa9c1 user:3 ip:10.0.0.4] Mensaje {"contexto":"…"}
 */
final class Logger
{
    public const LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
    ];

    /** Claves de contexto cuyo valor nunca se escribe */
    private const SECRET_KEYS = '/pass(word)?|secret|token|_csrf|authorization|api[_-]?key/i';

    /**
     * @param \Closure():array<string,scalar> $extra datos comunes de la petición (req, user, ip)
     */
    public function __construct(
        private readonly string $dir,
        private readonly string $channel,
        private readonly int $minLevel,
        private readonly int $maxFiles,
        private readonly \Closure $extra,
    ) {
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function isEnabled(string $level): bool
    {
        return (self::LEVELS[$level] ?? 0) >= $this->minLevel;
    }

    /**
     * @param string $message admite {clave} que se sustituye por $context['clave'] (estilo PSR-3)
     * @param array $context  datos adicionales; 'exception' => Throwable añade la traza
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->isEnabled($level)) {
            return;
        }
        $exception = $context['exception'] ?? null;
        unset($context['exception']);
        $context = self::mask($context);

        $message = preg_replace_callback('/\{([\w.]+)\}/', static function (array $m) use (&$context) {
            if (!array_key_exists($m[1], $context) || !is_scalar($context[$m[1]]) && $context[$m[1]] !== null) {
                return $m[0];
            }
            $value = $context[$m[1]];
            unset($context[$m[1]]);
            return $value === null ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }, $message) ?? $message;

        $extra = [];
        foreach (($this->extra)() as $k => $v) {
            if ($v !== null && $v !== '') {
                $extra[] = "$k:$v";
            }
        }
        $time = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)))
            ?: new \DateTimeImmutable();
        $time = $time->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $line = sprintf(
            '[%s] %s [%s] %s',
            $time->format('Y-m-d H:i:s.v'),
            strtoupper($level),
            implode(' ', $extra),
            str_replace(["\r", "\n"], ['\\r', '\\n'], $message)
        );
        if ($context) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($exception instanceof \Throwable) {
            $line .= "\n" . self::describe($exception);
        }
        $this->write($line . "\n");
    }

    private function write(string $text): void
    {
        $file = sprintf('%s/%s-%s.log', $this->dir, $this->channel, date('Y-m-d'));
        try {
            $isNew = !is_file($file);
            if ($isNew && !is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                throw new \RuntimeException("No se puede crear {$this->dir}");
            }
            if (@file_put_contents($file, $text, FILE_APPEND | LOCK_EX) === false) {
                throw new \RuntimeException("No se puede escribir en $file");
            }
            if ($isNew) {
                $this->rotate();
            }
        } catch (\Throwable $e) {
            // Último recurso: el log de errores de PHP
            error_log('[quasar] ' . $e->getMessage() . ' · ' . trim($text));
        }
    }

    private function rotate(): void
    {
        $files = glob(sprintf('%s/%s-????-??-??.log', $this->dir, $this->channel)) ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - $this->maxFiles)) as $old) {
            @unlink($old);
        }
    }

    /** Oculta valores de claves sensibles, también anidadas. */
    private static function mask(array $context): array
    {
        foreach ($context as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_KEYS, $k)) {
                $context[$k] = '***';
            } elseif (is_array($v)) {
                $context[$k] = self::mask($v);
            } elseif ($v instanceof \Throwable) {
                $context[$k] = get_class($v) . ': ' . $v->getMessage();
            } elseif (is_object($v) && !$v instanceof \JsonSerializable) {
                $context[$k] = $v instanceof \DateTimeInterface ? $v->format('c') : get_class($v);
            }
        }
        return $context;
    }

    private static function describe(\Throwable $e): string
    {
        $out = '';
        $prefix = '';
        do {
            $out .= sprintf("  %s%s: %s en %s:%d\n", $prefix, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
            $out .= '    ' . str_replace("\n", "\n    ", $e->getTraceAsString()) . "\n";
            $prefix = 'Causado por ';
        } while ($e = $e->getPrevious());
        return rtrim($out);
    }
}
