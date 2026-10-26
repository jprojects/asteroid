<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Consulta de un módulo a partir de los parámetros de la petición
 * (q, f[...], sort, dir, page, size). Se usa para el listado paginado y
 * para la exportación, de modo que ambos aplican exactamente los mismos
 * filtros y orden. Todo lo que llega del cliente se contrasta con el JSON.
 */
final class DataQuery
{
    public function __construct(
        private readonly Module $module,
        private readonly Database $db,
    ) {
    }

    /** Página de resultados formateados para la tabla (respuesta de r=data). */
    public function run(array $params): array
    {
        $m = $this->module;
        [$whereSql, $bind] = $this->conditions($params);
        [$sort, $dir, $orderSql] = $this->ordering($params);

        $size = (int) ($params['size'] ?? 0);
        if (!in_array($size, $m->pageSizes(), true)) {
            $size = $m->pageSize();
        }
        $total = $this->count($whereSql, $bind);
        $pages = max(1, (int) ceil($total / $size));
        $page = min(max(1, (int) ($params['page'] ?? 1)), $pages);

        $columns = $m->listColumns();
        $expand = $m->expandFields();
        // Botones de fila con "showon": se calcula en qué filas se ven con los campos de la condición
        $conditional = array_filter($m->rowActions(), static fn ($a) => $a['showon'] !== null);
        $showOnFields = [];
        foreach ($conditional as $a) {
            foreach (ShowOn::fields($a['showon']) as $ref) {
                $showOnFields[$ref] = ['field' => $m->field($ref)];
            }
        }
        $queried = array_merge($columns, $expand, array_values($showOnFields)); // todos llevan 'field'
        $sql = $this->select($queried) . $whereSql . $orderSql
            . sprintf(' LIMIT %d OFFSET %d', $size, ($page - 1) * $size);

        $rows = [];
        foreach ($this->db->fetchAll($sql, $bind) as $row) {
            $row = $this->resolveComputed($queried, $row);
            $cells = [];
            $badges = [];
            $tags = [];
            foreach ($columns as $c) {
                $f = $c['field'];
                $value = $row[$f->name] ?? null;
                // En columnas "wrap" el texto va entero; en "ellipsis" lo recorta el navegador
                $cells[$f->name] = $c['overflow'] === 'wrap' ? $f->fullText($value) : $f->format($value);
                if (($list = $f->cellTags($value)) !== null) {
                    $tags[$f->name] = $list;
                } elseif (($tone = $f->badgeColor($value)) !== null) {
                    $badges[$f->name] = $tone;
                }
            }
            // Detalle desplegable: solo los campos con contenido (sin ninguno, la fila no se despliega)
            $details = [];
            foreach ($expand as $e) {
                $value = $row[$e['field']->name] ?? null;
                $text = $e['field']->fullText($value);
                if (trim($text) === '') {
                    continue;
                }
                $details[$e['field']->name] = ['text' => $text]
                    + (($list = $e['field']->cellTags($value)) !== null ? ['tags' => $list] : [])
                    + (($tone = $e['field']->badgeColor($value)) !== null ? ['tone' => $tone] : []);
            }
            $hide = [];
            foreach ($conditional as $i => $a) {
                $show = ShowOn::evaluate($a['showon'], static fn (string $f) => isset($showOnFields[$f])
                    ? $showOnFields[$f]['field']->fromDb($row[$f] ?? null) : null);
                if (!$show) {
                    $hide[] = $i; // posición en rowActions()
                }
            }
            $rows[] = ['id' => (string) $row['__pk'], 'cells' => $cells]
                + ($badges ? ['badges' => $badges] : [])
                + ($tags ? ['tags' => $tags] : [])
                + ($details ? ['details' => $details] : [])
                + ($hide ? ['hide' => $hide] : []);
        }

        return [
            'rows' => $rows,
            'totals' => $this->totals($whereSql, $bind),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'size' => $size,
            'sort' => $sort,
            'dir' => $dir,
        ];
    }

    /**
     * Todas las filas (hasta $limit) con los valores de BD sin formatear.
     *
     * @param list<array{field:\Quasar\Fields\Field}> $columns
     * @return array{rows:list<array<string,mixed>>,total:int,truncated:bool}
     */
    public function export(array $params, array $columns, int $limit): array
    {
        [$whereSql, $bind] = $this->conditions($params);
        [, , $orderSql] = $this->ordering($params);
        $total = $this->count($whereSql, $bind);
        $sql = $this->select($columns) . $whereSql . $orderSql . sprintf(' LIMIT %d', max(1, $limit));
        return [
            'rows' => array_map(fn (array $row) => $this->resolveComputed($columns, $row), $this->db->fetchAll($sql, $bind)),
            'total' => $total,
            'truncated' => $total > $limit,
        ];
    }

