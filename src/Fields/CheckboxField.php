<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Booleano guardado como 0/1. "required": true obliga a marcarlo.
 */
class CheckboxField extends Field
{
    public function align(): string
    {
        return 'center';
    }

    public function defaultOperator(): string
    {
        return '=';
    }

    public function defaultValue(): mixed
    {
        return (int) ($this->def['default'] ?? 0);
    }

    public function render(mixed $value, array $opts = []): string
    {
        $attrs = $this->baseAttrs($opts);
        $readonly = $attrs['readonly'];
        unset($attrs['readonly'], $attrs['placeholder'], $attrs['autocomplete']);
        $attrs['class'] = 'qs-input qs-checkbox';
        $attrs['disabled'] = $readonly;

        return '<input type="hidden" name="' . self::e($this->inputName()) . '" value="0">'
            . '<input' . $this->attrs($attrs + [
                'type' => 'checkbox',
                'value' => '1',
                'checked' => (bool) $value,
            ]) . '>';
    }

    public function fromRequest(array $input): mixed
    {
        return in_array($input[$this->name] ?? '0', ['1', 'on', 'true'], true) ? 1 : 0;
    }

    public function validate(mixed $value, bool $isNew): array
    {
        return $this->isRequired($isNew) && $value !== 1 ? [$this->ctx->t('validation.required')] : [];
    }

    public function fromDb(mixed $value): mixed
    {
        return (int) $value;
    }

    public function format(mixed $value): string
    {
        return $this->ctx->t((int) $value ? 'yes' : 'no');
    }

    public function renderFilter(mixed $value, string $operator): string
    {
        $current = is_scalar($value) ? (string) $value : '';
        $html = '<select' . $this->attrs(['name' => "f[{$this->name}]", 'class' => 'qs-input']) . '>';
        foreach (['' => 'filter.all', '1' => 'yes', '0' => 'no'] as $v => $key) {
            $v = (string) $v;
            $html .= '<option value="' . $v . '"' . ($v === $current ? ' selected' : '') . '>'
                . self::e($this->ctx->t($key)) . '</option>';
        }
        return $html . '</select>';
    }

    public function filterClause(mixed $value, string $operator): ?array
    {
        if ($value !== '0' && $value !== '1') {
            return null;
        }
        return parent::filterClause($value, '=');
    }
}
