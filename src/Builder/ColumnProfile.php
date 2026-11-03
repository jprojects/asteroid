<?php
declare(strict_types=1);

namespace Quasar\Builder;

/**
 * Qué parece cada columna de una hoja: tipo (integer, decimal, date, datetime, boolean, email, text,
 * longtext), longitud máxima, vacíos y, si tiene pocos valores distintos, cuáles (candidata a select).
 * Es lo que se envía a la IA junto con unas filas de muestra.
 */
final class ColumnProfile
{
    private const SAMPLE = 5000;
    private const MAX_DISTINCT = 15;

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @param bool $excel valores de un .xlsx (números con punto decimal)
     * @return list<array{header:string,type:string,max_length:int,decimals:int,empty:int,distinct:?list<string>,samples:list<string>}>
     */
    public static function profile(array $headers, array $rows, bool $excel = false): array
    {
        $out = [];
        $sample = array_slice($rows, 0, self::SAMPLE);
        foreach ($headers as $i => $header) {
            $values = array_map(static fn ($r) => (string) ($r[$i] ?? ''), $sample);
            $filled = array_values(array_filter($values, static fn ($v) => $v !== ''));
            $distinct = array_values(array_unique($filled));
            $out[] = [
                'header' => $header,
                'type' => self::type($filled, $excel),
                'max_length' => $filled ? max(array_map('mb_strlen', $filled)) : 0,
                'decimals' => $filled ? max(array_map(static fn ($v) => ValueParser::decimals($v, $excel), $filled)) : 0,
                'empty' => count($values) - count($filled),
                // Pocos valores que se repiten: posible lista de opciones
                'distinct' => count($distinct) <= self::MAX_DISTINCT && count($filled) >= 2 * count($distinct) ? $distinct : null,
                'samples' => array_slice($distinct, 0, 5),
            ];
        }
        return $out;
    }

    /** @param list<string> $values sin vacíos */
    private static function type(array $values, bool $excel): string
    {
        if ($values === []) {
            return 'text';
        }
        $all = static fn (callable $test) => array_reduce($values, static fn ($ok, $v) => $ok && $test($v), true);
        $distinct = array_unique(array_map('mb_strtolower', $values));
        if (count($distinct) <= 2 && $all(static fn ($v) => (ValueParser::bool($v) !== null && !is_numeric($v)) || in_array($v, ['0', '1'], true))) {
            return 'boolean';
        }
        // Enteros sin ceros a la izquierda (un código postal o un teléfono "0034…" es texto)
        if ($all(static fn ($v) => preg_match('/^-?(0|[1-9]\d{0,17})$/', $v))) {
            return 'integer';
        }
        if ($all(static fn ($v) => ValueParser::number($v, $excel) !== null && !preg_match('/^0\d/', $v))) {
            return 'decimal';
        }
        if ($all(static fn ($v) => ValueParser::date($v, true) !== null)) {
            return array_filter($values, [ValueParser::class, 'hasTime']) ? 'datetime' : 'date';
        }
        if ($all(static fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL) !== false)) {
            return 'email';
        }
        $long = array_filter($values, static fn ($v) => mb_strlen($v) > 255 || str_contains($v, "\n"));
        return $long ? 'longtext' : 'text';
    }
}
