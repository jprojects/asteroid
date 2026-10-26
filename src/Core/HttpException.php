<?php
declare(strict_types=1);

namespace Quasar\Core;

final class HttpException extends \RuntimeException
{
    /** @param string|null $langKey clave de idioma del mensaje (por defecto errors.{status}) */
    public function __construct(public readonly int $status, ?string $langKey = null)
    {
        parent::__construct($langKey ?? "errors.$status", $status);
    }
}
