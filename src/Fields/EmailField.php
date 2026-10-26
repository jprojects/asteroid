<?php
declare(strict_types=1);

namespace Quasar\Fields;

class EmailField extends TextField
{
    protected string $inputType = 'email';

    protected function validateValue(mixed $value): array
    {
        $errors = parent::validateValue($value);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = $this->ctx->t('validation.email');
        }
        return $errors;
    }
}
