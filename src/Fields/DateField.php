<?php
declare(strict_types=1);

namespace Quasar\Fields;

use DateTimeImmutable;

/**
 * Fecha (columna DATE). Formato de visualización en lang common: format.date
 * Opciones: min, max (Y-m-d), "default": "today" (la fecha del día en las altas; en datetime, "now").
 */
class DateField extends Field
{
    protected string $inputType = 'date';
    /** @var list<string> formatos aceptados desde el navegador */
    protected array $inputFormats = ['Y-m-d'];
    protected string $inputFormat = 'Y-m-d';
    protected string $dbFormat = 'Y-m-d';
    protected string $displayKey = 'format.date';

    public function defaultOperator(): string
    {
        return 'between';
    }

    public function defaultValue(): mixed
    {
        $default = parent::defaultValue();
        return $default === 'today' || $default === 'now' ? date($this->dbFormat) : $default;
    }

    public function render(mixed $value, array $opts = []): string
    {
        return '<input' . $this->attrs($this->baseAttrs($opts) + [
            'type' => $this->inputType,
            'value' => $this->toInput($value),
            'min' => $this->def['min'] ?? null,
            'max' => $this->def['max'] ?? null,
        ]) . '>';
    }

    protected function toInput(mixed $value): string
    {
        $date = $this->parseDb($value);
        return $date ? $date->format($this->inputFormat) : (is_string($value) ? $value : '');
    }

    protected function parseDb(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    protected function parseInput(string $value): ?DateTimeImmutable
    {
        foreach ($this->inputFormats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date && $date->format($format) === $value) {
                return $date;
            }
        }
        return null;
    }

    protected function validateValue(mixed $value): array
    {
        $date = $this->parseInput((string) $value);
        if ($date === null) {
            return [$this->ctx->t('validation.date')];
        }
        $errors = [];
        if (isset($this->def['min']) && $date->format('Y-m-d') < $this->def['min']) {
            $errors[] = $this->ctx->t('validation.min', ['min' => $this->format($this->def['min'])]);
        }
        if (isset($this->def['max']) && $date->format('Y-m-d') > $this->def['max']) {
            $errors[] = $this->ctx->t('validation.max', ['max' => $this->format($this->def['max'])]);
        }
        return $errors;
    }

    public function toDb(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        return $this->parseInput((string) $value)?->format($this->dbFormat);
    }

    public function format(mixed $value): string
    {
        $date = $this->parseDb($value);
        return $date ? $date->format($this->ctx->t($this->displayKey)) : '';
    }

    public function exportValue(mixed $value): mixed
    {
        return $this->parseDb($value);
    }

    public function excelFormat(): ?string
    {
        return $this->ctx->t($this->displayKey === 'format.date' ? 'format.excel_date' : 'format.excel_datetime');
    }

    public function renderFilter(mixed $value, string $operator): string
    {
        if ($operator === 'between') {
            return $this->renderRange('date', $value);
        }
        return '<input' . $this->attrs([
            'type' => 'date',
            'name' => "f[{$this->name}]",
            'value' => is_string($value) ? $value : '',
            'class' => 'qs-input',
        ]) . '>';
    }

    public function filterClause(mixed $value, string $operator): ?array
    {
        if ($operator === '=') {
            // Filtro de día exacto (también válido para columnas DATETIME)
            return parent::filterClause(['from' => $value, 'to' => $value], 'between');
        }
        return parent::filterClause($value, $operator === 'like' ? 'between' : $operator);
    }

    protected function filterBound(mixed $value, bool $upper): mixed
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        // createFromFormat acepta 2026-13-45 desbordando al mes siguiente: solo fechas reales
        if (!$date || $date->format('Y-m-d') !== trim($value)) {
            return null;
        }
        return $date->format('Y-m-d') . ($upper ? ' 23:59:59' : ' 00:00:00');
    }
}
