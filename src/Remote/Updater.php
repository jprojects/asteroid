<?php
declare(strict_types=1);

namespace Quasar\Remote;

use Quasar\Core\Log;
use Quasar\Core\Version;

/**
 * Instala un paquete de versión (el ZIP de una etiqueta de GitHub que envía Cosmo).
 *
 * 1. Valida el ZIP (sha256, rutas, VERSION, core.json) y lo extrae en storage/remote/tmp.
 * 2. Si hay manifiesto y algún fichero del núcleo se ha cambiado a mano, se detiene (salvo $force).
 * 3. Copia los ficheros del núcleo según el core.json NUEVO; guarda los originales para deshacer.
 * 4. Borra los del núcleo que la versión anterior instaló y la nueva ya no trae (solo con manifiesto,
 *    así nunca se borra un fichero que haya añadido el proyecto).
 * 5. Autoprueba: pide ?r=remote&a=status a la propia instalación, que ya responde con el código nuevo.
 *    Si la versión nueva no arranca, se deshace (si no se puede conectar consigo misma, solo se avisa).
 * 6. Si algo falla, restaura todo lo tocado. Si va bien, escribe el manifiesto nuevo.
 *
 * Las migraciones de BD van aparte (acción migrate), en otra petición, para que las ejecute el código nuevo.
 */
final class Updater
{
    private const MAX_FILES = 5000;

    /** @var list<array{0:string,1:?string}> [ruta destino, copia del original | null si era nuevo] */
    private array $touched = [];

