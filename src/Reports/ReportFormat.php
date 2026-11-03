<?php
declare(strict_types=1);

namespace Quasar\Reports;

/**
 * Formatos de los valores de un informe PDF ("format" de las columnas y {campo|formato} en los textos):
 *   (vacío)      el valor tal cual
 *   date         fecha (format.date del idioma);  datetime  fecha y hora
 *   int          número sin decimales;            number    2 decimales;  number:3  3 decimales
 *   money        2 decimales y " €";              percent   2 decimales y " %"  (percent:0, money:0…)
 *   yesno        Sí / No
 *   %…           cualquier patrón de sprintf (p. ej. "%05d"), sin separadores del idioma
 * Los números usan los separadores del idioma (format.decimal_point, format.thousands_sep).
 */
final class ReportFormat
{
    public const HELP = 'date, datetime, int, number, number:N, money, percent, yesno o un patrón de sprintf como %05d';

    public static function isValid(string $format): bool
    {
        if ($format === '' || preg_match('/^(date|datetime|int|yesno|(number|money|percent)(:[0-6])?)$/', $format)) {
            return true;
        }
        if (!str_contains($format, '%')) {
            return false;
        }
        try {
            sprintf($format, 1);
            return true;
        } catch (\ValueError|\ArgumentCountError) {
            return false;
        }
    }

    public static function isNumeric(string $format): bool
    {
        return (bool) preg_match('/^(int|number|money|percent)/', $format);
    }

    /** @param \Closure(string):string $t textos comunes (format.date, yes, no…) */
    public static function apply(string $format, mixed $value, \Closure $t): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($format === '') {
            return is_scalar($value) ? (string) $value : '';
        }
        if ($format === 'date' || $format === 'datetime') {
            try {
                $date = new \DateTimeImmutable((string) $value);
            } catch (\Exception) {
                return (string) $value;
            }
            return $date->format($t($format === 'date' ? 'format.date' : 'format.datetime'));
        }
        if ($format === 'yesno') {
            return $t(in_array($value, [0, '0', false, 'false'], true) ? 'no' : 'yes');
        }
        if (preg_match('/^(int|number|money|percent)(?::(\d))?$/', $format, $m)) {
            if (!is_numeric($value)) {
                return (string) $value;
            }
            $decimals = isset($m[2]) ? (int) $m[2] : ($m[1] === 'int' ? 0 : 2);
            $text = number_format((float) $value, $decimals, $t('format.decimal_point'), $t('format.thousands_sep'));
            return $text . match ($m[1]) { 'money' => ' €', 'percent' => ' %', default => '' };
        }
        return sprintf($format, is_numeric($value) ? $value + 0 : $value);
    }
}
