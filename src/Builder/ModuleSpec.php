<?php
declare(strict_types=1);

namespace Quasar\Builder;

use Quasar\Core\Database;

/**
 * Definición de un módulo nuevo del constructor (la propone la IA y la revisa el administrador):
 *
 *   {name, icon, group, title: {ca,es,en}, description: {…}|null,
 *    fields: [{name, type, label: {…}, required, maxlength, decimals, multiple,
 *              options: [{value, label: {…}, color}], source: {table, label}|null,
 *              list, filter, search, width, from}],
 *    sections: [{title: {…}, fields: [nombres]}], sort: {field, dir}}
 *
 * normalize() la valida contra la BD y los módulos existentes; a partir de ella Quasar genera el
 * CREATE TABLE (nunca lo escribe la IA), el JSON del módulo y sus textos en cada idioma.
 */
final class ModuleSpec
{
    public const LOCALES = ['ca', 'es', 'en'];
    public const TYPES = ['text', 'textarea', 'richtext', 'number', 'email', 'date', 'datetime', 'checkbox', 'select', 'tags'];
    public const TONES = ['gray', 'blue', 'green', 'amber', 'orange', 'red', 'purple', 'pink', 'cyan', 'teal'];
    public const WIDTHS = ['full', 'half', 'third', 'two-thirds', 'quarter'];
    private const MAX_FIELDS = 60;
    /** Palabras reservadas de MySQL/MariaDB habituales como nombre de columna o tabla */
    private const RESERVED = [
        'add', 'all', 'alter', 'analyze', 'and', 'as', 'asc', 'before', 'between', 'both', 'by', 'call', 'cascade', 'case',
        'change', 'check', 'collate', 'column', 'condition', 'constraint', 'continue', 'convert', 'create', 'cross',
        'current_date', 'current_time', 'current_timestamp', 'current_user', 'database', 'databases', 'dec', 'decimal',
        'declare', 'default', 'delete', 'desc', 'describe', 'distinct', 'div', 'double', 'drop', 'each', 'else', 'elseif',
        'exists', 'exit', 'explain', 'false', 'fetch', 'float', 'for', 'force', 'foreign', 'from', 'fulltext', 'function',
        'generated', 'get', 'grant', 'group', 'groups', 'having', 'if', 'ignore', 'in', 'index', 'inner', 'insert', 'int',
        'integer', 'interval', 'into', 'is', 'join', 'key', 'keys', 'kill', 'lag', 'lead', 'left', 'like', 'limit', 'lines',
        'load', 'lock', 'long', 'loop', 'match', 'mod', 'natural', 'not', 'null', 'numeric', 'of', 'on', 'option', 'or',
        'order', 'out', 'outer', 'over', 'partition', 'precision', 'primary', 'procedure', 'purge', 'range', 'rank', 'read',
        'real', 'references', 'regexp', 'release', 'rename', 'repeat', 'replace', 'require', 'restrict', 'return', 'revoke',
        'right', 'rlike', 'row', 'rows', 'schema', 'select', 'set', 'show', 'signal', 'spatial', 'sql', 'table', 'then', 'to',
        'trigger', 'true', 'undo', 'union', 'unique', 'unlock', 'unsigned', 'update', 'usage', 'use', 'using', 'values',
        'varchar', 'when', 'where', 'while', 'window', 'with', 'write', 'xor', 'zerofill',
    ];
    /** Columnas que nunca se usan como etiqueta de un select (no se muestran secretos) */
    private const SECRET = '/pass|token|secret|hash|key/i';

    /** @param list<string> $icons nombres de iconos Lucide disponibles */
    public function __construct(
        private readonly Database $db,
        private readonly string $modulesDir,
        private readonly array $icons,
    ) {
    }

