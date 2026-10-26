<?php
declare(strict_types=1);

namespace Quasar\Fields;

use Quasar\Core\Access;
use Quasar\Core\Database;
use Quasar\Core\FieldContext;

/**
 * Clase base de todos los tipos de campo.
 *
 * Un campo sabe: pintar su control de formulario y de filtro, leer y validar
 * el valor de la petición, convertirlo para BD, formatearlo para la tabla y
 * construir su condición SQL de filtrado. La plantilla solo decide la maquetación
 * (etiqueta, errores, rejilla) alrededor del control.
 *
 * Opciones comunes del JSON: type, label, help, column, required, requiredOnCreate,
 * readonly, unique, default, width (full|half|third|quarter|two-thirds), placeholder,
 * autocomplete, virtual.
 *
 * Acceso por grupos (ver Quasar\Core\Access):
 *   "access": ["direccion"]       solo esos grupos ven el campo (formulario, tabla, filtros, exportación)
 *   "editAccess": ["direccion"]   el resto lo ve en solo lectura
 *
 * Campos calculados (no se guardan; solo lectura; se formatean según su "type"):
 *   "sql": "expresión SQL"      se calcula en la consulta: ordenable, filtrable y buscable
 *   "compute": "metodo"         método PHP de la clase hooks del módulo (o "Clase::metodo" estático)
 *                               que recibe la fila completa (array) y devuelve el valor
 */
abstract class Field
{
    /** Nombre del input HTML si difiere del nombre del campo (p. ej. dentro de un repeater) */
    private ?string $inputName = null;
    private ?string $inputIdOverride = null;
    /** Prefijo de ruta cuando el campo está anidado: "contacts." */
    private string $pathPrefix = '';
    /** @var array|false|null condición showon ya analizada (false = sin condición) */
    private array|false|null $showOn = null;
    /** @var array<string,Access|false> reglas "access" y "editAccess" ya analizadas (false = sin regla) */
    private array $access = [];
    /** Solo lectura impuesta desde fuera (el usuario no puede modificarlo: "editAccess") */
    private bool $forcedReadonly = false;

    public function __construct(
        public readonly string $name,
        protected readonly array $def,
        protected readonly FieldContext $ctx,
    ) {
    }

    // ---------------------------------------------------------------- anidamiento

    /** Copia del campo que se pinta con otro name/id HTML (usado por RepeaterField). */
    public function withInput(string $inputName, string $inputId): static
    {
        $clone = clone $this;
        $clone->inputName = $inputName;
        $clone->inputIdOverride = $inputId;
        return $clone;
    }

    public function inputName(): string
    {
        return $this->inputName ?? $this->name;
    }

    /** Ruta completa del campo dentro del módulo: "photos" o "contacts.photo". */
    public function path(): string
    {
        return $this->pathPrefix . $this->name;
    }

    public function nestUnder(string $parentPath): void
    {
        $this->pathPrefix = $parentPath . '.';
    }

    // ---------------------------------------------------------------- metadatos

    public function type(): string
    {
        return (string) ($this->def['type'] ?? 'text');
    }

    public function column(): string
    {
        return (string) ($this->def['column'] ?? $this->name);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->def[$key] ?? $default;
    }

    public function label(): string
    {
        return $this->ctx->t((string) ($this->def['label'] ?? 'fields.' . $this->name));
    }

    public function help(): ?string
    {
        return isset($this->def['help']) ? $this->ctx->t((string) $this->def['help']) : null;
    }

    public function width(): string
    {
        $w = (string) ($this->def['width'] ?? 'full');
        return in_array($w, ['full', 'half', 'third', 'quarter', 'two-thirds'], true) ? $w : 'full';
    }

    public function align(): string
    {
        return 'left';
    }

    public function inputId(): string
    {
        return $this->inputIdOverride ?? 'f_' . $this->name;
    }

    public function isHidden(): bool
    {
        return false;
    }

    public function isReadonly(): bool
    {
        return !empty($this->def['readonly']) || $this->isComputed() || $this->forcedReadonly;
    }

    /** Copia del campo en solo lectura (no se valida ni se guarda). */
    public function asReadonly(): static
    {
        $clone = clone $this;
        $clone->forcedReadonly = true;
        return $clone;
    }

