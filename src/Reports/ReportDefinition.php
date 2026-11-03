<?php
declare(strict_types=1);

namespace Quasar\Reports;

/**
 * Definición de un informe PDF (módulo con "type": "report"): consulta con parámetros, consultas de un
 * solo valor ("values") y maquetación ("pdf"). Se valida al cargar el módulo; cualquier error del JSON
 * es un RuntimeException("Módulo '…': …").
 *
 * Los parámetros se escriben en el SQL como :nombre y se pasan a MySQL como parámetros de la consulta
 * preparada (nunca se pegan en el texto). :user_id es siempre el usuario conectado.
 */
final class ReportDefinition
{
    public const TOTALS = ['sum', 'avg', 'min', 'max', 'count'];
    public const ALIGNS = ['left', 'center', 'right'];
    /** Parámetros que pone Quasar (no se declaran en "params") */
    public const BUILTIN = ['user_id'];

    /** Consulta principal con ? en lugar de :nombre */
    public readonly string $sql;
    /** @var list<string> parámetro de cada ?, en orden */
    public readonly array $bindings;
    /** @var array<string,array{sql:string,bindings:list<string>}> consultas de un valor */
    public readonly array $values;
    /**
     * @var array{
     *   orientation:string, fontSize:float, subtitle:?string, empty:?string, totalLabel:string,
     *   info:list<array{label:string,value:string}>, summary:list<array{label:string,value:string}>,
     *   columns:list<array{field:string,label:string,width:float,align:?string,format:string,total:?string,wrap:bool,options:?array<string,string>}>,
     *   groupBy:?array{field:string,label:string,subtotal:bool,newPage:bool}, totals:bool
     * }
     */
    public readonly array $pdf;

