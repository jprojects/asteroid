<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Opciones: maxlength, minlength, pattern (sin anclas, como en HTML), patternMessage.
 */
class TextField extends Field
{
    protected string $inputType = 'text';

    public function render(mixed $value, array $opts = []): string
    {
        return '<input' . $this->attrs($this->baseAttrs($opts) + [
            'type' => $this->inputType,
            'value' => $value === null ? '' : (string) $value,
            'maxlength' => $this->def['maxlength'] ?? null,
            'minlength' => $this->def['minlength'] ?? null,
            'pattern' => $this->def['pattern'] ?? null,
        ]) . '>';
    }

    protected function validateValue(mixed $value): array
    {
        $errors = [];
        $len = mb_strlen((string) $value);
        if (isset($this->def['maxlength']) && $len > (int) $this->def['maxlength']) {
            $errors[] = $this->ctx->t('validation.maxlength', ['max' => $this->def['maxlength']]);
        }
        if (isset($this->def['minlength']) && $len < (int) $this->def['minlength']) {
            $errors[] = $this->ctx->t('validation.minlength', ['min' => $this->def['minlength']]);
        }
        if (isset($this->def['pattern'])) {
            $regex = '~^(?:' . str_replace('~', '\~', (string) $this->def['pattern']) . ')$~u';
            if (!preg_match($regex, (string) $value)) {
                $errors[] = $this->ctx->t((string) ($this->def['patternMessage'] ?? 'validation.pattern'));
            }
        }
        return $errors;
    }
}
