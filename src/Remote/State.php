<?php
declare(strict_types=1);

namespace Quasar\Remote;

/**
 * Estado del agente remoto en storage/remote/: modo mantenimiento, historial de actualizaciones…
 * Esta carpeta nunca la toca una actualización ni entra en las copias.
 */
final class State
{
    public static function dir(string $sub = ''): string
    {
        $dir = QS_ROOT . '/storage/remote' . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se puede crear $dir");
        }
        return $dir;
    }

    public static function readJson(string $file): ?array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) ? $data : null;
    }

    /** Escritura atómica (fichero temporal + rename). */
    public static function writeJson(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se puede crear $dir");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false
            || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("No se puede escribir $file");
        }
    }

    // ------------------------------------------------------------ mantenimiento

    private static function maintenanceFile(): string
    {
        return QS_ROOT . '/storage/remote/maintenance.json';
    }

    /** @return array{since:string,reason:string}|null */
    public static function maintenance(): ?array
    {
        $data = self::readJson(self::maintenanceFile());
        return $data === null ? null : ['since' => (string) ($data['since'] ?? ''), 'reason' => (string) ($data['reason'] ?? '')];
    }

    public static function setMaintenance(bool $on, string $reason = ''): void
    {
        if ($on) {
            self::writeJson(self::maintenanceFile(), ['since' => date(DATE_ATOM), 'reason' => $reason]);
        } elseif (is_file(self::maintenanceFile())) {
            unlink(self::maintenanceFile());
        }
    }

    // ------------------------------------------------------------ historial de actualizaciones

    /** @return list<array<string,mixed>> la más reciente primero */
    public static function updates(): array
    {
        return (array) (self::readJson(self::dir() . '/updates.json') ?? []);
    }

    public static function addUpdate(array $entry): void
    {
        $list = self::updates();
        array_unshift($list, $entry);
        self::writeJson(self::dir() . '/updates.json', array_slice($list, 0, 50));
    }
}
