<?php
declare(strict_types=1);

namespace Quasar\Fields;

class HiddenField extends Field
{
    public function isHidden(): bool
    {
        return true;
    }

    public function render(mixed $value, array $opts = []): string
    {
        return '<input' . $this->attrs([
            'type' => 'hidden',
            'id' => $this->inputId(),
            'name' => $this->inputName(),
            'value' => $value === null ? '' : (string) $value,
        ]) . '>';
    }
}