    // ---------------------------------------------------------------- acceso por grupos

    /**
     * Regla "access" (quién ve el campo) o "editAccess" (quién lo modifica); null = todos.
     * @param 'access'|'editAccess' $key
     * @throws \InvalidArgumentException si está mal escrita
     */
    public function accessRule(string $key = 'access'): ?Access
    {
        if (!isset($this->access[$key])) {
            $this->access[$key] = Access::parse($this->def[$key] ?? null) ?? false;
        }
        return $this->access[$key] ?: null;
    }

    /** Si el usuario actual puede ver el campo. */
    public function canView(): bool
    {
        return $this->accessRule('access')?->allows($this->ctx->user(), $this->ctx->domain) ?? true;
    }

    /** Si el usuario actual puede modificarlo (además de verlo). */
    public function canEdit(): bool
    {
        return $this->canView() && ($this->accessRule('editAccess')?->allows($this->ctx->user(), $this->ctx->domain) ?? true);
    }

    /**
     * Condición "showon" analizada (ver Quasar\Core\ShowOn), o null si el campo se muestra siempre.
     * @return list<list<array{field:string,not:bool,values:list<string>}>>|null
     */
    public function showOn(): ?array
    {
        if ($this->showOn === null) {
            $expr = $this->def['showon'] ?? $this->def['showOn'] ?? null;
            $this->showOn = is_string($expr) && trim($expr) !== '' ? \Quasar\Core\ShowOn::parse($expr) : false;
        }
        return $this->showOn ?: null;
    }

    /** Valor calculado con "sql" o "compute" en lugar de leerse de una columna. */
    public function isComputed(): bool
    {
        return isset($this->def['sql']) || isset($this->def['compute']);
    }

    /** Nombre del cálculo PHP ("metodo" o "Clase::metodo"), o null. */
    public function computeFunction(): ?string
    {
        return isset($this->def['compute']) ? (string) $this->def['compute'] : null;
    }

    /**
     * Expresión SQL que representa el campo en SELECT, WHERE y ORDER BY;
     * null si el campo no existe en la consulta (virtual o calculado en PHP).
     * La expresión "sql" viene del JSON del módulo (configuración del desarrollador,
     * nunca de datos del usuario) y se inserta tal cual.
     */
    public function sqlExpr(): ?string
    {
        if (isset($this->def['sql'])) {
            return '(' . $this->def['sql'] . ')';
        }
        return $this->isStored() ? Database::ident($this->column()) : null;
    }

    public function isRequired(bool $isNew): bool
    {
        return !empty($this->def['required']) || ($isNew && !empty($this->def['requiredOnCreate']));
    }

    public function isUnique(): bool
    {
        return !empty($this->def['unique']);
    }

    /** Si el valor vive en una columna propia de la tabla (no virtual ni calculado). */
    public function isStored(): bool
    {
        return empty($this->def['virtual']) && !$this->isComputed();
    }

    public function isSortable(): bool
    {
        return $this->sqlExpr() !== null;
    }

    public function isSearchable(): bool
    {
        return $this->sqlExpr() !== null;
    }

    public function defaultValue(): mixed
    {
        return $this->def['default'] ?? null;
    }

    /** Operador de filtro si el JSON no indica ninguno: like | = | between */
    public function defaultOperator(): string
    {
        return 'like';
    }

    // ---------------------------------------------------------------- formulario

    /**
     * HTML del control.
     * @param array{isNew?:bool,readonly?:bool} $opts
     */
    abstract public function render(mixed $value, array $opts = []): string;

