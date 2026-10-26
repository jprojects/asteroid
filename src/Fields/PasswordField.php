<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Nunca muestra el valor guardado. Si se deja vacío al editar, no se modifica.
 * Se guarda como hash (password_hash). Por defecto minlength = 8.
 */
class PasswordField extends TextField
{
    protected string $inputType = 'password';

    public function render(mixed $value, array $opts = []): string
    {
        $attrs = $this->baseAttrs($opts) + [
            'type' => 'password',
            'value' => '',
            'minlength' => $this->minLength(),
            'maxlength' => $this->def['maxlength'] ?? 255,
        ];
        $attrs['autocomplete'] = 'new-password';
        if (empty($opts['isNew'])) {
            $attrs['placeholder'] = $this->ctx->t('password.keep');
        }
        return '<input' . $this->attrs($attrs) . '>';
    }

    protected function validateValue(mixed $value): array
    {
        if (mb_strlen((string) $value) < $this->minLength()) {
            return [$this->ctx->t('validation.minlength', ['min' => $this->minLength()])];
        }
        return [];
    }

    private function minLength(): int
    {
        return (int) ($this->def['minlength'] ?? 8);
    }

    public function fromRequest(array $input): mixed
    {
        // Sin trim: los espacios forman parte de la contraseña
        $value = $input[$this->name] ?? '';
        return is_string($value) ? $value : '';
    }

    public function shouldSave(mixed $value, bool $isNew): bool
    {
        return parent::shouldSave($value, $isNew) && $value !== '';
    }

    public function toDb(mixed $value): mixed
    {
        return password_hash((string) $value, PASSWORD_DEFAULT);
    }

    public function fromDb(mixed $value): mixed
    {
        return '';
    }

    public function format(mixed $value): string
    {
        return '';
    }

    public function isSortable(): bool
    {
        return false;
    }

    public function isSearchable(): bool
    {
        return false;
    }
}
