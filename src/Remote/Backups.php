<?php
declare(strict_types=1);

namespace Quasar\Remote;

use Quasar\Core\Version;

/**
 * Copias de seguridad en storage/backups/ (protegida por storage/.htaccess).
 *
 * Cada copia es un ZIP con:
 *   backup.json    versión, fecha, tipo… (también va como comentario del ZIP, para listar rápido)
 *   database.sql   volcado completo (tipos full y db)
 *   files/…        el proyecto entero salvo .git, logs, otras copias y storage/remote (tipos full y files)
 */
final class Backups
{
    public const TYPES = ['full', 'db', 'files'];
    private const NAME = '/^backup-\d{8}-\d{6}-(full|db|files)-[0-9a-f]{6}\.zip$/';

    public function __construct(private readonly array $config)
    {
    }

    public function dir(): string
    {
        $dir = QS_ROOT . '/storage/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se puede crear $dir");
        }
        return $dir;
    }

    /** @return array<string,mixed> datos de la copia creada */
    public function create(string $type, string $label = ''): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Tipo de copia no válido: $type");
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Falta la extensión zip de PHP');
        }
        $started = microtime(true);
        $name = sprintf('backup-%s-%s-%s.zip', date('Ymd-His'), $type, bin2hex(random_bytes(3)));
        $file = $this->dir() . '/' . $name;
        $partial = $file . '.part';
        $sqlFile = null;

        $meta = [
            'app' => 'quasar',
            'version' => Version::current(),
            'name' => (string) ($this->config['app_name'] ?? 'Quasar'),
            'type' => $type,
            'label' => mb_substr($label, 0, 100),
            'created_at' => date(DATE_ATOM),
            'db' => null,
            'files' => null,
        ];

        try {
            $zip = new \ZipArchive();
            if ($zip->open($partial, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException("No se puede crear $partial");
            }
            if ($type !== 'files') {
                $sqlFile = State::dir('tmp') . '/dump-' . bin2hex(random_bytes(4)) . '.sql';
                $meta['db'] = ['name' => $this->config['db']['name'] ?? null] + (new SqlDump($this->config['db']))->toFile($sqlFile);
                $zip->addFile($sqlFile, 'database.sql');
            }
            if ($type !== 'db') {
                $count = 0;
                $bytes = 0;
                foreach (CoreFiles::walk(QS_ROOT) as $rel => $abs) {
                    $zip->addFile($abs, 'files/' . $rel);
                    $count++;
                    $bytes += (int) filesize($abs);
                }
                // Los subidos se recorren aparte: walk() salta storage/uploads por defecto
                $uploads = rtrim((string) ($this->config['uploads_dir'] ?? QS_ROOT . '/storage/uploads'), '/');
                if (is_dir($uploads)) {
                    $prefix = str_starts_with($uploads, QS_ROOT . '/') ? substr($uploads, strlen(QS_ROOT) + 1) : 'storage/uploads';
                    foreach (CoreFiles::walk($uploads) as $rel => $abs) {
                        $zip->addFile($abs, 'files/' . $prefix . '/' . $rel);
                        $count++;
                        $bytes += (int) filesize($abs);
                    }
                }
                $meta['files'] = ['count' => $count, 'bytes' => $bytes];
            }
            $json = json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $zip->addFromString('backup.json', $json);
            $zip->setArchiveComment((string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (!$zip->close()) {
                throw new \RuntimeException('No se pudo cerrar el ZIP: ' . $zip->getStatusString());
            }
            if (!rename($partial, $file)) {
                throw new \RuntimeException("No se puede mover la copia a $file");
            }
        } catch (\Throwable $e) {
            @unlink($partial);
            throw $e;
        } finally {
            if ($sqlFile !== null) {
                @unlink($sqlFile);
            }
        }

        $this->prune();
        return $this->describe($file) + ['sha256' => hash_file('sha256', $file), 'seconds' => round(microtime(true) - $started, 1)];
    }

    /** @return list<array<string,mixed>> la más reciente primero */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir() . '/backup-*.zip') ?: [] as $file) {
            if (preg_match(self::NAME, basename($file))) {
                $out[] = $this->describe($file);
            }
        }
        usort($out, static fn (array $a, array $b) => strcmp($b['file'], $a['file']));
        return $out;
    }

    /** Ruta de una copia por su nombre, o null si no existe / el nombre no es válido. */
    public function path(string $name): ?string
    {
        if (!preg_match(self::NAME, $name)) {
            return null;
        }
        $file = $this->dir() . '/' . $name;
        return is_file($file) ? $file : null;
    }

    public function delete(string $name): bool
    {
        $file = $this->path($name);
        return $file !== null && unlink($file);
    }

    /** Conserva las 'remote.backups_keep' más recientes (por defecto 5). */
    private function prune(): void
    {
        $keep = max(1, (int) ($this->config['remote']['backups_keep'] ?? 5));
        foreach (array_slice($this->list(), $keep) as $old) {
            @unlink($this->dir() . '/' . $old['file']);
        }
    }

    /** @return array<string,mixed> */
    private function describe(string $file): array
    {
        $meta = [];
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) === true) {
            $meta = json_decode((string) $zip->getArchiveComment(), true) ?: [];
            $zip->close();
        }
        preg_match(self::NAME, basename($file), $m);
        return [
            'file' => basename($file),
            'size' => (int) filesize($file),
            'type' => $meta['type'] ?? ($m[1] ?? null),
            'label' => $meta['label'] ?? '',
            'version' => $meta['version'] ?? null,
            'created_at' => $meta['created_at'] ?? date(DATE_ATOM, (int) filemtime($file)),
        ];
    }
}
