<?php
declare(strict_types=1);

namespace Quasar\Google;

final class GoogleException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