    /**
     * @param ?\Closure(string):array{reachable:bool,ok:bool,error:?string} $selfTest recibe la versión esperada
     * @return array<string,mixed> informe
     */
    public function install(string $zipFile, string $sha256, string $expectedVersion, bool $force = false, ?\Closure $selfTest = null): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new UpdateException('Falta la extensión zip de PHP');
        }
        if (!hash_equals(strtolower($sha256), hash_file('sha256', $zipFile))) {
            throw new UpdateException('El sha256 del paquete no coincide (subida incompleta o alterada)');
        }

        $work = State::dir('tmp') . '/update-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $pkg = $work . '/pkg';
        $rollback = $work . '/rollback';
        $from = Version::current();
        $started = microtime(true);

        try {
            $this->extract($zipFile, $pkg);

            $version = trim((string) @file_get_contents($pkg . '/VERSION'));
            if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
                throw new UpdateException('El paquete no tiene un fichero VERSION válido');
            }
            if ($expectedVersion !== '' && $version !== $expectedVersion) {
                throw new UpdateException("El paquete es la versión $version y se esperaba $expectedVersion");
            }
            if (!is_file($pkg . '/src/Core/App.php') || !is_file($pkg . '/public/index.php')) {
                throw new UpdateException('El paquete no parece una versión de Quasar');
            }
            $patterns = CoreFiles::patterns($pkg);
            if ($patterns === []) {
                throw new UpdateException('El paquete no tiene core.json: no se sabe qué ficheros son del núcleo');
            }

            $manifest = CoreFiles::manifest();
            $local = CoreFiles::check();
            $changedByHand = $local === null ? [] : $local['modified'];
            if ($changedByHand && !$force) {
                throw new UpdateException('Hay ficheros del núcleo modificados a mano', ['modified' => $changedByHand]);
            }

            $newFiles = CoreFiles::scan($pkg, $patterns);
            $copy = [];
            foreach ($newFiles as $rel => $hash) {
                $target = QS_ROOT . '/' . $rel;
                if (!is_file($target) || sha1_file($target) !== $hash) {
                    $copy[] = $rel;
                }
            }
            $delete = [];
            if ($manifest !== null) {
                foreach (array_keys($manifest['files']) as $rel) {
                    if (!isset($newFiles[$rel]) && is_file(QS_ROOT . '/' . $rel)) {
                        $delete[] = $rel;
                    }
                }
            }

            foreach ($copy as $rel) {
                $this->place($pkg . '/' . $rel, QS_ROOT . '/' . $rel, $rollback . '/' . $rel);
            }
            foreach ($delete as $rel) {
                $this->remove(QS_ROOT . '/' . $rel, $rollback . '/' . $rel);
            }
            self::flushOpcache(array_map(static fn (array $t) => $t[0], $this->touched));

            $test = $selfTest !== null && $this->touched ? $selfTest($version) : null;
            if ($test !== null && $test['reachable'] && !$test['ok']) {
                throw new UpdateException('La versión nueva no arranca y se ha deshecho la actualización: ' . $test['error']);
            }

            CoreFiles::saveManifest($version, $newFiles);
        } catch (\Throwable $e) {
            $restored = $this->undo();
            self::flushOpcache([]);
            self::removeTree($work);
            if ($restored > 0) {
                Log::error('Actualización fallida, {n} ficheros restaurados', ['n' => $restored, 'exception' => $e]);
            }
            throw $e instanceof UpdateException ? $e : new UpdateException('Error al instalar: ' . $e->getMessage(), ['restored' => $restored], $e);
        }

        self::removeTree($work);

        $report = [
            'from' => $from,
            'to' => $version,
            'copied' => count($copy),
            'deleted' => count($delete),
            'overwritten_modified' => $changedByHand,
            'first_manifest' => $manifest === null,
            'self_test' => $test === null ? 'skipped' : ($test['reachable'] ? 'ok' : 'unreachable: ' . $test['error']),
            'seconds' => round(microtime(true) - $started, 1),
            'installed_at' => date(DATE_ATOM),
        ];
        State::addUpdate($report);
        Log::notice('Actualizado de {from} a {to}', ['from' => $from, 'to' => $version, 'copied' => count($copy), 'deleted' => count($delete)]);
        return $report;
    }

    /** Extrae solo lo que hay dentro de la carpeta raíz del ZIP (GitHub la llama owner-repo-sha/). */
    private function extract(string $zipFile, string $dest): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::RDONLY) !== true) {
            throw new UpdateException('El paquete no es un ZIP válido');
        }
        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new UpdateException('El paquete tiene demasiados ficheros');
            }
            $prefix = $zip->locateName('VERSION') !== false ? '' : null;
            if ($prefix === null) {
                $first = (string) $zip->getNameIndex(0);
                $prefix = str_contains($first, '/') ? substr($first, 0, strpos($first, '/') + 1) : '';
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                    throw new UpdateException('El paquete tiene más de una carpeta raíz');
                }
                $rel = substr($name, strlen($prefix));
                if ($rel === '' || str_ends_with($rel, '/')) {
                    continue;
                }
                if (str_contains($rel, '\\') || str_starts_with($rel, '/') || preg_match('~(^|/)\.\.(/|$)~', $rel) || str_contains($rel, "\0")) {
                    throw new UpdateException("Ruta no permitida en el paquete: $rel");
                }
                $target = $dest . '/' . $rel;
                self::ensureDir(dirname($target));
                $in = $zip->getStream($name);
                $out = $in ? fopen($target, 'wb') : false;
                if (!$in || !$out || stream_copy_to_stream($in, $out) === false) {
                    throw new UpdateException("No se puede extraer $rel");
                }
                fclose($in);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }

    private function place(string $source, string $target, string $backup): void
    {
        self::ensureDir(dirname($target));
        $saved = null;
        if (is_file($target)) {
            self::ensureDir(dirname($backup));
            if (!copy($target, $backup)) {
                throw new \RuntimeException("No se puede guardar el original de $target");
            }
            $saved = $backup;
        }
        $this->touched[] = [$target, $saved];
        $tmp = $target . '.qs-new';
        if (!copy($source, $tmp) || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("No se puede escribir $target (¿permisos?)");
        }
    }

    private function remove(string $target, string $backup): void
    {
        self::ensureDir(dirname($backup));
        if (!copy($target, $backup)) {
            throw new \RuntimeException("No se puede guardar el original de $target");
        }
        $this->touched[] = [$target, $backup];
        if (!unlink($target)) {
            throw new \RuntimeException("No se puede borrar $target");
        }
    }

    /** Deshace los cambios hechos hasta ahora; devuelve cuántos ficheros ha restaurado. */
    private function undo(): int
    {
        $n = 0;
        foreach (array_reverse($this->touched) as [$target, $saved]) {
            if ($saved === null) {
                @unlink($target);
            } else {
                @copy($saved, $target);
            }
            $n++;
        }
        $this->touched = [];
        return $n;
    }

    /** Que la siguiente petición compile los ficheros nuevos aunque opcache no revise fechas. */
    private static function flushOpcache(array $files): void
    {
        if (function_exists('opcache_invalidate')) {
            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    @opcache_invalidate($file, true);
                }
            }
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se puede crear $dir (¿permisos?)");
        }
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
