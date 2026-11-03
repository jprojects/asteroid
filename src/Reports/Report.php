<?php
declare(strict_types=1);

namespace Quasar\Reports;

/**
 * Un informe (fila de la tabla reports): su consulta, columnas con título y tipo, orden y límite.
 * Sabe mostrar cada valor en pantalla y prepararlo para Excel según el tipo de su columna.
 * Puede llevar un gráfico (tipo, columna de categorías y columnas numéricas) para la pestaña "Gráfico"
 * y el último comentario de la IA sobre su resultado.
 */
final class Report
{
    /**
     * @param list<array{name:string,label:string,type:string}> $columns
     * @param list<array{column:string,dir:string}> $sort
     * @param array{type:string,x:string,series:list<string>}|null $chart
     * @param array{summary:string,points:list<string>,caveats:?string}|null $comment
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
        public readonly ?array $chart,
        public readonly ?array $comment,
        public readonly ?string $commentAt,
    ) {
    }

    public static function fromRow(array $r): self
    {
        $columns = json_decode((string) $r['columns'], true);
        $sort = json_decode((string) ($r['sort'] ?? '[]'), true);
        $columns = is_array($columns) ? $columns : [];
        return new self(
            (int) $r['id'],
            $r['user_id'] !== null ? (int) $r['user_id'] : null,
            $r['name'] !== null ? (string) $r['name'] : null,
            (string) $r['prompt'],
            (string) $r['title'],
            (string) ($r['explanation'] ?? ''),
            (string) $r['query'],
            $columns,
            is_array($sort) ? $sort : [],
            $r['row_limit'] !== null ? (int) $r['row_limit'] : null,
            self::cleanChart(json_decode((string) ($r['chart'] ?? 'null'), true), $columns),
            ReportComment::clean(json_decode((string) ($r['comment'] ?? 'null'), true)),
            isset($r['comment_at']) ? (string) $r['comment_at'] : null,
        );
    }

    /**
     * Gráfico válido para estas columnas o null: tipo conocido, "x" que exista y de 1 a 8 series numéricas
     * distintas de "x" (el circular, solo una).
     * @param list<array{name:string,label:string,type:string}> $columns
     * @return array{type:string,x:string,series:list<string>}|null
     */
    public static function cleanChart(mixed $chart, array $columns): ?array
    {
        if (!is_array($chart) || !in_array($chart['type'] ?? null, ReportAi::CHARTS, true)) {
            return null;
        }
        $names = array_column($columns, 'name');
        $x = $chart['x'] ?? null;
        if (!is_string($x) || !in_array($x, $names, true)) {
            return null;
        }
        $numeric = array_column(array_filter($columns, static fn ($c) => self::isNumericType($c['type'])), 'name');
        $series = array_values(array_unique(array_filter(
            (array) ($chart['series'] ?? []),
            static fn ($s) => is_string($s) && $s !== $x && in_array($s, $numeric, true)
        )));
        $series = array_slice($series, 0, $chart['type'] === 'pie' ? 1 : 8);
        return $series === [] ? null : ['type' => $chart['type'], 'x' => $x, 'series' => $series];
    }

    public static function isNumericType(string $type): bool
    {
        return in_array($type, ['integer', 'decimal', 'money', 'percent'], true);
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
