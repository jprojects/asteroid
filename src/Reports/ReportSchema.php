<?php
declare(strict_types=1);

namespace Quasar\Reports;

use Quasar\Core\Database;
use Quasar\Core\ModuleRegistry;
use Quasar\Fields\CheckboxField;
use Quasar\Fields\Field;
use Quasar\Fields\RepeaterField;
use Quasar\Fields\SelectField;
use Quasar\Fields\TagsField;

/**
 * Estructura de las tablas que pueden usar los informes ('reports.tables'), leída de la propia BD:
 * columnas, tipos, comentarios y claves foráneas. Se completa con lo que saben los módulos de
 * Quasar que usan esas tablas (etiqueta de cada columna, opciones de los select, sí/no, JSON…).
 * Así funciona igual en cualquier instalación: basta con indicar qué tablas se pueden consultar.
 */
final class ReportSchema
{
    /** @var array<string,list<array{name:string,type:string,key:string,comment:string}>>|null */
    private ?array $columns = null;

    /** @param array<string,mixed> $config config['reports'] */
    public function __construct(
        private readonly Database $db,
        private readonly array $config,
        private readonly ?ModuleRegistry $modules = null,
    ) {
    }

    /** @return array<string,string> tabla => descripción de la configuración ('' si no tiene) */
    public function tables(): array
    {
        $out = [];
        foreach ((array) ($this->config['tables'] ?? []) as $k => $v) {
            [$name, $desc] = is_int($k) ? [(string) $v, ''] : [(string) $k, (string) $v];
            if (preg_match('/^[A-Za-z0-9_]+$/', $name) && !in_array(strtolower($name), SqlGuard::NEVER, true)) {
                $out[$name] = $desc;
            }
        }
        return array_intersect_key($out, $this->describeColumns()); // solo las que existen
    }

    /** @return list<string> todas las tablas y vistas de la BD (para rechazar las no permitidas) */
    public function allTables(): array
    {
        return array_map('strval', array_column($this->db->fetchAll(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        ), 'TABLE_NAME'));
    }

    /** @return list<string> bases de datos visibles (para rechazar consultas a otras) */
    public function schemas(): array
    {
        return array_map('strval', array_column($this->db->fetchAll('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA'), 'SCHEMA_NAME'));
    }

    public function guard(): SqlGuard
    {
        return new SqlGuard(array_keys($this->tables()), $this->allTables(), $this->schemas());
    }

    public function serverVersion(): string
    {
        return (string) $this->db->fetchValue('SELECT VERSION()');
    }

    /** Texto para el modelo: tablas, columnas con su significado y relaciones. */
    public function describe(): string
    {
        $cols = $this->describeColumns();
        $hints = $this->moduleHints();
        $lines = [];
        foreach ($this->tables() as $table => $desc) {
            $lines[] = "TABLA $table" . ($desc !== '' ? " — $desc" : '');
            foreach ($cols[$table] as $c) {
                $info = array_filter([
                    $c['key'] === 'PRI' ? 'clave primaria' : null,
                    $hints[$table][$c['name']] ?? null,
                    $c['comment'] !== '' ? $c['comment'] : null,
                ]);
                $lines[] = "  {$c['name']} {$c['type']}" . ($info ? ' — ' . implode('. ', $info) : '');
            }
            $lines[] = '';
        }
        $relations = $this->relations();
        if ($relations) {
            $lines[] = 'RELACIONES';
            foreach ($relations as $from => $to) {
                $lines[] = "  $from → $to";
            }
        }
        return rtrim(implode("\n", $lines));
    }

    /**
     * Claves foráneas entre tablas permitidas, más las de 'reports.relations' (para vistas o tablas sin FK).
     * @return array<string,string> "tabla.columna" => "tabla.columna"
     */
    public function relations(): array
    {
        $allowed = array_keys($this->tables());
        $out = [];
        if ($allowed) {
            $in = implode(',', array_fill(0, count($allowed), '?'));
            $rows = $this->db->fetchAll(
                "SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME rt, REFERENCED_COLUMN_NAME rc
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
                   AND TABLE_NAME IN ($in) AND REFERENCED_TABLE_NAME IN ($in)",
                [...$allowed, ...$allowed]
            );
            foreach ($rows as $r) {
                $out["{$r['t']}.{$r['c']}"] = "{$r['rt']}.{$r['rc']}";
            }
        }
        foreach ((array) ($this->config['relations'] ?? []) as $from => $to) {
            $ft = explode('.', (string) $from)[0];
            $tt = explode('.', (string) $to)[0];
            if (in_array($ft, $allowed, true) && in_array($tt, $allowed, true)) {
                $out[(string) $from] = (string) $to;
            }
        }
        return $out;
    }

    /** @return array<string,list<array{name:string,type:string,key:string,comment:string}>> */
    private function describeColumns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $wanted = [];
        foreach ((array) ($this->config['tables'] ?? []) as $k => $v) {
            $wanted[] = is_int($k) ? (string) $v : (string) $k;
        }
        $this->columns = [];
        if (!$wanted) {
            return $this->columns;
        }
        $rows = $this->db->fetchAll(
            'SELECT TABLE_NAME t, COLUMN_NAME name, COLUMN_TYPE type, COLUMN_KEY k, COLUMN_COMMENT comment
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', array_fill(0, count($wanted), '?')) . ')
             ORDER BY TABLE_NAME, ORDINAL_POSITION',
            $wanted
        );
        foreach ($rows as $r) {
            $this->columns[$r['t']][] = ['name' => $r['name'], 'type' => $r['type'], 'key' => (string) $r['k'], 'comment' => (string) $r['comment']];
        }
        return $this->columns;
    }

    /**
     * Lo que dicen los módulos de Quasar de cada columna: etiqueta y valores posibles.
     * @return array<string,array<string,string>> tabla => columna => descripción
     */
    private function moduleHints(): array
    {
        $out = [];
        if ($this->modules === null) {
            return $out;
        }
        $tables = $this->tables();
        foreach ($this->modules->all() as $m) {
            if ($m->isPage() || !isset($tables[$m->table()])) {
                continue;
            }
            foreach ($m->fields() as $f) {
                if (!$f->isStored()) {
                    continue;
                }
                $out[$m->table()][$f->column()] ??= $this->hint($f);
            }
        }
        return $out;
    }

    private function hint(Field $f): string
    {
        $hint = $f->label();
        if ($f instanceof SelectField && $f->searchMode() !== 'remote') {
            $options = $f->options();
            if ($options && count($options) <= 50) {
                $pairs = [];
                foreach ($options as $v => $l) {
                    $pairs[] = "$v = $l";
                }
                $hint .= ($f->isMultiple() ? '. JSON con una lista de valores: ' : '. Valores: ') . implode('; ', $pairs);
            } elseif ($f->isMultiple()) {
                $hint .= '. JSON con una lista de valores';
            }
        } elseif ($f instanceof CheckboxField) {
            $hint .= '. 1 = sí, 0 = no';
        } elseif ($f instanceof TagsField) {
            $hint .= '. JSON con una lista de etiquetas de texto';
        } elseif ($f instanceof RepeaterField) {
            $hint .= '. JSON con una lista de objetos con: ' . implode(', ', array_keys($f->subFields()));
        }
        return $hint;
    }
}
