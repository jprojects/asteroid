<?php
declare(strict_types=1);

namespace Quasar\Ai;

/** Fallo al hablar con el proveedor. $usage lleva el consumo si la llamada llegó a facturarse. */
final class LlmException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?LlmResponse $usage = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
