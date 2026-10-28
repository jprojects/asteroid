<?php
declare(strict_types=1);

namespace Quasar\Fields;

use Quasar\Core\Database;

/**
 * Opciones estáticas:
 *   "options": {"valor": "clave.de.idioma", ...}
 * u opciones desde otra tabla:
 *   "source": {"table": "countries", "value": "id", "label": "name", "order": "name"}
 *   "source": {…, "where": "active = 1"}      solo las filas que cumplen la condición (SQL fijo del JSON)
 *   "source": {"module": "projects", …}        la tabla de ese módulo y solo los registros que el usuario
 *                                              ve en él ("scope"/"where"): no puede elegir otros
 *   "source": {…, "order": "id", "dir": "desc"} orden de las opciones
 *
 * Select dependiente de otro campo del formulario o de los filtros (siempre con búsqueda en el servidor):
 *   "dependsOn": {"field": "project_id", "column": "project_id"}   solo las filas de la tabla de origen cuya
 *                columna "column" coincide con el valor elegido en "field" (sin valor, todas)
 *   "dependsOn": {…, "form": false}       solo en los filtros del listado ("filters": false, solo en la ficha)
 * En la ficha también se valida al guardar.
 *
 * Buscador (como select2, sin librerías):
 *   "search": true       buscar entre las opciones de la página (por defecto si hay más de 10)
 *   "search": false      select normal
 *   "search": "remote"   buscar en el servidor mientras se escribe (para tablas grandes con "source":
 *                        la página no carga todas las opciones)
 *
 * Selección múltiple (se guarda como lista JSON en una columna JSON/TEXT):
 *   "multiple": true, "maxItems": 5
 * En la tabla se muestran las etiquetas separadas por comas (o una etiqueta de color por valor si
 * hay "colors"); el filtro encuentra los registros que contienen el valor elegido.
 */
class SelectField extends Field
{
    private const AUTO_SEARCH_FROM = 10;
    private const REMOTE_LIMIT = 30;

    /** @var array<string,string>|null */
    private ?array $options = null;
    /** @var array<string,?string> etiquetas ya resueltas en modo remoto */
    private array $labels = [];

    public function defaultOperator(): string
    {
        return '=';
    }

    public function isMultiple(): bool
    {
        return !empty($this->def['multiple']);
    }

    public function maxItems(): ?int
    {
        return isset($this->def['maxItems']) ? max(1, (int) $this->def['maxItems']) : null;
    }

    public function isSortable(): bool
    {
        return !$this->isMultiple() && parent::isSortable();
    }

    public function defaultValue(): mixed
    {
        return $this->isMultiple() ? $this->toList($this->def['default'] ?? []) : parent::defaultValue();
    }

    protected function isEmpty(mixed $value): bool
    {
        return $this->isMultiple() ? $this->toList($value) === [] : parent::isEmpty($value);
    }

