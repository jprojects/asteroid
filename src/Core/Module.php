<?php
declare(strict_types=1);

namespace Quasar\Core;

use Quasar\Fields\Field;
use Quasar\Fields\FieldFactory;
use Quasar\Fields\PasswordField;
use Quasar\Fields\RepeaterField;

/**
 * Un módulo = una tabla de BD con su vista de listado y su formulario,
 * descritos en modules/{nombre}.json. Ver README para el esquema completo.
 */
final class Module
{
    private const OPERATORS = ['like', '=', 'between'];
    private const OVERFLOW = ['ellipsis', 'wrap'];
    private const BUTTON_VARIANTS = ['default', 'primary', 'danger'];
    /** sitio del botón => [ruta en el JSON, admite showon] */
    private const BUTTON_PLACES = [
        'toolbar' => [['list', 'toolbar'], false],
        'row' => [['list', 'rowActions'], true],
        'form' => [['form', 'buttons'], true],
    ];

    /** @var array<string,Field> */
    private array $fields = [];
    private ?ModuleHooks $hooks = null;
    private bool $hooksResolved = false;
    private FieldContext $ctx;
    private ?Scope $scope = null;

    public function __construct(
        public readonly string $name,
        private readonly array $def,
        FieldContext $ctx,
    ) {
        $this->ctx = $ctx;

        if ($this->isPage()) {
            // Página personalizada: no tiene tabla propia, solo un controlador
            $class = $def['controller'] ?? null;
            if (!is_string($class) || !is_subclass_of($class, \Quasar\Controllers\Page::class)) {
                throw new \RuntimeException("Módulo '$name': una página necesita \"controller\" con una clase que extienda " . \Quasar\Controllers\Page::class);
            }
        } else {
            foreach (['table', 'fields'] as $key) {
                if (empty($def[$key])) {
                    throw new \RuntimeException("Módulo '$name': falta la clave obligatoria '$key'");
                }
            }
            Database::ident($this->table());
            Database::ident($this->primaryKey());
        }

        foreach ((array) ($def['fields'] ?? []) as $fieldName => $fieldDef) {
            $fieldName = (string) $fieldName;
            if (!is_array($fieldDef) || str_starts_with($fieldName, '_')) {
                throw new \RuntimeException("Módulo '$name': definición no válida del campo '$fieldName'");
            }
            Database::ident($fieldName);
            $field = FieldFactory::make($fieldName, $fieldDef, $this->ctx);
            Database::ident($field->column());
            $this->checkComputed($field);
            try {
                $field->colors();
            } catch (\InvalidArgumentException $e) {
                throw new \RuntimeException("Módulo '{$this->name}': " . $e->getMessage(), 0, $e);
            }
            $this->checkFieldAccess($field);
            $this->fields[$fieldName] = $field;
        }

        // Comprueba que todas las referencias a campos existen (falla pronto y con un mensaje claro)
        foreach ($this->fields as $field) {
            if (isset($def['fields'][$field->name]['dependsOn'])) {
                $dep = $def['fields'][$field->name]['dependsOn'];
                if (!$field instanceof \Quasar\Fields\SelectField || !is_array($dep) || !isset($this->fields[(string) ($dep['field'] ?? '')])
                    || empty($dep['column']) || empty($def['fields'][$field->name]['source'])) {
                    throw new \RuntimeException("Módulo '{$this->name}': \"dependsOn\" del campo '{$field->name}' necesita un select con \"source\" y {\"field\": otro campo del módulo, \"column\": columna de la tabla de origen}");
                }
            }
            foreach (ShowOn::fields($field->showOn() ?? []) as $ref) {
                if (!isset($this->fields[$ref]) || $ref === $field->name) {
                    throw new \RuntimeException("Módulo '{$this->name}': el showon del campo '{$field->name}' usa '$ref', que no es otro campo del módulo");
                }
            }
        }
        try {
            $this->scope = Scope::parse($def['scope'] ?? null);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException("Módulo '$name': \"scope\" no válido: " . $e->getMessage(), 0, $e);
        }
        if (isset($def['where']) && (!is_string($def['where']) || trim($def['where']) === '')) {
            throw new \RuntimeException("Módulo '$name': \"where\" debe ser una condición SQL");
        }
        if (($this->scope !== null || isset($def['where'])) && $this->isPage()) {
            throw new \RuntimeException("Módulo '$name': una página no puede tener \"scope\" ni \"where\"");
        }
        $this->listColumns();
        $this->totalFields();
        $this->expandFields();
        $this->filters();
        $this->searchFields();
        $this->formSections();
        $this->exportConfig();
        $this->checkButtons();
        $this->aiConfig();
        $this->chatConfig();
        $this->quickCreate();
        $this->accessRule($def['menu']['access'] ?? null, 'el menú');
    }