    /** Valor tal como viene en la petición, normalizado. */
    public function fromRequest(array $input): mixed
    {
        $value = $input[$this->name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** @return list<string> mensajes de error ya traducidos */
    public function validate(mixed $value, bool $isNew): array
    {
        if ($this->isEmpty($value)) {
            return $this->isRequired($isNew) ? [$this->ctx->t('validation.required')] : [];
        }
        return $this->validateValue($value);
    }

    /** Validación específica del tipo; solo se llama con valores no vacíos. */
    protected function validateValue(mixed $value): array
    {
        return [];
    }

    protected function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /** Si el valor debe escribirse en BD (p. ej. contraseña vacía en edición = no tocar). */
    public function shouldSave(mixed $value, bool $isNew): bool
    {
        return $this->isStored() && !$this->isReadonly();
    }

    /** Valor de formulario -> valor de BD. */
    public function toDb(mixed $value): mixed
    {
        return $value;
    }

    /** Valor de BD -> valor para el formulario. */
    public function fromDb(mixed $value): mixed
    {
        return $value;
    }

    /** Valor a guardar cuando el campo va dentro de un JSON (repeater). */
    public function toJson(mixed $value): mixed
    {
        return $this->toDb($value);
    }

    /**
     * IDs de la tabla media referenciados por el valor guardado en BD.
     * Lo usa la limpieza de archivos huérfanos.
     * @return list<int>
     */
    public function mediaIds(mixed $dbValue): array
    {
        return [];
    }

    /**
     * Expresión regular (sintaxis de MySQL) que encuentra el archivo $id en el valor de la columna
     * leído como texto. La usa el "scope" para saber si un archivo está en un registro visible.
     */
    public function mediaPattern(int $id): string
    {
        return '(^|[^0-9])' . $id . '([^0-9]|$)';
    }

    // ---------------------------------------------------------------- tabla

    /** Texto plano para mostrar en una celda de la tabla. */
    public function format(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * Texto completo, sin recortar y con sus saltos de línea: celdas con "overflow": "wrap"
     * y detalle desplegable de las filas. Por defecto, el mismo que format().
     */
    public function fullText(mixed $value): string
    {
        return $this->format($value);
    }

    // ---------------------------------------------------------------- etiquetas de color

    /** Colores con nombre de la paleta (template: .qs-tone-{nombre}); también se admite "#rrggbb". */
    public const TONES = ['gray', 'blue', 'green', 'amber', 'orange', 'red', 'purple', 'pink', 'cyan', 'teal'];

    /**
     * "colors": {"valor": "color", …, "*": "color para el resto"} validado.
     * @return array<string,string>
     */
    public function colors(): array
    {
        $colors = $this->def['colors'] ?? [];
        if (!is_array($colors)) {
            throw new \InvalidArgumentException("Campo '{$this->name}': \"colors\" debe ser un objeto {valor: color}");
        }
        $out = [];
        foreach ($colors as $value => $tone) {
            $tone = is_string($tone) ? strtolower(trim($tone)) : '';
            if (!in_array($tone, self::TONES, true) && !preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $tone)) {
                throw new \InvalidArgumentException("Campo '{$this->name}': color no válido '$tone' (usa " . implode(', ', self::TONES) . ' o #rrggbb)');
            }
            $out[(string) $value] = $tone;
        }
        return $out;
    }

    /**
     * Varias etiquetas en una celda del listado ([texto, color] por cada una), o null.
     * Lo usan los campos con varios valores (tags).
     * @return list<array{0:string,1:string}>|null
     */
    public function cellTags(mixed $value): ?array
    {
        return null;
    }

    /** Color de etiqueta para un valor de BD, o null si se muestra como texto normal. */
    public function badgeColor(mixed $value): ?string
    {
        $colors = $this->colors();
        if (!$colors || $value === null || $value === '' || is_array($value)) {
            return null;
        }
        return $colors[(string) $value] ?? $colors['*'] ?? null;
    }

    // ---------------------------------------------------------------- exportación

    /**
     * Valor para una celda de Excel a partir del valor de BD: string, int, float,
     * DateTimeInterface o null. Por defecto, el mismo texto que en la tabla.
     */
    public function exportValue(mixed $value): mixed
    {
        return $this->format($value);
    }

    /** Formato de número de Excel para la columna (null = general). */
    public function excelFormat(): ?string
    {
        return null;
    }

    /** Texto legible de un filtro activo (para la cabecera de las exportaciones); null si no aplica. */
    public function describeFilter(mixed $value, string $operator): ?string
    {
        if ($this->filterClause($value, $operator) === null) {
            return null;
        }
        if ($operator === 'between') {
            $from = is_scalar($value['from'] ?? null) && trim((string) $value['from']) !== '' ? $this->format(trim((string) $value['from'])) : '…';
            $to = is_scalar($value['to'] ?? null) && trim((string) $value['to']) !== '' ? $this->format(trim((string) $value['to'])) : '…';
            return "$from – $to";
        }
        if (is_array($value)) {
            // Varios valores (filtro "=" de una lista): "España, Portugal"
            return implode(', ', array_map(fn ($v) => $this->format(trim((string) $v)), self::filterValues($value)));
        }
        return $operator === 'like' ? trim((string) $value) : $this->format(trim((string) $value));
    }

    /**
     * Valores no vacíos de un filtro "=" con varios valores (f[campo][]=a&f[campo][]=b).
     * @return list<string>
     */
    public static function filterValues(mixed $value): array
    {
        if (!is_array($value)) {
            return is_scalar($value) && trim((string) $value) !== '' ? [trim((string) $value)] : [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_scalar($v) && ($v = trim((string) $v)) !== '') {
                $out[$v] = true;
            }
        }
        return array_map('strval', array_keys($out));
    }

    public function renderFilter(mixed $value, string $operator): string
    {
        if ($operator === 'between') {
            return $this->renderRange('text', $value);
        }
        return '<input' . $this->attrs([
            'type' => 'search',
            'name' => "f[{$this->name}]",
            'value' => is_scalar($value) ? (string) $value : '',
            'class' => 'qs-input',
        ]) . '>';
    }

    /**
     * Condición SQL para el filtro.
     * @return array{0:string,1:list<mixed>}|null
     */
    public function filterClause(mixed $value, string $operator): ?array
    {
        $col = $this->sqlExpr();
        if ($col === null) {
            return null;
        }
        switch ($operator) {
            case 'like':
                if (!is_string($value) || trim($value) === '') {
                    return null;
                }
                return ["$col LIKE ?", ['%' . addcslashes(trim($value), '%_\\') . '%']];
            case '=':
                // Uno o varios valores (cualquiera de ellos)
                $values = self::filterValues($value);
                if (!$values) {
                    return null;
                }
                return count($values) === 1
                    ? ["$col = ?", $values]
                    : ["$col IN (" . implode(', ', array_fill(0, count($values), '?')) . ')', $values];
            case 'between':
                if (!is_array($value)) {
                    return null;
                }
                $parts = [];
                $params = [];
                $from = $this->filterBound($value['from'] ?? null, false);
                $to = $this->filterBound($value['to'] ?? null, true);
                if ($from !== null) {
                    $parts[] = "$col >= ?";
                    $params[] = $from;
                }
                if ($to !== null) {
                    $parts[] = "$col <= ?";
                    $params[] = $to;
                }
                return $parts ? [implode(' AND ', $parts), $params] : null;
        }
        return null;
    }

    /** Normaliza un extremo de un filtro "between"; null = ignorar. */
    protected function filterBound(mixed $value, bool $upper): mixed
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    // ---------------------------------------------------------------- utilidades HTML

    protected function baseAttrs(array $opts): array
    {
        $readonly = !empty($opts['readonly']) || $this->isReadonly();
        $placeholder = $this->def['placeholder'] ?? null;
        return [
            'id' => $this->inputId(),
            'name' => $this->inputName(),
            'class' => 'qs-input',
            'required' => !$readonly && $this->isRequired(!empty($opts['isNew'])),
            'readonly' => $readonly,
            'placeholder' => $placeholder !== null ? $this->ctx->t((string) $placeholder) : null,
            'autocomplete' => $this->def['autocomplete'] ?? null,
        ];
    }

    protected function renderRange(string $type, mixed $value, array $extra = []): string
    {
        $value = is_array($value) ? $value : [];
        $html = '';
        foreach (['from', 'to'] as $bound) {
            $html .= '<input' . $this->attrs($extra + [
                'type' => $type,
                'name' => "f[{$this->name}][$bound]",
                'value' => is_scalar($value[$bound] ?? null) ? (string) $value[$bound] : '',
                'class' => 'qs-input',
                'title' => $this->ctx->t('filter.' . $bound),
                'placeholder' => $this->ctx->t('filter.' . $bound),
            ]) . '>';
        }
        return '<span class="qs-range">' . $html . '</span>';
    }

    /** Construye atributos HTML escapados. true = atributo booleano, null/false = omitido. */
    protected function attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === false) {
                continue;
            }
            $out .= $v === true ? ' ' . $k : ' ' . $k . '="' . self::e((string) $v) . '"';
        }
        return $out;
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