    /**
     * Lista de valores (textos únicos, sin vacíos) a partir de un array, del JSON guardado o,
     * por compatibilidad con datos migrados, de un texto separado por comas ("1,4,7").
     * @return list<string>
     */
    private function toList(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }
        $out = [];
        foreach (is_array($value) ? $value : [$value] as $v) {
            if (is_scalar($v) && ($v = trim((string) $v)) !== '') {
                $out[$v] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    /** off | local | remote */
    public function searchMode(): string
    {
        if ($this->dependency() !== null) {
            return 'remote'; // las opciones dependen de otro campo: se piden al servidor
        }
        $search = $this->def['search'] ?? 'auto';
        if ($search === 'remote') {
            return $this->source() !== null ? 'remote' : 'local';
        }
        if ($search === true) {
            return 'local';
        }
        if ($search === false) {
            return 'off';
        }
        return count($this->options()) > self::AUTO_SEARCH_FROM ? 'local' : 'off';
    }

    /**
     * "dependsOn" en la ficha ('form'), en los filtros ('filters') o en cualquiera (null).
     * @return array{field:string,column:string}|null
     */
    public function dependency(?string $place = null): ?array
    {
        $d = $this->def['dependsOn'] ?? null;
        if (!is_array($d) || $this->source() === null) {
            return null;
        }
        if ($place !== null && ($d[$place] ?? true) === false) {
            return null;
        }
        $column = (string) ($d['column'] ?? '');
        Database::ident($column); // valida el nombre
        return ['field' => (string) ($d['field'] ?? ''), 'column' => $column];
    }

    /** Si $value es una opción válida cuando el campo del que depende vale $parent. */
    public function belongsTo(string $value, string $parent): bool
    {
        $s = $this->source();
        [$filter, $bind] = $this->sourceFilter($s, [$parent]);
        return $this->ctx->db->fetchValue(sprintf(
            'SELECT 1 FROM %s WHERE %s = ?%s LIMIT 1',
            Database::ident($s['table']),
            Database::ident($s['value']),
            $filter
        ), [$value, ...$bind]) !== null;
    }

    /** @return array{table:string,value:string,label:string,order:string,dir:string,module:?string,where:?string}|null */
    private function source(): ?array
    {
        $s = $this->def['source'] ?? null;
        if (!is_array($s)) {
            return null;
        }
        $label = (string) ($s['label'] ?? 'name');
        $module = isset($s['module']) ? (string) $s['module'] : null;
        $table = $s['table'] ?? ($module !== null ? $this->ctx->module($module)?->table() : null);
        if ($table === null) {
            throw new \RuntimeException("Campo '{$this->name}': \"source\" necesita \"table\" o un \"module\" que exista");
        }
        return [
            'table' => (string) $table,
            'value' => (string) ($s['value'] ?? 'id'),
            'label' => $label,
            'order' => (string) ($s['order'] ?? $label),
            'dir' => strtolower((string) ($s['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC',
            'module' => $module,
            'where' => isset($s['where']) ? (string) $s['where'] : null,
        ];
    }

    /**
     * Condiciones de "source.where" y de los registros que el usuario ve en "source.module".
     * @return array{0:string,1:list<mixed>} " AND …" (o "") y sus parámetros
     */
    private function sourceFilter(array $s, array $parent = []): array
    {
        $parts = [];
        $bind = [];
        $parent = array_values(array_filter(array_map('strval', $parent), static fn ($v) => $v !== ''));
        if ($parent && ($dep = $this->dependency()) !== null) {
            // Solo las filas del valor elegido en el campo del que depende
            $parts[] = Database::ident($dep['column']) . ' IN (' . implode(', ', array_fill(0, count($parent), '?')) . ')';
            array_push($bind, ...$parent);
        }
        if ($s['where'] !== null) {
            $parts[] = '(' . $s['where'] . ')';
        }
        if ($s['module'] !== null) {
            $module = $this->ctx->module($s['module'])
                ?? throw new \RuntimeException("Campo '{$this->name}': el módulo '{$s['module']}' de \"source\" no existe");
            if (($c = $module->rowCondition()) !== null) {
                $parts[] = '(' . $c[0] . ')';
                array_push($bind, ...$c[1]);
            }
        }
        return [$parts ? ' AND ' . implode(' AND ', $parts) : '', $bind];
    }

    /** @return array<string,string> valor => etiqueta */
    public function options(): array
    {
        if ($this->options !== null) {
            return $this->options;
        }
        $options = [];
        if (($s = $this->source()) !== null) {
            [$filter, $bind] = $this->sourceFilter($s);
            $sql = sprintf(
                'SELECT %s AS v, %s AS l FROM %s WHERE 1 = 1%s ORDER BY %s',
                Database::ident($s['value']),
                Database::ident($s['label']),
                Database::ident($s['table']),
                $filter,
                Database::ident($s['order']) . ' ' . $s['dir']
            );
            foreach ($this->ctx->db->fetchAll($sql, $bind) as $row) {
                $options[(string) $row['v']] = (string) $row['l'];
            }
        } else {
            foreach ((array) ($this->def['options'] ?? []) as $value => $label) {
                $options[(string) $value] = $this->ctx->t((string) $label);
            }
        }
        return $this->options = $options;
    }

    /** Etiqueta de un valor (en modo remoto, con una consulta por valor y caché). */
    public function labelFor(string $value): ?string
    {
        if ($this->searchMode() !== 'remote') {
            return $this->options()[$value] ?? null;
        }
        if (!array_key_exists($value, $this->labels)) {
            $s = $this->source();
            [$filter, $bind] = $this->sourceFilter($s);
            $label = $this->ctx->db->fetchValue(sprintf(
                'SELECT %s FROM %s WHERE %s = ?%s',
                Database::ident($s['label']),
                Database::ident($s['table']),
                Database::ident($s['value']),
                $filter
            ), [$value, ...$bind]);
            $this->labels[$value] = $label === null ? null : (string) $label;
        }
        return $this->labels[$value];
    }

    /**
     * Búsqueda para el modo remoto (ruta r=options).
     * @return list<array{value:string,label:string}>
     */
    public function search(string $q, array $parent = []): array
    {
        $s = $this->source();
        if ($s === null) {
            $q = mb_strtolower(trim($q));
            $out = [];
            foreach ($this->options() as $v => $l) {
                if ($q === '' || str_contains(mb_strtolower($l), $q)) {
                    $out[] = ['value' => (string) $v, 'label' => $l];
                }
            }
            return array_slice($out, 0, self::REMOTE_LIMIT);
        }
        [$filter, $bind] = $this->sourceFilter($s, $parent);
        $rows = $this->ctx->db->fetchAll(sprintf(
            'SELECT %s AS v, %s AS l FROM %s WHERE %s LIKE ?%s ORDER BY %s LIMIT %d',
            Database::ident($s['value']),
            Database::ident($s['label']),
            Database::ident($s['table']),
            Database::ident($s['label']),
            $filter,
            Database::ident($s['order']) . ' ' . $s['dir'],
            self::REMOTE_LIMIT
        ), ['%' . addcslashes(trim($q), '%_\\') . '%', ...$bind]);
        return array_map(static fn ($r) => ['value' => (string) $r['v'], 'label' => (string) $r['l']], $rows);
    }

    /**
     * Opciones que se pintan en el <select>: todas, o en modo remoto o de solo lectura solo las
     * seleccionadas (un campo que no se puede cambiar no enseña el resto, p. ej. todos los usuarios).
     * @param list<string> $current
     */
    private function renderOptions(array $current, bool $onlySelected = false): array
    {
        if (!$onlySelected && $this->searchMode() !== 'remote') {
            return $this->options();
        }
        $out = [];
        foreach ($current as $value) {
            if ($value !== '' && ($label = $this->labelFor($value)) !== null) {
                $out[$value] = $label;
            }
        }
        return $out;
    }

    /**
     * Atributo data-combobox para app.js (buscador), o null. La selección múltiple
     * siempre usa el componente (etiquetas con buscador).
     */
    private function comboAttr(string $placeholder, bool $multiple = false, bool $isFilter = false): ?string
    {
        $mode = $this->searchMode();
        if ($mode === 'off' && !$multiple) {
            return null;
        }
        $dep = $this->dependency($isFilter ? 'filters' : 'form');
        return json_encode([
            'remote' => $mode === 'remote' ? $this->ctx->url('options', ['m' => $this->ctx->domain, 'field' => $this->path()]) : null,
            // Campo del que depende (nombre del input en la ficha o en los filtros): sus valores se envían al buscar
            'parent' => $dep !== null ? ($isFilter ? "f[{$dep['field']}][]" : $dep['field']) : null,
            'placeholder' => $placeholder,
            'search' => $this->ctx->t('combo.search'),
            'empty' => $this->ctx->t('combo.no_results'),
            'loading' => $this->ctx->t('combo.loading'),
            'clear' => $this->ctx->t('combo.clear'),
        ] + ($multiple ? [
            'multiple' => true,
            'max' => $isFilter ? null : $this->maxItems(), // en un filtro se pueden elegir todas
            'remove' => $this->ctx->t('combo.remove'),
            'maxText' => $this->ctx->t('validation.max_items', ['max' => (int) $this->maxItems()]),
            'colors' => $this->colors(),
        ] : []), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function render(mixed $value, array $opts = []): string
    {
        if ($this->isMultiple()) {
            return $this->renderMultiple($value, $opts);
        }
        $attrs = $this->baseAttrs($opts);
        $readonly = $attrs['readonly'];
        unset($attrs['readonly'], $attrs['placeholder']);
        $attrs['disabled'] = $readonly;

        $current = $value === null ? '' : (string) $value;
        $required = $this->isRequired(!empty($opts['isNew']));
        $placeholder = isset($this->def['placeholder']) ? $this->ctx->t((string) $this->def['placeholder']) : '';
        $attrs['data-combobox'] = $readonly ? null : $this->comboAttr($placeholder);
        $attrs['data-required'] = $required && !$readonly;

        $html = '<select' . $this->attrs($attrs) . '>';
        if (!$required || $current === '') {
            $html .= '<option value="">' . self::e($placeholder) . '</option>';
        }
        foreach ($this->renderOptions([$current], $readonly) as $v => $label) {
            $v = (string) $v; // PHP convierte las claves numéricas en int
            $html .= '<option value="' . self::e($v) . '"' . ($v === $current ? ' selected' : '') . '>'
                . self::e($label) . '</option>';
        }
        return $html . '</select>';
    }

    /** <select multiple> que app.js convierte en etiquetas con buscador; en solo lectura, las etiquetas. */
    private function renderMultiple(mixed $value, array $opts): string
    {
        $current = $this->toList($value);
        $attrs = $this->baseAttrs($opts);
        if ($attrs['readonly']) {
            $html = '';
            foreach ($this->chips($current) as [$text, $tone]) {
                $html .= '<span class="qs-tag ' . (str_starts_with($tone, '#') ? '" style="--t:' . self::e($tone) : 'qs-tone-' . self::e($tone)) . '">' . self::e($text) . '</span>';
            }
            return '<div class="qs-tags is-readonly" id="' . self::e($this->inputId()) . '">' . $html . '</div>';
        }
        $placeholder = isset($this->def['placeholder']) ? $this->ctx->t((string) $this->def['placeholder']) : '';
        unset($attrs['readonly'], $attrs['placeholder']);
        $attrs['name'] = $this->inputName() . '[]';
        $attrs['multiple'] = true;
        $attrs['data-combobox'] = $this->comboAttr($placeholder, true);
        $html = '<select' . $this->attrs($attrs) . '>';
        foreach ($this->renderOptions($current) as $v => $label) {
            $v = (string) $v;
            $html .= '<option value="' . self::e($v) . '"' . (in_array($v, $current, true) ? ' selected' : '') . '>'
                . self::e($label) . '</option>';
        }
        return $html . '</select>';
    }

    public function fromRequest(array $input): mixed
    {
        return $this->isMultiple() ? $this->toList($input[$this->name] ?? []) : parent::fromRequest($input);
    }

    protected function validateValue(mixed $value): array
    {
        if (!$this->isMultiple()) {
            return $this->labelFor((string) $value) !== null ? [] : [$this->ctx->t('validation.option')];
        }
        $values = $this->toList($value);
        if (($max = $this->maxItems()) !== null && count($values) > $max) {
            return [$this->ctx->t('validation.max_items', ['max' => $max])];
        }
        foreach ($values as $v) {
            if ($this->labelFor($v) === null) {
                return [$this->ctx->t('validation.option')];
            }
        }
        return [];
    }

    public function toDb(mixed $value): mixed
    {
        if ($this->isMultiple()) {
            return json_encode($this->toList($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return $value === '' ? null : $value;
    }

    public function toJson(mixed $value): mixed
    {
        return $this->isMultiple() ? $this->toList($value) : parent::toJson($value);
    }

    public function fromDb(mixed $value): mixed
    {
        return $this->isMultiple() ? $this->toList($value) : $value;
    }

    public function format(mixed $value): string
    {
        if ($this->isMultiple()) {
            return implode(', ', array_map(fn ($v) => $this->labelFor($v) ?? $v, $this->toList($value)));
        }
        if ($value === null || $value === '') {
            return '';
        }
        return $this->labelFor((string) $value) ?? (string) $value;
    }

    /**
     * [etiqueta, color] de cada valor de una selección múltiple.
     * @param list<string> $values
     * @return list<array{0:string,1:string}>
     */
    private function chips(array $values): array
    {
        $colors = $this->colors();
        return array_map(fn ($v) => [$this->labelFor($v) ?? $v, $colors[$v] ?? $colors['*'] ?? 'gray'], $values);
    }

    /** Selección múltiple con "colors": una etiqueta de color por valor (sin colores, texto con comas). */
    public function cellTags(mixed $value): ?array
    {
        if (!$this->isMultiple() || !$this->colors()) {
            return null;
        }
        return $this->chips($this->toList($value)) ?: null;
    }

    public function badgeColor(mixed $value): ?string
    {
        return $this->isMultiple() ? null : parent::badgeColor($value);
    }

    public function filterClause(mixed $value, string $operator): ?array
    {
        $col = $this->sqlExpr();
        if (!$this->isMultiple() || $col === null) {
            return parent::filterClause($value, $operator);
        }
        $values = self::filterValues($value);
        if (!$values) {
            return null;
        }
        // Registros cuya lista contiene alguno de los valores (CASE: datos antiguos que no son JSON no dan error)
        $one = "JSON_CONTAINS($col, JSON_QUOTE(?))";
        return ["CASE WHEN JSON_VALID($col) THEN (" . implode(' OR ', array_fill(0, count($values), $one)) . ') ELSE 0 END', $values];
    }

    public function describeFilter(mixed $value, string $operator): ?string
    {
        $values = self::filterValues($value);
        if ($this->isMultiple() && $values) {
            return implode(', ', array_map(fn ($v) => $this->labelFor($v) ?? $v, $values));
        }
        return parent::describeFilter($value, $operator);
    }

    /** Filtro "=": se pueden elegir varias opciones (cualquiera de ellas); sin ninguna, todos. */
    public function renderFilter(mixed $value, string $operator): string
    {
        $current = self::filterValues($value);
        $html = '<select' . $this->attrs([
            'name' => "f[{$this->name}][]",
            'class' => 'qs-input',
            'multiple' => true,
            'data-combobox' => $this->comboAttr($this->ctx->t('filter.all'), true, true),
        ]) . '>';
        foreach ($this->renderOptions($current) as $v => $label) {
            $v = (string) $v; // PHP convierte las claves numéricas en int
            $html .= '<option value="' . self::e($v) . '"' . (in_array($v, $current, true) ? ' selected' : '') . '>'
                . self::e($label) . '</option>';
        }
        return $html . '</select>';
    }
}
