<?php
declare(strict_types=1);

namespace Quasar\Core;

/** Error de subida mostrable al usuario (clave de idioma + variables). */
final class MediaException extends \RuntimeException
{
    public function __construct(public readonly string $langKey, public readonly array $vars = [])
    {
        parent::__construct($langKey);
    }
}
