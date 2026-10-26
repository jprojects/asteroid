<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Resultado de una acción de módulo (ver Action):
 *
 *   ActionResult::success('Correo enviado')                     aviso; la tabla se recarga
 *   ActionResult::error('El cliente no tiene email')            ventana de error; no cambia nada
 *   ActionResult::redirect($a->editUrl($id), 'Duplicado')       va a esa página (con aviso opcional)
 *   ActionResult::download('/ruta/informe.pdf', 'informe.pdf')  descarga un fichero del disco
 *   ActionResult::downloadData($csv, 'datos.csv', 'text/csv')   descarga un contenido generado
 *
 * Las descargas solo funcionan en botones con "download": true en el JSON.
 */
final class ActionResult
{
    private function __construct(
        public readonly string $kind,        // success | error | redirect | download
        public readonly ?string $message = null,
        public readonly ?string $url = null,
        public readonly ?string $file = null,
        public readonly ?string $data = null,
        public readonly ?string $filename = null,
        public readonly string $mime = 'application/octet-stream',
    ) {
    }

    public static function success(?string $message = null): self
    {
        return new self('success', $message);
    }

    public static function error(string $message): self
    {
        return new self('error', $message);
    }

    public static function redirect(string $url, ?string $message = null): self
    {
        return new self('redirect', $message, $url);
    }

    public static function download(string $file, ?string $filename = null, ?string $mime = null): self
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException("ActionResult::download: no se puede leer '$file'");
        }
        return new self('download', null, null, $file, null, $filename ?? basename($file),
            $mime ?? (mime_content_type($file) ?: 'application/octet-stream'));
    }

    public static function downloadData(string $data, string $filename, string $mime = 'application/octet-stream'): self
    {
        return new self('download', null, null, null, $data, $filename, $mime);
    }

    /** Lo que devuelve el método de la acción: ActionResult, un texto (éxito) o null. */
    public static function from(mixed $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            is_string($value) => self::success($value),
            $value === null => self::success(),
            default => throw new \RuntimeException('Una acción debe devolver ActionResult, un texto o null'),
        };
    }
}
