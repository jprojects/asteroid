<?php
declare(strict_types=1);

namespace Quasar\Builder;

/**
 * Interpreta los valores de una hoja de cálculo: números en formato español o inglés, fechas habituales
 * y sí/no. Lo usan el análisis de columnas (qué tipo parece cada una) y la importación de las filas.
 */
final class ValueParser
{
    private const TRUE = ['1', 'si', 'sí', 's', 'yes', 'y', 'true', 'x', 'verdadero', 'cert', 'vertader'];
    private const FALSE = ['0', 'no', 'n', 'false', 'falso', 'fals', ''];

    /**
     * "1.234,56", "1,234.56", "1234.5", "12 %", "1.234 €" → float; null si no es un número.
     * $excel: el valor viene de una celda numérica de .xlsx, que siempre usa punto decimal ("1.234" es 1,234).
     */
    public static function number(string $v, bool $excel = false): ?float
    {
        if ($excel && is_numeric($v)) {
            return (float) $v;
        }
        $v = trim(str_replace(["\u{00A0}", ' ', '€', '$', '%'], '', $v));
        if ($v === '' || !preg_match('/^[-+]?[\d.,]+$/', $v) || !preg_match('/\d/', $v)) {
            return null;
        }
        $dot = strrpos($v, '.');
        $comma = strrpos($v, ',');
        if ($dot !== false && $comma !== false) {
            // El último separador es el decimal
            $v = $comma > $dot ? str_replace(',', '.', str_replace('.', '', $v)) : str_replace(',', '', $v);
        } elseif ($comma !== false) {
            // Solo comas: decimal si hay una y no son grupos de miles exactos ("1,234,567")
            $v = substr_count($v, ',') === 1 && !preg_match('/^\d{1,3}(,\d{3})+$/', $v) ? str_replace(',', '.', $v) : str_replace(',', '', $v);
        } elseif ($dot !== false && substr_count($v, '.') > 1) {
            $v = str_replace('.', '', $v); // "1.234.567"
        } elseif ($dot !== false && preg_match('/^\d{1,3}\.\d{3}$/', $v)) {
            $v = str_replace('.', '', $v); // "1.234" en una hoja española son mil doscientos
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /** Decimales de un número tal como está escrito */
    public static function decimals(string $v, bool $excel = false): int
    {
        $n = self::number($v, $excel);
        if ($n === null || floor($n) === $n) {
            return 0;
        }
        return min(6, strlen(rtrim(substr(sprintf('%.6F', $n - floor($n)), 2), '0')));
    }

    /** "2024-03-15", "15/03/2024", "15-03-24", "15.03.2024" (+ hora) → "Y-m-d[ H:i:s]"; null si no es una fecha */
    public static function date(string $v, bool $withTime = false): ?string
    {
        $v = trim($v);
        if (!preg_match('~^(\d{1,4})[-/.](\d{1,2})[-/.](\d{1,4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$~', $v, $m)) {
            return null;
        }
        [$a, $b, $c] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$y, $mo, $d] = strlen($m[1]) === 4 ? [$a, $b, $c] : [$c < 100 ? 2000 + $c : $c, $b, $a];
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $y, $mo, $d);
        if (!$withTime) {
            return $date;
        }
        return sprintf('%s %02d:%02d:%02d', $date, (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
    }

    public static function hasTime(string $v): bool
    {
        return (bool) preg_match('/\d{1,2}:\d{2}/', $v) && !preg_match('/\s00:00(:00)?$/', $v);
    }

    /** Sí/no → 1/0; null si no lo parece */
    public static function bool(string $v): ?int
    {
        $v = mb_strtolower(trim($v));
        return in_array($v, self::TRUE, true) ? 1 : (in_array($v, self::FALSE, true) ? 0 : null);
    }

    /** Texto sin acentos y en minúsculas, para comparar etiquetas */
    public static function fold(string $v): string
    {
        $t = \Normalizer::normalize(mb_strtolower(trim($v)), \Normalizer::FORM_D);
        return (string) preg_replace('/\p{Mn}/u', '', (string) $t);
    }
}
