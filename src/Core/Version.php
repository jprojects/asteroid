<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Versión del núcleo. La única fuente es el fichero VERSION de la raíz
 * (versionado semántico: MAYOR.MENOR.PARCHE); cada release se etiqueta en git como vX.Y.Z.
 */
final class Version
{
    private static ?string $current = null;

    public static function current(): string
    {
        if (self::$current === null) {
            $raw = is_file(QS_ROOT . '/VERSION') ? trim((string) file_get_contents(QS_ROOT . '/VERSION')) : '';
            self::$current = preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $raw) ? $raw : '0.0.0';
        }
        return self::$current;
    }
}