    /**
     * Valida y completa la definición. Corrige en silencio lo menor (icono, anchos, textos que faltan,
     * secciones) y devuelve como errores lo que el administrador debe decidir.
     * @return array{spec:array,errors:list<array{0:string,1:array<string,string>}>} errores = [clave de idioma, variables]
     */
    public function normalize(array $raw): array
    {
        $errors = [];
        $err = static function (string $key, array $vars = []) use (&$errors): void {
            $errors[] = [$key, $vars];
        };
        $name = strtolower(trim((string) ($raw['name'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_]{1,40}$/', $name) || in_array($name, self::RESERVED, true)) {
            $err('errors.name', ['name' => $name]);
        } elseif (is_file($this->modulesDir . '/' . $name . '.json')) {
            $err('errors.module_exists', ['name' => $name]);
        } elseif ($this->tableExists($name)) {
            $err('errors.table_exists', ['name' => $name]);
        }
        $spec = [
            'name' => $name,
            'icon' => in_array($raw['icon'] ?? null, $this->icons, true) ? (string) $raw['icon'] : 'table',
            'group' => ($raw['group'] ?? 'main') === 'admin' ? 'admin' : 'main',
            'title' => self::texts($raw['title'] ?? null, $name),
            'description' => is_array($raw['description'] ?? null) && array_filter((array) $raw['description']) ? self::texts($raw['description'], '') : null,
            'fields' => [],
            'sections' => [],
            'sort' => null,
        ];

        $fields = array_values(array_filter((array) ($raw['fields'] ?? []), 'is_array'));
        if ($fields === [] || count($fields) > self::MAX_FIELDS) {
            $err('errors.fields_count', ['max' => (string) self::MAX_FIELDS]);
        }
        $seen = [];
        foreach (array_slice($fields, 0, self::MAX_FIELDS) as $f) {
            $field = $this->field($f, $err);
            if ($field === null) {
                continue;
            }
            if (isset($seen[$field['name']])) {
                $err('errors.field_duplicate', ['field' => $field['name']]);
                continue;
            }
            $seen[$field['name']] = true;
            $spec['fields'][] = $field;
        }
        $names = array_column($spec['fields'], 'name');
        if ($names && !array_filter($spec['fields'], static fn ($f) => $f['list'])) {
            foreach (array_slice(array_keys($spec['fields']), 0, 4) as $i) {
                $spec['fields'][$i]['list'] = true;
            }
        }

        // Secciones: cada campo en una sola; los que falten, a la última
        $used = [];
        foreach (array_filter((array) ($raw['sections'] ?? []), 'is_array') as $s) {
            $list = array_values(array_filter(array_map('strval', (array) ($s['fields'] ?? [])), static function ($n) use ($names, &$used) {
                if (!in_array($n, $names, true) || isset($used[$n])) {
                    return false;
                }
                return $used[$n] = true;
            }));
            if ($list) {
                $spec['sections'][] = ['title' => self::texts($s['title'] ?? null, ''), 'fields' => $list];
            }
        }
        $missing = array_values(array_filter($names, static fn ($n) => !isset($used[$n])));
        if ($missing) {
            if ($spec['sections']) {
                $last = count($spec['sections']) - 1;
                $spec['sections'][$last]['fields'] = array_merge($spec['sections'][$last]['fields'], $missing);
            } else {
                $spec['sections'][] = ['title' => self::texts(null, ''), 'fields' => $missing];
            }
        }

        $sort = (array) ($raw['sort'] ?? []);
        $sortField = in_array($sort['field'] ?? null, [...$names, 'id', 'created_at'], true) ? (string) $sort['field'] : ($names[0] ?? 'id');
        $spec['sort'] = ['field' => $sortField, 'dir' => ($sort['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc'];
        return ['spec' => $spec, 'errors' => $errors];
    }

    /** @param \Closure(string,array):void $err */
    private function field(array $f, \Closure $err): ?array
    {
        $name = strtolower(trim((string) ($f['name'] ?? '')));
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name) || in_array($name, [...self::RESERVED, 'id', 'created_at'], true)) {
            $err('errors.field_name', ['field' => $name]);
            return null;
        }
        $type = (string) ($f['type'] ?? 'text');
        if (!in_array($type, self::TYPES, true)) {
            $err('errors.field_type', ['field' => $name, 'type' => $type]);
            return null;
        }
        $out = [
            'name' => $name,
            'type' => $type,
            'label' => self::texts($f['label'] ?? null, $name),
            'required' => !empty($f['required']) && $type !== 'checkbox',
            'maxlength' => null,
            'decimals' => null,
            'multiple' => false,
            'options' => [],
            'source' => null,
            'list' => !empty($f['list']),
            'filter' => !empty($f['filter']),
            'search' => !empty($f['search']) && in_array($type, ['text', 'email', 'textarea', 'richtext', 'tags'], true),
            'width' => in_array($f['width'] ?? null, self::WIDTHS, true) ? (string) $f['width'] : self::defaultWidth($type),
            'from' => is_string($f['from'] ?? null) && $f['from'] !== '' ? $f['from'] : null,
        ];
        if ($type === 'text') {
            // Más de 255 caracteres es un textarea (VARCHAR largos llenan la fila de MySQL)
            $max = (int) ($f['maxlength'] ?? 150);
            $out['maxlength'] = max(1, min(255, $max > 0 ? $max : 150));
        } elseif ($type === 'email') {
            $out['maxlength'] = 150;
        } elseif ($type === 'number') {
            $out['decimals'] = max(0, min(6, (int) ($f['decimals'] ?? 0)));
        } elseif ($type === 'select') {
            $source = $f['source'] ?? null;
            if (is_array($source) && !empty($source['table'])) {
                $out['source'] = $this->source((string) $source['table'], (string) ($source['label'] ?? ''), $name, $err);
                if ($out['source'] === null) {
                    return null;
                }
            } else {
                $out['multiple'] = !empty($f['multiple']);
                $values = [];
                foreach (array_filter((array) ($f['options'] ?? []), 'is_array') as $o) {
                    $value = strtolower(trim((string) ($o['value'] ?? '')));
                    if (!preg_match('/^[a-z0-9_-]{1,40}$/', $value) || isset($values[$value])) {
                        $err('errors.option', ['field' => $name, 'value' => $value]);
                        continue;
                    }
                    $values[$value] = true;
                    $out['options'][] = [
                        'value' => $value,
                        'label' => self::texts($o['label'] ?? null, $value),
                        'color' => in_array($o['color'] ?? null, self::TONES, true) ? (string) $o['color'] : null,
                    ];
                }
                if ($out['options'] === [] || count($out['options']) > 100) {
                    $err('errors.options_count', ['field' => $name]);
                    return null;
                }
            }
        }
        return $out;
    }

    /**
     * Relación con otra tabla: debe existir, con clave primaria de una columna y la columna de la etiqueta.
     * @param \Closure(string,array):void $err
     * @return array{table:string,label:string,value:string,column_type:string,innodb:bool,rows:int}|null
     */
    private function source(string $table, string $label, string $field, \Closure $err): ?array
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $table) || !$this->tableExists($table)) {
            $err('errors.source_table', ['field' => $field, 'table' => $table]);
            return null;
        }
        $cols = $this->db->fetchAll(
            'SELECT COLUMN_NAME name, COLUMN_TYPE type, COLUMN_KEY k FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table]
        );
        $pk = array_values(array_filter($cols, static fn ($c) => $c['k'] === 'PRI'));
        $names = array_column($cols, 'name');
        if (count($pk) !== 1) {
            $err('errors.source_pk', ['field' => $field, 'table' => $table]);
            return null;
        }
        if (!in_array($label, $names, true) || preg_match(self::SECRET, $label)) {
            // Sin etiqueta válida: la primera columna de texto que no sea secreta
            $label = (string) ($this->db->fetchValue(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND DATA_TYPE IN ('varchar', 'char', 'text') ORDER BY ORDINAL_POSITION",
                [$table]
            ) ?? '');
            if ($label === '' || preg_match(self::SECRET, $label)) {
                $err('errors.source_label', ['field' => $field, 'table' => $table]);
                return null;
            }
        }
        $engine = (string) $this->db->fetchValue('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
        return [
            'table' => $table,
            'label' => $label,
            'value' => (string) $pk[0]['name'],
            'column_type' => (string) $pk[0]['type'],
            'innodb' => strcasecmp($engine, 'InnoDB') === 0,
            'rows' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . Database::ident($table)),
        ];
    }

    private function tableExists(string $name): bool
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$name]
        ) > 0;
    }

    /** @return array{ca:string,es:string,en:string} */
    private static function texts(mixed $raw, string $fallback): array
    {
        $raw = is_array($raw) ? $raw : [];
        $any = '';
        foreach (self::LOCALES as $l) {
            if (is_string($raw[$l] ?? null) && trim($raw[$l]) !== '') {
                $any = trim($raw[$l]);
                break;
            }
        }
        $out = [];
        foreach (self::LOCALES as $l) {
            $text = is_string($raw[$l] ?? null) && trim($raw[$l]) !== '' ? trim($raw[$l]) : ($any !== '' ? $any : $fallback);
            $out[$l] = mb_substr($text, 0, 150);
        }
        return $out;
    }

    private static function defaultWidth(string $type): string
    {
        return match ($type) {
            'textarea', 'richtext', 'tags' => 'full',
            'checkbox', 'date' => 'quarter',
            'number', 'datetime' => 'third',
            default => 'half',
        };
    }

    // ---------------------------------------------------------------- lo que se genera

    /** CREATE TABLE a partir de la definición ya normalizada. */
    public function createSql(array $spec): string
    {
        $q = static fn (string $s) => "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
        $lines = ['  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT'];
        $keys = ['  PRIMARY KEY (`id`)'];
        foreach ($spec['fields'] as $f) {
            $col = Database::ident($f['name']);
            $type = match ($f['type']) {
                'text', 'email' => 'VARCHAR(' . $f['maxlength'] . ') NULL',
                'textarea' => 'TEXT NULL',
                'richtext' => 'MEDIUMTEXT NULL',
                'number' => $f['decimals'] > 0 ? 'DECIMAL(' . (12 + $f['decimals']) . ',' . $f['decimals'] . ') NULL' : 'INT NULL',
                'date' => 'DATE NULL',
                'datetime' => 'DATETIME NULL',
                'checkbox' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'tags' => 'JSON NULL',
                'select' => $f['source'] !== null ? strtoupper($f['source']['column_type']) . ' NULL' : ($f['multiple'] ? 'JSON NULL' : 'VARCHAR(40) NULL'),
            };
            // El comentario de la columna ayuda a la IA de los informes
            $lines[] = "  $col $type COMMENT " . $q($f['label']['es']);
            if ($f['type'] === 'select' && !$f['multiple']) {
                $keys[] = '  KEY ' . Database::ident('ix_' . substr($spec['name'] . '_' . $f['name'], 0, 60)) . " ($col)";
            }
            if ($f['source'] !== null && $f['source']['innodb']) {
                $keys[] = '  CONSTRAINT ' . Database::ident('fk_' . substr($spec['name'] . '_' . $f['name'], 0, 60))
                    . " FOREIGN KEY ($col) REFERENCES " . Database::ident($f['source']['table']) . ' (' . Database::ident($f['source']['value']) . ') ON DELETE SET NULL';
            }
        }
        $lines[] = '  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        return 'CREATE TABLE ' . Database::ident($spec['name']) . " (\n" . implode(",\n", [...$lines, ...$keys])
            . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=" . $q($spec['title']['es']);
    }

    /** JSON del módulo (modules/{name}.json). */
    public function moduleDef(array $spec): array
    {
        $fields = ['id' => ['type' => 'number', 'readonly' => true, 'grouping' => false]];
        foreach ($spec['fields'] as $f) {
            $def = ['type' => $f['type']];
            if ($f['required']) {
                $def['required'] = true;
            }
            if ($f['maxlength'] !== null && $f['type'] === 'text') {
                $def['maxlength'] = $f['maxlength'];
            }
            if ($f['type'] === 'number' && $f['decimals'] > 0) {
                $def['decimals'] = $f['decimals'];
            }
            if ($f['type'] === 'checkbox') {
                $def['default'] = 0;
            }
            if ($f['type'] === 'select') {
                if ($f['source'] !== null) {
                    $def['source'] = ['table' => $f['source']['table'], 'value' => $f['source']['value'], 'label' => $f['source']['label']];
                    $def['search'] = $f['source']['rows'] > 200 ? 'remote' : true;
                } else {
                    if ($f['multiple']) {
                        $def['multiple'] = true;
                    }
                    $def['options'] = [];
                    foreach ($f['options'] as $o) {
                        $def['options'][$o['value']] = "options.{$f['name']}.{$o['value']}";
                    }
                    $colors = array_filter(array_column($f['options'], 'color', 'value'));
                    if ($colors) {
                        $def['colors'] = $colors;
                    }
                }
            }
            if ($f['width'] !== 'full') {
                $def['width'] = $f['width'];
            }
            $fields[$f['name']] = $def;
        }
        $fields['created_at'] = ['type' => 'datetime', 'readonly' => true, 'width' => 'third'];

        $columns = [['field' => 'id', 'width' => '70px']];
        foreach ($spec['fields'] as $f) {
            if ($f['list']) {
                $columns[] = in_array($f['type'], ['textarea', 'richtext'], true) ? ['field' => $f['name'], 'overflow' => 'wrap'] : $f['name'];
            }
        }
        $filters = [];
        foreach ($spec['fields'] as $f) {
            if ($f['filter']) {
                $filters[] = in_array($f['type'], ['number', 'date', 'datetime'], true) ? ['field' => $f['name'], 'operator' => 'between'] : ['field' => $f['name']];
            }
        }
        $list = ['columns' => $columns, 'defaultSort' => $spec['sort'], 'pageSize' => 25];
        $search = array_column(array_filter($spec['fields'], static fn ($f) => $f['search']), 'name');
        if ($search) {
            $list['search'] = $search;
        }
        if ($filters) {
            $list['filters'] = $filters;
        }

        $sections = [];
        foreach ($spec['sections'] as $i => $s) {
            $sections[] = ['title' => 'sections.s' . ($i + 1), 'fields' => $s['fields']];
        }
        $sections[count($sections) - 1]['fields'][] = 'created_at';

        return [
            'table' => $spec['name'],
            'primaryKey' => 'id',
            'menu' => ['group' => $spec['group'], 'order' => 100, 'icon' => $spec['icon']],
            'fields' => $fields,
            'list' => $list,
            'form' => count($sections) === 1 && $spec['sections'][0]['title']['es'] === '' ? ['fields' => $sections[0]['fields']] : ['sections' => $sections],
        ];
    }

    /**
     * Textos del módulo en un idioma (lang/{idioma}/{name}.json).
     * @param array{id:string,created_at:string} $auto etiquetas de las columnas que añade Quasar
     */
    public function langFile(array $spec, string $locale, array $auto): array
    {
        $out = ['title' => $spec['title'][$locale]];
        if ($spec['description'] !== null) {
            $out['description'] = $spec['description'][$locale];
        }
        $out['fields'] = ['id' => $auto['id']];
        $options = [];
        foreach ($spec['fields'] as $f) {
            $out['fields'][$f['name']] = $f['label'][$locale];
            foreach ($f['options'] as $o) {
                $options[$f['name']][$o['value']] = $o['label'][$locale];
            }
        }
        $out['fields']['created_at'] = $auto['created_at'];
        if ($options) {
            $out['options'] = $options;
        }
        foreach ($spec['sections'] as $i => $s) {
            $out['sections']['s' . ($i + 1)] = $s['title'][$locale] !== '' ? $s['title'][$locale] : $spec['title'][$locale];
        }
        return $out;
    }

    /** JSON con sangría de 2 espacios, como los módulos escritos a mano. */
    public static function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return preg_replace_callback('/^( +)/m', static fn ($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json) . "\n";
    }
}
