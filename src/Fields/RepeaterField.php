<?php
declare(strict_types=1);

namespace Quasar\Fields;

use Quasar\Core\FieldContext;
use Quasar\Core\ShowOn;

/**
 * Lista de filas, cada una con sus propios subcampos. Se guarda como JSON
 * en una sola columna (tipo JSON o TEXT).
 *
 *   "contacts": {
 *     "type": "repeater",
 *     "layout": "table",           // table (una línea por fila) | block (rejilla como el formulario)
 *     "min": 0, "max": 10,
 *     "addLabel": "repeater.add_contact",
 *     "summary": "name",           // subcampo que se muestra en la tabla; si no, el nº de filas
 *     "fields": {
 *       "name":  { "type": "text", "required": true },
 *       "email": { "type": "email" }
 *     }
 *   }
 *
 * Los subcampos pueden ser de cualquier tipo excepto password (también media
 * y otros repeaters). Las opciones unique/readonly no aplican dentro de un repeater.
 */
class RepeaterField extends Field
{
    /** @var array<string,Field> */
    private array $subFields = [];
    /** @var array<int,array<string,string>> errores de la última validación, por fila y subcampo */
    private array $rowErrors = [];

    public function __construct(string $name, array $def, FieldContext $ctx)
    {
        parent::__construct($name, $def, $ctx);
        foreach ((array) ($def['fields'] ?? []) as $subName => $subDef) {
            $subName = (string) $subName;
            if (!is_array($subDef) || !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $subName)) {
                throw new \InvalidArgumentException("Repeater '$name': subcampo '$subName' no válido");
            }
            if (($subDef['type'] ?? 'text') === 'password') {
                throw new \InvalidArgumentException("Repeater '$name': el tipo password no está permitido en un repeater");
            }
            $field = FieldFactory::make($subName, $subDef, $ctx);
            $field->nestUnder($this->path());
            $this->subFields[$subName] = $field;
        }
        if (!$this->subFields) {
            throw new \InvalidArgumentException("Repeater '$name': debe definir al menos un subcampo en 'fields'");
        }
        // showon dentro de un repeater: se refiere a otro subcampo de la misma fila
        foreach ($this->subFields as $sub) {
            foreach (ShowOn::fields($sub->showOn() ?? []) as $ref) {
                if (!isset($this->subFields[$ref]) || $ref === $sub->name) {
                    throw new \InvalidArgumentException("Repeater '$name': el showon de '{$sub->name}' usa '$ref', que no es otro subcampo de la fila");
                }
            }
        }
    }

    public function nestUnder(string $parentPath): void
    {
        parent::nestUnder($parentPath);
        foreach ($this->subFields as $field) {
            $field->nestUnder($this->path());
        }
    }

    /** @return array<string,Field> */
    public function subFields(): array
    {
        return $this->subFields;
    }

    public function subField(string $name): ?Field
    {
        return $this->subFields[$name] ?? null;
    }

    public function isSortable(): bool
    {
        return false;
    }

    public function defaultValue(): mixed
    {
        return [];
    }

    private function layout(): string
    {
        return ($this->def['layout'] ?? 'table') === 'block' ? 'block' : 'table';
    }

    private function minRows(): int
    {
        return max(0, (int) ($this->def['min'] ?? 0));
    }

    private function maxRows(): int
    {
        return max(0, (int) ($this->def['max'] ?? 0)); // 0 = sin límite
    }

    protected function isEmpty(mixed $value): bool
    {
        return !is_array($value) || $value === [];
    }

    // ---------------------------------------------------------------- petición / validación

    public function fromRequest(array $input): mixed
    {
        $raw = $input[$this->name] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $rows = [];
        foreach ($raw as $rowInput) {
            if (!is_array($rowInput)) {
                continue;
            }
            $row = [];
            foreach ($this->subFields as $name => $field) {
                $row[$name] = $field->fromRequest($rowInput);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public function validate(mixed $value, bool $isNew): array
    {
        $this->rowErrors = [];
        $rows = is_array($value) ? $value : [];
        $errors = [];

        if ($rows === [] && $this->isRequired($isNew)) {
            $errors[] = $this->ctx->t('validation.required');
        }
        if (count($rows) < $this->minRows()) {
            $errors[] = $this->ctx->t('validation.min_rows', ['min' => $this->minRows()]);
        }
        if ($this->maxRows() > 0 && count($rows) > $this->maxRows()) {
            $errors[] = $this->ctx->t('validation.max_rows', ['max' => $this->maxRows()]);
        }

        foreach (array_values($rows) as $i => $row) {
            $visible = ShowOn::visibility($this->subFields, $row);
            foreach ($this->subFields as $name => $field) {
                if (!$visible[$name]) {
                    continue; // oculto por showon: no se valida
                }
                $fieldErrors = $field->validate($row[$name] ?? null, $isNew);
                if ($fieldErrors) {
                    $this->rowErrors[$i][$name] = $fieldErrors[0];
                }
            }
        }
        if ($this->rowErrors) {
            $errors[] = $this->ctx->t('validation.rows');
        }
        return array_values(array_unique($errors));
    }

    // ---------------------------------------------------------------- conversión

    public function toDb(mixed $value): mixed
    {
        return json_encode($this->toJson($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function toJson(mixed $value): mixed
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $row) {
            $out = [];
            foreach ($this->subFields as $name => $field) {
                $out[$name] = $field->toJson($row[$name] ?? null);
            }
            $rows[] = $out;
        }
        return $rows;
    }

    public function fromDb(mixed $value): mixed
    {
        $rows = [];
        foreach ($this->decode($value) as $row) {
            $out = [];
            foreach ($this->subFields as $name => $field) {
                $out[$name] = $field->fromDb($row[$name] ?? null);
            }
            $rows[] = $out;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function decode(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    public function mediaIds(mixed $dbValue): array
    {
        $ids = [];
        foreach ($this->decode($dbValue) as $row) {
            foreach ($this->subFields as $name => $field) {
                array_push($ids, ...$field->mediaIds($row[$name] ?? null));
            }
        }
        return $ids;
    }

    public function format(mixed $value): string
    {
        $rows = $this->decode($value);
        $summary = $this->def['summary'] ?? null;
        if (is_string($summary) && isset($this->subFields[$summary])) {
            $field = $this->subFields[$summary];
            $parts = array_filter(array_map(static fn ($row) => $field->format($row[$summary] ?? null), $rows), 'strlen');
            $text = implode(', ', $parts);
            return mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text;
        }
        return $rows ? $this->ctx->t('repeater.count', ['n' => count($rows)]) : '';
    }

    // ---------------------------------------------------------------- render

    public function render(mixed $value, array $opts = []): string
    {
        $readonly = !empty($opts['readonly']) || $this->isReadonly();
        $isNew = !empty($opts['isNew']);
        $rows = is_array($value) ? array_values($value) : [];

        // En un alta, se precargan filas vacías hasta el mínimo
        if ($isNew && $rows === [] && !$readonly) {
            $empty = array_map(static fn (Field $f) => $f->defaultValue(), $this->subFields);
            $rows = array_fill(0, $this->minRows(), $empty);
        }

        $renderedRows = [];
        foreach ($rows as $i => $row) {
            $renderedRows[] = $this->rowItems((string) $i, is_array($row) ? $row : [], $this->rowErrors[$i] ?? []);
        }
        $placeholder = '__' . strtoupper((string) preg_replace('/\W+/', '_', $this->path())) . '_I__';
        $defaults = array_map(static fn (Field $f) => $f->defaultValue(), $this->subFields);

        return $this->ctx->render('fields/repeater', [
            'field' => $this,
            'id' => $this->inputId(),
            'name' => $this->inputName(),
            'layout' => $this->layout(),
            'headers' => array_map(static fn (Field $f) => [
                'label' => $f->label(),
                'width' => $f->width(),
                'required' => $f->isRequired($isNew),
                'hidden' => $f->isHidden(),
            ], $this->subFields),
            'rows' => $renderedRows,
            'template' => $readonly ? null : $this->rowItems($placeholder, $defaults, []),
            'placeholder' => $placeholder,
            'min' => $this->minRows(),
            'max' => $this->maxRows(),
            'readonly' => $readonly,
            'opts' => ['isNew' => $isNew, 'readonly' => $readonly],
            'addLabel' => $this->ctx->t((string) ($this->def['addLabel'] ?? 'repeater.add')),
        ]);
    }

    /**
     * Subcampos de una fila listos para pintar, con su name/id HTML propio.
     * @return list<array{field:Field,value:mixed,error:?string,visible:bool}>
     */
    private function rowItems(string $index, array $row, array $errors): array
    {
        $items = [];
        $visible = ShowOn::visibility($this->subFields, $row);
        foreach ($this->subFields as $name => $field) {
            $items[] = [
                'visible' => $visible[$name],
                'field' => $field->withInput(
                    $this->inputName() . "[$index][$name]",
                    $this->inputId() . "_{$index}_{$name}"
                ),
                'value' => $row[$name] ?? null,
                'error' => $errors[$name] ?? null,
            ];
        }
        return $items;
    }
}