    /**
     * Filtros activos en formato legible: [["Estado", "Activo"], ["Buscar", "acme"]].
     * @return list<array{0:string,1:string}>
     */
    public function describe(array $params): array
    {
        $out = [];
        $q = is_string($params['q'] ?? null) ? trim($params['q']) : '';
        if ($q !== '' && $this->module->searchFields()) {
            $out[] = [$this->module->t('export.search'), $q];
        }
        $values = is_array($params['f'] ?? null) ? $params['f'] : [];
        foreach ($this->module->filters() as $flt) {
            $text = $flt['field']->describeFilter($values[$flt['field']->name] ?? null, $flt['operator']);
            if ($text !== null) {
                $out[] = [$flt['label'], $text];
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- piezas SQL

    /** @return array{0:string,1:list<mixed>} cláusula WHERE y parámetros */
    private function conditions(array $params): array
    {
        $where = [];
        $bind = [];

        // Registros que puede ver el usuario ("where" y "scope" del módulo)
        if (($scope = $this->module->rowCondition()) !== null) {
            $where[] = '(' . $scope[0] . ')';
            array_push($bind, ...$scope[1]);
        }

        $filterValues = is_array($params['f'] ?? null) ? $params['f'] : [];
        foreach ($this->module->filters() as $flt) {
            $clause = $flt['field']->filterClause($filterValues[$flt['field']->name] ?? null, $flt['operator']);
            if ($clause !== null) {
                $where[] = '(' . $clause[0] . ')';
                array_push($bind, ...$clause[1]);
            }
        }

        $q = is_string($params['q'] ?? null) ? trim($params['q']) : '';
        $searchFields = $this->module->searchFields();
        if ($q !== '' && $searchFields) {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $or = [];
            foreach ($searchFields as $f) {
                $or[] = $f->sqlExpr() . ' LIKE ?';
                $bind[] = $like;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }

        return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $bind];
    }

    /** @return array{0:string,1:string,2:string} campo, dirección y cláusula ORDER BY */
    private function ordering(array $params): array
    {
        $sortable = [];
        foreach ($this->module->listColumns() as $c) {
            if ($c['sortable']) {
                $sortable[$c['field']->name] = (string) $c['field']->sqlExpr();
            }
        }
        $default = $this->module->defaultSort();
        $sort = is_string($params['sort'] ?? null) && isset($sortable[$params['sort']]) ? $params['sort'] : $default['field'];
        $dir = isset($params['dir']) && is_string($params['dir'])
            ? (strtolower($params['dir']) === 'desc' ? 'desc' : 'asc')
            : $default['dir'];

        $pk = Database::ident($this->module->primaryKey());
        $orderSql = $sort !== ''
            ? sprintf(' ORDER BY %s %s, %s %s', $sortable[$sort], strtoupper($dir), $pk, strtoupper($dir))
            : " ORDER BY $pk";
        return [$sort, $dir, $orderSql];
    }

    /**
     * Sumas de "list.totals" con los mismos filtros, formateadas como su campo.
     * @return array<string,string>|null
     */
    private function totals(string $whereSql, array $bind): ?array
    {
        $fields = $this->module->totalFields();
        if (!$fields) {
            return null;
        }
        $select = array_map(static fn ($f) => 'SUM(' . $f->sqlExpr() . ') AS ' . Database::ident($f->name), $fields);
        $row = $this->db->fetch('SELECT ' . implode(', ', $select) . ' FROM ' . Database::ident($this->module->table()) . $whereSql, $bind) ?? [];
        $out = [];
        foreach ($fields as $f) {
            $out[$f->name] = $f->format($row[$f->name] ?? 0);
        }
        return $out;
    }

    private function count(string $whereSql, array $bind): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ' . Database::ident($this->module->table()) . $whereSql,
            $bind
        );
    }

    /** @param list<array{field:\Quasar\Fields\Field}> $columns */
    private function select(array $columns): string
    {
        $table = Database::ident($this->module->table());
        $select = [];
        // Los cálculos en PHP reciben la fila completa
        foreach ($columns as $c) {
            if ($c['field']->computeFunction() !== null) {
                $select[] = "$table.*";
                break;
            }
        }
        $select[] = Database::ident($this->module->primaryKey()) . ' AS `__pk`';
        foreach ($columns as $c) {
            $expr = $c['field']->sqlExpr();
            if ($expr !== null) {
                $select[] = $expr . ' AS ' . Database::ident($c['field']->name);
            }
        }
        return 'SELECT ' . implode(', ', array_unique($select)) . ' FROM ' . $table;
    }

    /** Añade a la fila los valores de los campos calculados en PHP. */
    private function resolveComputed(array $columns, array $row): array
    {
        foreach ($columns as $c) {
            if ($c['field']->computeFunction() !== null) {
                $row[$c['field']->name] = $this->module->compute($c['field'], $row);
            }
        }
        return $row;
    }
}
