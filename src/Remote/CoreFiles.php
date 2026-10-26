<?php
declare(strict_types=1);

namespace Quasar\Remote;

/**
 * Qué ficheros son del núcleo (core.json) y cuáles instaló la última actualización (manifiesto).
 *
 * El manifiesto (storage/remote/manifest.json) guarda el sha1 de cada fichero del núcleo instalado;
 * sirve para detectar cambios hechos a mano y para borrar los que una versión nueva ya no trae.
 */
final class CoreFiles
{
    /** Carpetas que nunca se recorren */
    private const SKIP_DIRS = ['.git', 'node_modules', 'storage/backups', 'storage/remote', 'storage/logs', 'storage/uploads'];

    /** @return list<string> patrones de core.json de la carpeta $root */
    public static function patterns(string $root): array
    {
        $file = $root . '/core.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $core = is_array($data) && is_array($data['core'] ?? null) ? $data['core'] : [];
        return array_values(array_filter($core, 'is_string'));
    }

    /** @param list<string> $patterns */
    public static function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            $regex = '~^' . strtr(preg_quote($pattern, '~'), ['\*\*' => '.*', '\*' => '[^/]*']) . '$~';
            if (preg_match($regex, $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ficheros del núcleo bajo $root.
     * @param list<string> $patterns
     * @return array<string,string> ruta relativa => sha1
     */
    public static function scan(string $root, array $patterns): array
    {
        $files = [];
        foreach (self::walk($root) as $rel => $abs) {
            if (self::matches($rel, $patterns)) {
                $files[$rel] = (string) sha1_file($abs);
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Todos los ficheros bajo $root salvo las carpetas de SKIP_DIRS (y $extraSkip).
     * @param list<string> $extraSkip
     * @return \Generator<string,string> ruta relativa => absoluta
     */
    public static function walk(string $root, array $extraSkip = []): \Generator
    {
        $skip = array_merge(self::SKIP_DIRS, $extraSkip);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $f) use ($root, $skip): bool {
                    $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
                    return !($f->isDir() && in_array($rel, $skip, true)) && !$f->isLink();
                }
            )
        );
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            if ($f->isFile()) {
                yield substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1) => $f->getPathname();
            }
        }
    }

    public static function manifestFile(): string
    {
        return QS_ROOT . '/storage/remote/manifest.json';
    }

    /** @return array{version:string,installed_at:string,files:array<string,string>}|null */
    public static function manifest(): ?array
    {
        $file = self::manifestFile();
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) && is_array($data['files'] ?? null) ? $data : null;
    }

    /** @param array<string,string> $files */
    public static function saveManifest(string $version, array $files): void
    {
        State::writeJson(self::manifestFile(), [
            'version' => $version,
            'installed_at' => date(DATE_ATOM),
            'files' => $files,
        ]);
    }

    /**
     * Ficheros del núcleo cambiados respecto al manifiesto (null si no hay manifiesto).
     * @return array{modified:list<string>,missing:list<string>}|null
     */
    public static function check(): ?array
    {
        $manifest = self::manifest();
        if ($manifest === null) {
            return null;
        }
        $modified = $missing = [];
        foreach ($manifest['files'] as $rel => $hash) {
            $abs = QS_ROOT . '/' . $rel;
            if (!is_file($abs)) {
                $missing[] = $rel;
            } elseif (sha1_file($abs) !== $hash) {
                $modified[] = $rel;
            }
        }
        return ['modified' => $modified, 'missing' => $missing];
    }
}