    /** @param \Closure(string):FieldContext $contextFactory recibe el nombre del módulo */
    public static function fromFile(string $file, \Closure $contextFactory): self
    {
        $name = basename($file, '.json');
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new \RuntimeException("Nombre de módulo no válido: '$name' (usa minúsculas, números y _)");
        }
        try {
            $def = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("JSON no válido en $file: " . $e->getMessage(), 0, $e);
        }
        return new self($name, (array) $def, $contextFactory($name));
    }

    // ---------------------------------------------------------------- general

    public function t(string $key, array $vars = []): string
    {
        return $this->ctx->t($key, $vars);
    }

    public function title(): string
    {
        return $this->t((string) ($this->def['title'] ?? 'title'));
    }

    /** Descripción opcional del módulo (clave "description" en su fichero de idioma). */
    public function description(): ?string
    {
        return $this->ctx->lang->has('description', $this->name) ? $this->t('description') : null;
    }

    /**
     * Texto que identifica un registro (título de su ficha): el campo indicado en
     * "recordTitle" del JSON o, si no, "name", "title", "username" o el primer campo de texto.
     */
    public function recordTitle(array $values): ?string
    {
        $candidates = isset($this->def['recordTitle']) ? [(string) $this->def['recordTitle']] : ['name', 'title', 'username'];
        foreach ($this->fields as $f) {
            if ($f->type() === 'text') {
                $candidates[] = $f->name;
            }
        }
        foreach ($candidates as $name) {
            if (isset($this->fields[$name]) && $this->fields[$name]->canView()) {
                $text = trim($this->fields[$name]->format($values[$name] ?? null));
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return null;
    }

    public function table(): string
    {
        return (string) ($this->def['table'] ?? '');
    }

    /** Módulo de tipo "page": página personalizada con su propio controlador (no listado/formulario). */
    public function isPage(): bool
    {
        return ($this->def['type'] ?? 'table') === 'page';
    }

    /** @return class-string<\Quasar\Controllers\Page>|null */
    public function pageController(): ?string
    {
        return $this->isPage() ? (string) $this->def['controller'] : null;
    }

    /** Opción libre del JSON del módulo (p. ej. la configuración propia de una página). */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->def[$key] ?? $default;
    }

    /** URL de entrada al módulo: el listado o, si es una página, la página. Sin escapar. */
    public function url(array $params = []): string
    {
        return $this->ctx->url($this->isPage() ? 'page' : 'list', ['m' => $this->name] + $params);
    }

    public function primaryKey(): string
    {
        return (string) ($this->def['primaryKey'] ?? 'id');
    }

    /** @return list<string> acciones sujetas a permiso */
    public function actions(): array
    {
        $default = $this->isPage() ? ['view'] : Permissions::DEFAULT_ACTIONS;
        return array_values((array) ($this->def['actions'] ?? $default));
    }

    public function isAdminOnly(): bool
    {
        return !empty($this->def['adminOnly']);
    }

    public function inMenu(): bool
    {
        return ($this->def['menu']['visible'] ?? true) !== false;
    }

    /**
     * Si el módulo sale en el menú de $user: "menu": { "access": ["equip"] } lo muestra solo a esos grupos
     * (el resto puede abrirlo igualmente desde un enlace si tiene permiso).
     */
    public function menuAllows(User $user): bool
    {
        $rule = $this->accessRule($this->def['menu']['access'] ?? null, 'el menú');
        return $rule === null || $rule->allows($user, $this->name);
    }

    public function menuGroup(): string
    {
        return (string) ($this->def['menu']['group'] ?? 'main');
    }

    public function menuOrder(): int
    {
        return (int) ($this->def['menu']['order'] ?? 100);
    }

    public function icon(): string
    {
        return (string) ($this->def['menu']['icon'] ?? 'table-2'); // nombre de icono Lucide
    }

    public function hooks(Database $db): ?ModuleHooks
    {
        if ($this->hooksResolved) {
            return $this->hooks;
        }
        $this->hooksResolved = true;
        $class = $this->hooksClass();
        return $this->hooks = $class !== null ? new $class($db) : null;
    }

    /** @return class-string<ModuleHooks>|null */
    private function hooksClass(): ?string
    {
        $class = $this->def['hooks'] ?? null;
        if ($class === null) {
            return null;
        }
        if (!is_string($class) || !is_subclass_of($class, ModuleHooks::class)) {
            throw new \RuntimeException("Módulo '{$this->name}': hooks debe extender " . ModuleHooks::class);
        }
        return $class;
    }

    /**
     * Valor de un campo calculado en PHP ("compute") a partir de la fila completa.
     * "metodo" se busca en la clase hooks del módulo; "Clase::metodo" es un método estático.
     */
    public function compute(Field $field, array $row): mixed
    {
        $fn = (string) $field->computeFunction();
        if (str_contains($fn, '::')) {
            return $fn($row);
        }
        $hooks = $this->hooks($this->ctx->db)
            ?? throw new \RuntimeException("Módulo '{$this->name}': el campo '{$field->name}' usa compute pero el módulo no tiene hooks");
        return $hooks->{$fn}($row);
    }

    private function checkComputed(Field $field): void
    {
        $fn = $field->computeFunction();
        if ($fn === null) {
            return;
        }
        if (isset($this->def['fields'][$field->name]['sql'])) {
            throw new \RuntimeException("Módulo '{$this->name}': el campo '{$field->name}' no puede tener a la vez sql y compute");
        }
        $ok = str_contains($fn, '::')
            ? is_callable($fn)
            : ($class = $this->hooksClass()) !== null && method_exists($class, $fn);
        if (!$ok) {
            throw new \RuntimeException("Módulo '{$this->name}': no existe la función '$fn' del campo calculado '{$field->name}'"
                . (str_contains($fn, '::') ? '' : ' (debe ser un método público de la clase hooks del módulo)'));
        }
    }

    // ---------------------------------------------------------------- registros visibles

    /**
     * Condición SQL de los registros que ve el usuario actual: "where" del módulo (para todos) y
     * "scope" (según sus grupos; ver Scope). Se aplica en el listado, la ficha, el guardado, el
     * borrado, las acciones, la exportación y los archivos.
     * @return array{0:string,1:list<mixed>}|null null = todos los registros
     */
    public function rowCondition(): ?array
    {
        return $this->rowConditionFor($this->ctx->user());
    }

    /** @return array{0:string,1:list<mixed>}|null como rowCondition(), para otro usuario (null = consola) */
    public function rowConditionFor(?User $user): ?array
    {
        $parts = [];
        $bind = [];
        if (isset($this->def['where'])) {
            $parts[] = '(' . $this->def['where'] . ')';
        }
        if (($c = $this->scope?->condition($user)) !== null) {
            $parts[] = '(' . $c[0] . ')';
            array_push($bind, ...$c[1]);
        }
        return $parts ? [implode(' AND ', $parts), $bind] : null;
    }

    /** Si el módulo restringe qué registros ve cada usuario. */
    public function hasScope(): bool
    {
        return $this->scope !== null || isset($this->def['where']);
    }

    /**
     * Columnas que el "scope" fija al guardar un registro de $user (p. ej. su cliente).
     * @return array<string,mixed>
     */
    public function scopeValues(?User $user): array
    {
        return $this->scope?->values($user) ?? [];
    }

    /** Si el registro existe y $user (por defecto el actual) puede verlo. */
    public function isVisible(string $id, ?User $user = null): bool
    {
        $c = func_num_args() > 1 ? $this->rowConditionFor($user) : $this->rowCondition();
        return $this->ctx->db->fetchValue(sprintf(
            'SELECT 1 FROM %s WHERE %s = ?%s LIMIT 1',
            Database::ident($this->table()),
            Database::ident($this->primaryKey()),
            $c !== null ? ' AND ' . $c[0] : ''
        ), [$id, ...($c[1] ?? [])]) !== null;
    }

    /**
     * Si el archivo $mediaId del campo $path está en algún registro que ve el usuario actual
     * (sin "scope" ni "where", siempre). Busca el id en la columna del campo (o del repeater).
     */
    public function mediaInScope(string $path, int $mediaId): bool
    {
        $c = $this->rowCondition();
        if ($c === null) {
            return true;
        }
        $parts = explode('.', $path);
        $field = $this->fields[$parts[0]] ?? null;
        if ($field === null || !$field->isStored()) {
            return false;
        }
        $pattern = count($parts) > 1
            // Dentro de un repeater: "subcampo": 12  o  "subcampo": [3, 12]
            ? '"' . end($parts) . '"[[:space:]]*:[[:space:]]*\\[?([0-9]+[[:space:]]*,[[:space:]]*)*' . $mediaId . '([^0-9]|$)'
            : $field->mediaPattern($mediaId);
        return $this->ctx->db->fetchValue(sprintf(
            'SELECT 1 FROM %s WHERE CAST(%s AS CHAR) REGEXP ? AND %s LIMIT 1',
            Database::ident($this->table()),
            Database::ident($field->column()),
            $c[0]
        ), [$pattern, ...$c[1]]) !== null;
    }

    // ---------------------------------------------------------------- acceso por grupos

    /**
     * Analiza una regla "access" del JSON (ver Access) y comprueba que su permiso es una acción del módulo.
     * @param string $where descripción del elemento para el mensaje de error
     */
    private function accessRule(mixed $rule, string $where): ?Access
    {
        try {
            $access = Access::parse($rule);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException("Módulo '{$this->name}': \"access\" de $where no válido: " . $e->getMessage(), 0, $e);
        }
        if ($access?->permission !== null && !in_array($access->permission, $this->actions(), true)) {
            throw new \RuntimeException("Módulo '{$this->name}': el permiso '{$access->permission}' de $where no está en \"actions\"");
        }
        return $access;
    }

    /** Si el usuario actual cumple la regla (sin regla, todos). */
    private function allows(?Access $access): bool
    {
        return $access === null || $access->allows($this->ctx->user(), $this->name);
    }

    private function checkFieldAccess(Field $field): void
    {
        foreach (['access', 'editAccess'] as $key) {
            $this->accessRule($field->option($key), "el campo '{$field->name}'");
        }
        foreach ($field instanceof RepeaterField ? $field->subFields() : [] as $sub) {
            if ($sub->option('access') !== null || $sub->option('editAccess') !== null) {
                throw new \RuntimeException("Módulo '{$this->name}': \"access\" no se admite en los subcampos de un repeater ('{$field->name}.{$sub->name}'); ponlo en el repeater");
            }
        }
    }

    /** El campo tal como lo ve el usuario actual: null si no puede verlo, en solo lectura si no puede modificarlo. */
    private function forViewer(Field $field): ?Field
    {
        if (!$field->canView()) {
            return null;
        }
        return $field->canEdit() ? $field : $field->asReadonly();
    }

    // ---------------------------------------------------------------- campos

    /** @return array<string,Field> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function field(string $name): Field
    {
        return $this->fields[$name]
            ?? throw new \RuntimeException("Módulo '{$this->name}': el campo '$name' no está definido en 'fields'");
    }

    /**
     * Campo por ruta, incluidos subcampos de repeaters: "contacts.photo". Tal como lo ve el
     * usuario actual: null si no puede verlo, en solo lectura si no puede modificarlo.
     */
    public function fieldByPath(string $path): ?Field
    {
        $parts = explode('.', $path);
        $field = $this->fields[array_shift($parts)] ?? null;
        $field = $field !== null ? $this->forViewer($field) : null;
        foreach ($parts as $part) {
            $field = $field instanceof RepeaterField ? $field->subField($part) : null;
        }
        return $field;
    }

    // ---------------------------------------------------------------- listado

    /**
     * Columnas del listado que puede ver el usuario actual.
     * @return list<array{field:Field,label:string,sortable:bool,width:?string,align:string,overflow:string,lines:?int,minWidth:?string,maxWidth:?string}>
     */
    public function listColumns(): array
    {
        return $this->parseColumns((array) ($this->def['list']['columns']
            ?? array_keys(array_filter($this->fields, static fn (Field $f) => $f->isSortable()))));
    }

    /**
     * Columnas (listado o exportación) que puede ver el usuario actual. Textos largos:
     *   "overflow": "ellipsis"   una línea recortada con "…" (por defecto; "list.overflow" cambia el del módulo)
     *   "overflow": "wrap"       texto entero en varias líneas;  "lines": 3  como máximo 3 líneas y "…"
     *   "minWidth" / "maxWidth"  límites de ancho de la columna (CSS: "200px", "20em")
     * @return list<array{field:Field,label:string,sortable:bool,width:?string,align:string,overflow:string,lines:?int,minWidth:?string,maxWidth:?string}>
     */
    private function parseColumns(array $defs): array
    {
        $defaultOverflow = (string) ($this->def['list']['overflow'] ?? 'ellipsis');
        $cols = [];
        foreach ($defs as $c) {
            $c = is_string($c) ? ['field' => $c] : (array) $c;
            $field = $this->field((string) ($c['field'] ?? ''));
            $overflow = (string) ($c['overflow'] ?? $defaultOverflow);
            if (!in_array($overflow, self::OVERFLOW, true)) {
                throw new \RuntimeException("Módulo '{$this->name}': \"overflow\" de la columna '{$field->name}' debe ser " . implode(' o ', self::OVERFLOW));
            }
            if (!$field->canView()) {
                continue;
            }
            $lines = isset($c['lines']) ? max(1, (int) $c['lines']) : null;
            $cols[] = [
                'field' => $field,
                'label' => isset($c['label']) ? $this->t((string) $c['label']) : $field->label(),
                'sortable' => ($c['sortable'] ?? true) !== false && $field->isSortable(),
                'width' => isset($c['width']) ? (string) $c['width'] : null,
                'align' => in_array($c['align'] ?? null, ['left', 'center', 'right'], true) ? $c['align'] : $field->align(),
                'overflow' => $overflow,
                'lines' => $overflow === 'wrap' ? $lines : null,
                'minWidth' => isset($c['minWidth']) ? (string) $c['minWidth'] : null,
                'maxWidth' => isset($c['maxWidth']) ? (string) $c['maxWidth'] : null,
            ];
        }
        return $cols;
    }

    /**
     * Campos que se muestran al desplegar una fila del listado (textos largos, notas…):
     *   "list": { "expand": ["notes", "contacts"] }
     *   "list": { "expand": { "fields": ["notes", { "field": "contacts", "label": "…" }] } }
     * @return list<array{field:Field,label:string}>
     */
    public function expandFields(): array
    {
        $cfg = $this->def['list']['expand'] ?? [];
        $defs = is_array($cfg) && !array_is_list($cfg) ? ($cfg['fields'] ?? []) : $cfg;
        if (!is_array($defs)) {
            throw new \RuntimeException("Módulo '{$this->name}': \"list.expand\" debe ser una lista de campos");
        }
        $out = [];
        foreach ($defs as $d) {
            $d = is_string($d) ? ['field' => $d] : (array) $d;
            $field = $this->field((string) ($d['field'] ?? ''));
            if ($field instanceof PasswordField) {
                throw new \RuntimeException("Módulo '{$this->name}': una contraseña no se puede mostrar en \"list.expand\"");
            }
            if ($field->canView()) {
                $out[] = ['field' => $field, 'label' => isset($d['label']) ? $this->t((string) $d['label']) : $field->label()];
            }
        }
        return $out;
    }

    /**
     * Exportación de la tabla. En el JSON:
     *   "list": { "export": false }                       desactiva
     *   "list": { "export": { "formats": ["xlsx", "pdf"],  por defecto ambos
     *                         "columns": [...],            por defecto las del listado
     *                         "orientation": "landscape" } } portrait | landscape | auto
     *
     * @return array{formats:list<string>,columns:list<array>,orientation:string}|null
     */
    public function exportConfig(): ?array
    {
        $cfg = $this->def['list']['export'] ?? true;
        if ($cfg === false || !in_array('export', $this->actions(), true)) {
            return null;
        }
        $cfg = is_array($cfg) ? $cfg : [];
        $formats = array_values(array_intersect(['xlsx', 'pdf'], (array) ($cfg['formats'] ?? ['xlsx', 'pdf'])));
        $columns = isset($cfg['columns']) ? $this->parseColumns((array) $cfg['columns']) : $this->listColumns();
        // Las contraseñas nunca se exportan
        $columns = array_values(array_filter($columns, static fn ($c) => !$c['field'] instanceof PasswordField));
        $orientation = in_array($cfg['orientation'] ?? null, ['portrait', 'landscape'], true) ? $cfg['orientation'] : 'auto';
        return $formats && $columns ? ['formats' => $formats, 'columns' => $columns, 'orientation' => $orientation] : null;
    }

    /**
     * Columnas con total al pie de la tabla ("list.totals": ["hours", "amount"]): la suma de todos los
     * registros filtrados (no solo los de la página). Solo campos numéricos que existan en SQL.
     * @return list<Field> los que puede ver el usuario actual
     */
    public function totalFields(): array
    {
        $out = [];
        foreach ((array) ($this->def['list']['totals'] ?? []) as $name) {
            $field = $this->field((string) $name);
            if (!$field instanceof \Quasar\Fields\NumberField || $field->sqlExpr() === null) {
                throw new \RuntimeException("Módulo '{$this->name}': el total de '{$field->name}' necesita un campo number o duration que exista en SQL");
            }
            if ($field->canView()) {
                $out[] = $field;
            }
        }
        return $out;
    }

    /** @return list<array{field:Field,operator:string,label:string}> */
    public function filters(): array
    {
        $out = [];
        foreach ((array) ($this->def['list']['filters'] ?? []) as $f) {
            $f = is_string($f) ? ['field' => $f] : (array) $f;
            $field = $this->field((string) ($f['field'] ?? ''));
            if ($field->sqlExpr() === null) {
                throw new \RuntimeException("Módulo '{$this->name}': no se puede filtrar por '{$field->name}' (no existe en SQL; usa \"sql\" en lugar de \"compute\")");
            }
            $op = (string) ($f['operator'] ?? $field->defaultOperator());
            if (!in_array($op, self::OPERATORS, true)) {
                throw new \RuntimeException("Módulo '{$this->name}': operador de filtro no válido '$op'");
            }
            if (!$field->canView()) {
                continue;
            }
            $out[] = [
                'field' => $field,
                'operator' => $op,
                'label' => isset($f['label']) ? $this->t((string) $f['label']) : $field->label(),
            ];
        }
        return $out;
    }

    /**
     * Filtrar con lenguaje natural ("list.ai"; además tiene que estar activado en la config 'ai'):
     *   "ai": true
     *   "ai": { "instructions": "…", "synonyms": { "status:lead": "potencial, prospecto" }, "examples": ["…"] }
     * La IA solo puede usar los filtros del panel, el buscador y el orden de las columnas.
     * @return array{instructions:?string,synonyms:array<string,string>,examples:list<string>}|null
     */
    public function aiConfig(): ?array
    {
        $cfg = $this->def['list']['ai'] ?? false;
        if ($cfg === false || $cfg === null) {
            return null;
        }
        $cfg = $cfg === true ? [] : $cfg;
        $err = fn (string $msg) => new \RuntimeException("Módulo '{$this->name}': \"list.ai\" $msg");
        if (!is_array($cfg) || array_is_list($cfg) && $cfg !== []) {
            throw $err('debe ser true o un objeto');
        }
        if (empty($this->def['list']['filters']) && empty($this->def['list']['search'])) {
            throw $err('necesita filtros ("list.filters") o búsqueda ("list.search") que pueda usar');
        }
        $synonyms = $cfg['synonyms'] ?? [];
        $examples = $cfg['examples'] ?? [];
        if (!is_array($synonyms) || array_filter($synonyms, static fn ($v) => !is_string($v))) {
            throw $err('"synonyms" debe ser un objeto {"término": "sinónimos"}');
        }
        if (!is_array($examples) || !array_is_list($examples) || array_filter($examples, static fn ($v) => !is_string($v))) {
            throw $err('"examples" debe ser una lista de frases');
        }
        return [
            'instructions' => isset($cfg['instructions']) ? (string) $cfg['instructions'] : null,
            'synonyms' => array_map('strval', $synonyms),
            'examples' => $examples,
        ];
    }

    /**
     * Conversación en la ficha de cada registro ("chat"):
     *   "chat": true
     *   "chat": { "notify": ["worker_id"],   campos con el id de un usuario: se le avisa de cada mensaje
     *             "access": ["equip"],       quién ve y escribe en la conversación (por defecto, quien ve el registro)
     *             "attachments": true,       adjuntar archivos e imágenes (por defecto true)
     *             "maxSize": "10M",          de cada adjunto
     *             "meet": true }             botón de videollamada con Google Meet (si la instalación está conectada)
     * También reciben aviso quienes ya han escrito en ella, los mencionados con @usuario y los que
     * devuelva el hook chatRecipients(). Nadie recibe aviso de un registro que no puede ver.
     * @return array{notify:list<string>,access:?Access,attachments:bool,maxSize:string,meet:bool}|null
     */
    public function chatConfig(): ?array
    {
        $cfg = $this->def['chat'] ?? false;
        if ($cfg === false || $cfg === null) {
            return null;
        }
        if ($this->isPage()) {
            throw new \RuntimeException("Módulo '{$this->name}': una página no puede tener \"chat\"");
        }
        $cfg = $cfg === true ? [] : $cfg;
        if (!is_array($cfg) || (array_is_list($cfg) && $cfg !== [])) {
            throw new \RuntimeException("Módulo '{$this->name}': \"chat\" debe ser true o un objeto");
        }
        $unknown = array_diff(array_keys($cfg), ['notify', 'access', 'attachments', 'maxSize', 'meet']);
        if ($unknown) {
            throw new \RuntimeException("Módulo '{$this->name}': clave desconocida en \"chat\": " . implode(', ', $unknown));
        }
        $notify = array_values(array_map('strval', (array) ($cfg['notify'] ?? [])));
        foreach ($notify as $name) {
            if ($this->field($name)->sqlExpr() === null) {
                throw new \RuntimeException("Módulo '{$this->name}': \"chat.notify\" usa '$name', que no existe en SQL");
            }
        }
        return [
            'notify' => $notify,
            'access' => $this->accessRule($cfg['access'] ?? null, '"chat"'),
            'attachments' => ($cfg['attachments'] ?? true) !== false,
            'maxSize' => (string) ($cfg['maxSize'] ?? '10M'),
            'meet' => !empty($cfg['meet']),
        ];
    }

    /** Si $user (por defecto el actual) puede usar la conversación de los registros que ve. */
    public function chatAllows(?User $user = null): bool
    {
        $cfg = $this->chatConfig();
        if ($cfg === null) {
            return false;
        }
        $user = func_num_args() > 0 ? $user : $this->ctx->user();
        return $cfg['access'] === null || $cfg['access']->allows($user, $this->name);
    }

    /**
     * Botón en la barra superior que abre el alta de este módulo en una ventana, desde cualquier página:
     *   "quickCreate": { "label": "quick.new_hours", "icon": "clock-plus",
     *                    "context": { "issues": "issue_id" } }   en la ficha de una incidencia, la rellena con ella
     * Solo lo ven los usuarios que pueden crear registros del módulo.
     * @return array{label:string,icon:string,context:array<string,string>}|null
     */
    public function quickCreate(): ?array
    {
        $cfg = $this->def['quickCreate'] ?? null;
        if ($cfg === null || $cfg === false) {
            return null;
        }
        $cfg = $cfg === true ? [] : $cfg;
        if (!is_array($cfg) || $this->isPage()) {
            throw new \RuntimeException("Módulo '{$this->name}': \"quickCreate\" debe ser true o un objeto (y no en una página)");
        }
        $context = (array) ($cfg['context'] ?? []);
        foreach ($context as $module => $field) {
            $this->field((string) $field);
        }
        return [
            'label' => isset($cfg['label']) ? $this->t((string) $cfg['label']) : $this->title(),
            'icon' => (string) ($cfg['icon'] ?? 'plus'),
            'context' => array_map('strval', $context),
        ];
    }

    /** Panel de filtros desplegado al entrar por primera vez ("list.filtersOpen"; por defecto plegado). */
    public function filtersOpen(): bool
    {
        return !empty($this->def['list']['filtersOpen']);
    }

    /** @return list<Field> campos de la búsqueda global (los que el usuario no puede ver no cuentan) */
    public function searchFields(): array
    {
        $fields = array_map(fn ($n) => $this->field((string) $n), (array) ($this->def['list']['search'] ?? []));
        return array_values(array_filter($fields, static fn (Field $f) => $f->isSearchable() && $f->canView()));
    }

    /** @return array{field:string,dir:string} */
    public function defaultSort(): array
    {
        $sort = (array) ($this->def['list']['defaultSort'] ?? []);
        $sortable = array_map(static fn ($c) => $c['field']->name, array_filter($this->listColumns(), static fn ($c) => $c['sortable']));
        $field = (string) ($sort['field'] ?? '');
        if (!in_array($field, $sortable, true)) {
            $field = $sortable[0] ?? '';
        }
        return ['field' => $field, 'dir' => strtolower((string) ($sort['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc'];
    }

    /** @return list<int> */
    public function pageSizes(): array
    {
        $sizes = array_values(array_filter(array_map('intval', (array) ($this->def['list']['pageSizes'] ?? [10, 25, 50, 100])), static fn ($n) => $n > 0 && $n <= 1000));
        $default = $this->pageSize();
        if (!in_array($default, $sizes, true)) {
            $sizes[] = $default;
            sort($sizes);
        }
        return $sizes;
    }

    public function pageSize(): int
    {
        $size = (int) ($this->def['list']['pageSize'] ?? 25);
        return $size > 0 && $size <= 1000 ? $size : 25;
    }

    // ---------------------------------------------------------------- botones y acciones

    /**
     * Botones propios de la barra de la tabla ("list.toolbar") que puede usar el usuario actual.
     * @return list<array{place:string,action:?string,label:string,icon:string,url:?string,confirm:?string,variant:string,download:bool,showon:?array}>
     */
    public function toolbarButtons(): array
    {
        return $this->buttons('toolbar');
    }

    /**
     * Botones de cada fila ("list.rowActions") que puede usar el usuario actual. Los que tienen
     * "showon" solo se muestran en las filas que cumplen la condición (ver DataQuery).
     * @return list<array{place:string,action:?string,label:string,icon:string,url:?string,confirm:?string,variant:string,download:bool,showon:?array}>
     */
    public function rowActions(): array
    {
        return $this->buttons('row');
    }

    /**
     * Botones propios de la ficha ("form.buttons"), solo en registros ya guardados.
     * @return list<array{place:string,action:?string,label:string,icon:string,url:?string,confirm:?string,variant:string,download:bool,showon:?array}>
     */
    public function formButtons(): array
    {
        return $this->buttons('form');
    }

    /** Botón que ejecuta la acción $name y que puede usar el usuario actual, o null. */
    public function action(string $name): ?array
    {
        foreach (array_keys(self::BUTTON_PLACES) as $place) {
            foreach ($this->buttons($place) as $b) {
                if ($b['action'] === $name) {
                    return $b;
                }
            }
        }
        return null;
    }

    /**
     * Un botón es un enlace ("url", admite {id}) o una acción ("action": método de la clase hooks
     * que se ejecuta por POST; ver Action). Opciones: label, icon, confirm (clave de idioma: pide
     * confirmación), variant (default|primary|danger), download (la acción devuelve un fichero),
     * access (ver Access) y, en filas y fichas, showon (condición sobre los valores del registro).
     */
    private function buttons(string $place): array
    {
        [$path, $allowsShowOn] = self::BUTTON_PLACES[$place];
        $defs = $this->def[$path[0]][$path[1]] ?? [];
        if (!is_array($defs) || !array_is_list($defs)) {
            throw new \RuntimeException("Módulo '{$this->name}': \"{$path[0]}.{$path[1]}\" debe ser una lista de botones");
        }
        $out = [];
        foreach ($defs as $i => $b) {
            $where = "el botón {$path[0]}.{$path[1]}[$i]";
            if (!is_array($b)) {
                throw new \RuntimeException("Módulo '{$this->name}': $where debe ser un objeto");
            }
            $action = isset($b['action']) ? (string) $b['action'] : null;
            $url = isset($b['url']) ? (string) $b['url'] : null;
            if (($action === null) === ($url === null)) {
                throw new \RuntimeException("Módulo '{$this->name}': $where necesita \"url\" o \"action\" (solo uno de los dos)");
            }
            $variant = (string) ($b['variant'] ?? 'default');
            if (!in_array($variant, self::BUTTON_VARIANTS, true)) {
                throw new \RuntimeException("Módulo '{$this->name}': \"variant\" de $where debe ser " . implode(', ', self::BUTTON_VARIANTS));
            }
            $showOn = null;
            if (isset($b['showon'])) {
                if (!$allowsShowOn) {
                    throw new \RuntimeException("Módulo '{$this->name}': $where no puede tener showon (no se refiere a ningún registro)");
                }
                try {
                    $showOn = ShowOn::parse((string) $b['showon']);
                } catch (\InvalidArgumentException $e) {
                    throw new \RuntimeException("Módulo '{$this->name}': showon de $where: " . $e->getMessage(), 0, $e);
                }
                foreach (ShowOn::fields($showOn) as $ref) {
                    $this->field($ref);
                }
            }
            $access = $this->accessRule($b['access'] ?? null, $where);
            if (!$this->allows($access)) {
                continue;
            }
            $out[] = [
                'place' => $place,
                'action' => $action,
                'label' => $this->t((string) ($b['label'] ?? $action ?? '')),
                'icon' => (string) ($b['icon'] ?? ''),
                'url' => $url,
                'confirm' => isset($b['confirm']) ? $this->t((string) $b['confirm']) : null,
                'variant' => $variant,
                'download' => !empty($b['download']),
                'showon' => $showOn,
            ];
        }
        return $out;
    }

    /** Las acciones deben existir en la clase hooks y no repetirse entre la barra y los registros. */
    private function checkButtons(): void
    {
        $reserved = array_map('strtolower', get_class_methods(ModuleHooks::class));
        $places = [];
        foreach (array_keys(self::BUTTON_PLACES) as $place) {
            [$path] = self::BUTTON_PLACES[$place];
            foreach ((array) ($this->def[$path[0]][$path[1]] ?? []) as $b) {
                $name = is_array($b) && isset($b['action']) ? (string) $b['action'] : null;
                if ($name === null) {
                    continue;
                }
                if (!preg_match('/^[a-z][A-Za-z0-9]{0,40}$/', $name) || in_array(strtolower($name), $reserved, true)) {
                    throw new \RuntimeException("Módulo '{$this->name}': nombre de acción no válido '$name' (letras y números, empezando en minúscula, y que no sea un método de ModuleHooks)");
                }
                $class = $this->hooksClass();
                if ($class === null || !method_exists($class, $name) || !(new \ReflectionMethod($class, $name))->isPublic()) {
                    throw new \RuntimeException("Módulo '{$this->name}': la acción '$name' necesita un método público $name(Action \$a) en la clase hooks del módulo");
                }
                $places[$name][$place === 'toolbar' ? 'toolbar' : 'record'] = true;
            }
        }
        foreach ($places as $name => $where) {
            if (count($where) > 1) {
                throw new \RuntimeException("Módulo '{$this->name}': la acción '$name' no puede estar a la vez en la barra y en las filas o la ficha");
            }
        }
        foreach (array_keys(self::BUTTON_PLACES) as $place) {
            $this->buttons($place);
        }
    }

    // ---------------------------------------------------------------- formulario

    /**
     * "form": {"fields": [...]} o "form": {"sections": [{"title": "...", "fields": [...], "access": [...]}]}
     * Tal como las ve el usuario actual: sin las secciones y campos que no puede ver, y en solo
     * lectura los campos que no puede modificar.
     * @return list<array{title:?string,fields:list<Field>}>
     */
    public function formSections(): array
    {
        $form = (array) ($this->def['form'] ?? []);
        $sections = $form['sections'] ?? [[
            'title' => null,
            'fields' => $form['fields'] ?? array_values(array_diff(array_keys($this->fields), [$this->primaryKey()])),
        ]];
        $out = [];
        foreach ($sections as $i => $s) {
            $fields = array_map(fn ($n) => $this->field((string) $n), (array) ($s['fields'] ?? []));
            if (!$this->allows($this->accessRule($s['access'] ?? null, "la sección $i"))) {
                continue;
            }
            $out[] = [
                'title' => isset($s['title']) ? $this->t((string) $s['title']) : null,
                'fields' => array_values(array_filter(array_map(fn (Field $f) => $this->forViewer($f), $fields))),
            ];
        }
        return $out;
    }

    /** @return list<Field> */
    public function formFields(): array
    {
        return array_merge(...array_column($this->formSections(), 'fields'));
    }
}
