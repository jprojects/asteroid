<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Fecha y hora (columna DATETIME). Formato de visualización: format.datetime
 */
class DateTimeField extends DateField
{
    protected string $inputType = 'datetime-local';
    protected array $inputFormats = ['Y-m-d\TH:i', 'Y-m-d\TH:i:s'];
    protected string $inputFormat = 'Y-m-d\TH:i';
    protected string $dbFormat = 'Y-m-d H:i:s';
    protected string $displayKey = 'format.datetime';
}
