<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Opciones: decimals (0 = entero), min, max, step, grouping (separador de miles, por defecto true).
 */
class NumberField extends Field
{
    public function align(): string
    {
        return 'right';
    }

    public function defaultOperator(): string
    {
        return '=';
    }

    private function decimals(): int
    {
        return max(0, (int) ($this->def['decimals'] ?? 0));
    }

    public function render(mixed $value, array $opts = []): string
    {
        $dec = $this->decimals();
        return '<input' . $this->attrs($this->baseAttrs($opts) + [
            'type' => 'number',
            'value' => $value === null ? '' : (string) $value,
            'min' => $this->def['min'] ?? null,
            'max' => $this->def['max'] ?? null,
            'step' => $this->def['step'] ?? ($dec > 0 ? '0.' . str_repeat('0', $dec - 1) . '1' : '1'),
            'inputmode' => $dec > 0 ? 'decimal' : 'numeric',
        ]) . '>';
    }

    public function fromRequest(array $input): mixed
    {
        $value = parent::fromRequest($input);
        return str_replace(',', '.', (string) $value);
    }

    protected function validateValue(mixed $value): array
    {
        if (!is_numeric($value)) {
            return [$this->ctx->t('validation.number')];
        }
        if ($this->decimals() === 0 && !preg_match('/^-?\d+$/', (string) $value)) {
            return [$this->ctx->t('validation.integer')];
        }
        $errors = [];
        if (isset($this->def['min']) && (float) $value < (float) $this->def['min']) {
            $errors[] = $this->ctx->t('validation.min', ['min' => $this->def['min']]);
        }
        if (isset($this->def['max']) && (float) $value > (float) $this->def['max']) {
            $errors[] = $this->ctx->t('validation.max', ['max' => $this->def['max']]);
        }
        return $errors;
    }

    public function toDb(mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $dec = $this->decimals();
        return $dec > 0 ? number_format((float) $value, $dec, '.', '') : (int) $value;
    }

    public function exportValue(mixed $value): mixed
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return $this->decimals() > 0 ? (float) $value : (int) $value;
    }

    public function excelFormat(): ?string
    {
        $grouping = ($this->def['grouping'] ?? true) !== false;
        $dec = $this->decimals();
        return ($grouping ? '#,##0' : '0') . ($dec > 0 ? '.' . str_repeat('0', $dec) : '');
    }

    public function format(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $grouping = ($this->def['grouping'] ?? true) !== false;
        return number_format(
            (float) str_replace(',', '.', (string) $value),
            $this->decimals(),
            $this->ctx->t('format.decimal_point'),
            $grouping ? $this->ctx->t('format.thousands_sep') : ''
        );
    }

    public function renderFilter(mixed $value, string $operator): string
    {
        if ($operator === 'between') {
            return $this->renderRange('number', $value, ['step' => 'any']);
        }
        return '<input' . $this->attrs([
            'type' => 'number',
            'step' => 'any',
            'name' => "f[{$this->name}]",
            'value' => is_scalar($value) ? (string) $value : '',
            'class' => 'qs-input',
        ]) . '>';
    }

    public function filterClause(mixed $value, string $operator): ?array
    {
        if ($operator === '=' && is_scalar($value) && !is_numeric(str_replace(',', '.', (string) $value))) {
            return null;
        }
        if ($operator === 'like') {
            $operator = '=';
        }
        return parent::filterClause($value, $operator);
    }

    protected function filterBound(mixed $value, bool $upper): mixed
    {
        $value = is_string($value) ? str_replace(',', '.', trim($value)) : null;
        return is_numeric($value) ? $value : null;
    }
}