    /** @param list<string> $params nombres de los parámetros declarados */
    public function __construct(private readonly string $module, array $def, array $params)
    {
        $known = array_merge($params, self::BUILTIN);
        foreach ($params as $p) {
            if (in_array($p, self::BUILTIN, true)) {
                throw $this->error("el parámetro '$p' lo pone Quasar: no se declara en \"params\"");
            }
        }
        [$this->sql, $this->bindings] = $this->parseSql($def['query'] ?? null, 'query', $known);

        $values = [];
        foreach ((array) ($def['values'] ?? []) as $name => $sql) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', (string) $name)) {
                throw $this->error("nombre no válido en \"values\": '$name' (minúsculas, números y _)");
            }
            [$s, $b] = $this->parseSql($sql, "values.$name", $known);
            $values[(string) $name] = ['sql' => $s, 'bindings' => $b];
        }
        $this->values = $values;
        $this->pdf = $this->parsePdf((array) ($def['pdf'] ?? []));
    }

    private function error(string $message): \RuntimeException
    {
        return new \RuntimeException("Módulo '{$this->module}': $message");
    }

    /**
     * Comprueba la consulta y cambia cada :nombre por ? (fuera de textos y `identificadores`).
     * @param list<string> $known
     * @return array{0:string,1:list<string>}
     */
    private function parseSql(mixed $sql, string $where, array $known): array
    {
        if (!is_string($sql) || trim($sql) === '') {
            throw $this->error("\"$where\" debe ser una consulta SELECT");
        }
        $sql = rtrim(trim($sql), "; \n\r\t");
        $bindings = [];
        $out = preg_replace_callback(
            '/\'(?:[^\'\\\\]|\\\\.|\'\')*\'|"(?:[^"\\\\]|\\\\.|"")*"|`(?:[^`]|``)*`|(?<![:\w]):([A-Za-z_][A-Za-z0-9_]*)|;/s',
            function (array $m) use (&$bindings, $known, $where): string {
                if ($m[0] === ';') {
                    throw $this->error("\"$where\": solo se admite una sentencia (sin ;)");
                }
                if (!isset($m[1]) || $m[1] === '') {
                    return $m[0];
                }
                if (!in_array($m[1], $known, true)) {
                    throw $this->error("\"$where\" usa :{$m[1]}, que no está en \"params\" (ni es " . implode(', ', array_map(static fn ($b) => ":$b", self::BUILTIN)) . ')');
                }
                $bindings[] = $m[1];
                return '?';
            },
            $sql
        );
        if (!preg_match('/^\(?\s*(select|with)\b/i', (string) $out)) {
            throw $this->error("\"$where\" debe empezar por SELECT o WITH");
        }
        return [(string) $out, $bindings];
    }

    private function parsePdf(array $pdf): array
    {
        $orientation = (string) ($pdf['orientation'] ?? 'portrait');
        if (!in_array($orientation, ['portrait', 'landscape'], true)) {
            throw $this->error('"pdf.orientation" debe ser portrait o landscape');
        }
        $fontSize = (float) ($pdf['fontSize'] ?? 8);
        if ($fontSize < 6 || $fontSize > 12) {
            throw $this->error('"pdf.fontSize" debe estar entre 6 y 12');
        }

        $columns = [];
        foreach ((array) ($pdf['columns'] ?? []) as $i => $c) {
            $c = is_string($c) ? ['field' => $c] : $c;
            if (!is_array($c) || !is_string($c['field'] ?? null) || $c['field'] === '') {
                throw $this->error("la columna $i de \"pdf.columns\" necesita \"field\" (nombre de una columna de la consulta)");
            }
            $field = $c['field'];
            $width = $c['width'] ?? 1;
            if (!is_numeric($width) || $width <= 0) {
                throw $this->error("\"width\" de la columna '$field' debe ser un número mayor que 0 (ancho relativo)");
            }
            $align = $c['align'] ?? null;
            if ($align !== null && !in_array($align, self::ALIGNS, true)) {
                throw $this->error("\"align\" de la columna '$field' debe ser " . implode(', ', self::ALIGNS));
            }
            $total = $c['total'] ?? null;
            if ($total !== null && !in_array($total, self::TOTALS, true)) {
                throw $this->error("\"total\" de la columna '$field' debe ser " . implode(', ', self::TOTALS));
            }
            $format = $this->format($c['format'] ?? '', "la columna '$field'");
            $options = $c['options'] ?? null;
            if ($options !== null && (!is_array($options) || array_filter($options, static fn ($v) => !is_string($v)))) {
                throw $this->error("\"options\" de la columna '$field' debe ser {\"valor\": \"texto o clave de idioma\"}");
            }
            $columns[] = [
                'field' => $field,
                'label' => (string) ($c['label'] ?? $field),
                'width' => (float) $width,
                'align' => $align,
                'format' => $format,
                'total' => $total,
                'wrap' => !empty($c['wrap']),
                'options' => $options,
            ];
        }
        if ($columns === []) {
            throw $this->error('"pdf.columns" necesita al menos una columna');
        }

        $group = null;
        if (isset($pdf['groupBy'])) {
            $g = is_string($pdf['groupBy']) ? ['field' => $pdf['groupBy']] : $pdf['groupBy'];
            if (!is_array($g) || !is_string($g['field'] ?? null) || $g['field'] === '') {
                throw $this->error('"pdf.groupBy" necesita "field" (columna de la consulta por la que agrupar)');
            }
            $group = [
                'field' => $g['field'],
                'label' => (string) ($g['label'] ?? '{' . $g['field'] . '}'),
                'subtotal' => ($g['subtotal'] ?? true) !== false,
                'newPage' => !empty($g['newPage']),
            ];
        }

        return [
            'orientation' => $orientation,
            'fontSize' => $fontSize,
            'subtitle' => isset($pdf['subtitle']) ? (string) $pdf['subtitle'] : null,
            'empty' => isset($pdf['empty']) ? (string) $pdf['empty'] : null,
            'totalLabel' => (string) ($pdf['totalLabel'] ?? 'report.total'),
            'info' => $this->pairs($pdf['info'] ?? [], 'pdf.info'),
            'summary' => $this->pairs($pdf['summary'] ?? [], 'pdf.summary'),
            'columns' => $columns,
            'groupBy' => $group,
            'totals' => ($pdf['totals'] ?? true) !== false,
        ];
    }

    /** @return list<array{label:string,value:string}> */
    private function pairs(mixed $list, string $where): array
    {
        if (!is_array($list)) {
            throw $this->error("\"$where\" debe ser una lista de {\"label\": …, \"value\": …}");
        }
        $out = [];
        foreach ($list as $i => $p) {
            if (!is_array($p) || !is_string($p['value'] ?? null)) {
                throw $this->error("el elemento $i de \"$where\" necesita \"value\" (texto con {campos} o {parámetros})");
            }
            $out[] = ['label' => (string) ($p['label'] ?? ''), 'value' => $p['value']];
        }
        return $out;
    }

    private function format(mixed $format, string $where): string
    {
        if (!is_string($format)) {
            throw $this->error("\"format\" de $where debe ser un texto");
        }
        if (!ReportFormat::isValid($format)) {
            throw $this->error("\"format\" de $where no es válido: '$format' (" . ReportFormat::HELP . ')');
        }
        return $format;
    }
}
