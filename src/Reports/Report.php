<?php
declare(strict_types=1);

namespace Quasar\Reports;

/**
 * Un informe (fila de la tabla reports): su consulta, columnas con título y tipo, orden y límite.
 * Sabe mostrar cada valor en pantalla y prepararlo para Excel según el tipo de su columna.
 */
final class Report
{
    /**
     * @param list<array{name:string,label:string,type:string}> $columns
     * @param list<array{column:string,dir:string}> $sort
     */
    private function __construct(
        public readonly int $id,
        public readonly ?int $userId,
        public readonly ?string $name,
        public readonly string $prompt,
        public readonly string $title,
        public readonly string $explanation,
        public readonly string $query,
        public readonly array $columns,
        public readonly array $sort,
        public readonly ?int $limit,
    ) {
    }

    public static function fromRow(array $r): self
    {
        $columns = json_decode((string) $r['columns'], true);
        $sort = json_decode((string) ($r['sort'] ?? '[]'), true);
        return new self(
            (int) $r['id'],
            $r['user_id'] !== null ? (int) $r['user_id'] : null,
            $r['name'] !== null ? (string) $r['name'] : null,
            (string) $r['prompt'],
            (string) $r['title'],
            (string) ($r['explanation'] ?? ''),
            (string) $r['query'],
            is_array($columns) ? $columns : [],
            is_array($sort) ? $sort : [],
            $r['row_limit'] !== null ? (int) $r['row_limit'] : null,
        );
    }

    /**
     * Columnas definitivas: las del resultado real, con el título y tipo que propuso la IA
     * (o deducido del tipo de la BD si no lo dio).
     * @param list<array{name:string,native:string}> $actual
     * @param list<array{name?:mixed,label?:mixed,type?:mixed}> $proposed
     * @return list<array{name:string,label:string,type:string}>
     */
    public static function mergeColumns(array $actual, array $proposed): array
    {
        $byName = [];
        foreach ($proposed as $p) {
            if (is_array($p) && is_string($p['name'] ?? null)) {
                $byName[$p['name']] = $p;
            }
        }
        $out = [];
        foreach ($actual as $c) {
            $p = $byName[$c['name']] ?? [];
            $type = in_array($p['type'] ?? null, ReportAi::TYPES, true) ? $p['type'] : self::nativeType($c['native']);
            $label = is_string($p['label'] ?? null) && trim($p['label']) !== '' ? trim($p['label']) : $c['name'];
            $out[] = ['name' => $c['name'], 'label' => mb_substr($label, 0, 80), 'type' => $type];
        }
        return $out;
    }

    private static function nativeType(string $native): string
    {
        return match ($native) {
            'TINY', 'SHORT', 'LONG', 'LONGLONG', 'INT24', 'YEAR' => 'integer',
            'NEWDECIMAL', 'DECIMAL', 'DOUBLE', 'FLOAT' => 'decimal',
            'DATE' => 'date',
            'DATETIME', 'TIMESTAMP' => 'datetime',
            default => 'text',
        };
    }

    /** Posición (1…) de una columna por su nombre, o null. */
    public function position(string $name): ?int
    {
        foreach ($this->columns as $i => $c) {
            if ($c['name'] === $name) {
                return $i + 1;
            }
        }
        return null;
    }

    /** @return list<array{0:int,1:string}> orden propio del informe en posiciones */
    public function baseOrder(): array
    {
        $out = [];
        foreach ($this->sort as $s) {
            if (($pos = $this->position((string) ($s['column'] ?? ''))) !== null) {
                $out[] = [$pos, ($s['dir'] ?? '') === 'desc' ? 'desc' : 'asc'];
            }
        }
        return $out;
    }

    public function align(int $i): string
    {
        return match ($this->columns[$i]['type'] ?? 'text') {
            'integer', 'decimal', 'money', 'percent' => 'right',
            'boolean' => 'center',
            default => 'left',
        };
    }

    /**
     * Texto para la pantalla y el PDF.
     * @param \Closure(string):string $t textos de idioma (formatos y sí/no)
     */
    public function format(int $i, mixed $v, \Closure $t): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $num = static fn (float $n, int $d) => number_format($n, $d, $t('format.decimal_point'), $t('format.thousands_sep'));
        return match ($this->columns[$i]['type'] ?? 'text') {
            'integer' => is_numeric($v) ? $num((float) $v, 0) : (string) $v,
            'decimal', 'money' => is_numeric($v) ? $num((float) $v, 2) : (string) $v,
            'percent' => is_numeric($v) ? $num((float) $v, 2) . ' %' : (string) $v,
            'date', 'datetime' => ($d = self::date($v)) !== null
                ? $d->format($t($this->columns[$i]['type'] === 'date' || $d->format('H:i:s') === '00:00:00' ? 'format.date' : 'format.datetime'))
                : (string) $v,
            'boolean' => in_array((string) $v, ['1', 'true'], true) ? $t('yes') : (in_array((string) $v, ['0', 'false'], true) ? $t('no') : (string) $v),
            default => (string) $v,
        };
    }

    /** Valor para una celda de Excel (número o fecha reales). */
    public function exportValue(int $i, mixed $v, \Closure $t): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }
        return match ($this->columns[$i]['type'] ?? 'text') {
            'integer' => is_numeric($v) ? (int) $v : (string) $v,
            'decimal', 'money', 'percent' => is_numeric($v) ? (float) $v : (string) $v,
            'date', 'datetime' => self::date($v) ?? (string) $v,
            default => $this->format($i, $v, $t),
        };
    }

    /** @param \Closure(string):string $t */
    public function excelFormat(int $i, \Closure $t): ?string
    {
        return match ($this->columns[$i]['type'] ?? 'text') {
            'integer' => '#,##0',
            'decimal', 'money' => '#,##0.00',
            'percent' => '0.00" %"',
            'date' => $t('format.excel_date'),
            'datetime' => $t('format.excel_datetime'),
            default => null,
        };
    }

    private static function date(mixed $v): ?\DateTimeImmutable
    {
        if (!is_string($v)) {
            return null;
        }
        foreach (['!Y-m-d H:i:s', '!Y-m-d'] as $f) {
            $d = \DateTimeImmutable::createFromFormat($f, $v);
            if ($d !== false) {
                return $d;
            }
        }
        return null;
    }
}
