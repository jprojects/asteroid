<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Opciones: rows (por defecto 4), maxlength, minlength, listLength (recorte en la tabla, por defecto 80;
 * no se aplica en columnas "overflow": "wrap", en el detalle desplegable ni en las exportaciones).
 */
class TextareaField extends TextField
{
    public function render(mixed $value, array $opts = []): string
    {
        return '<textarea' . $this->attrs($this->baseAttrs($opts) + [
            'rows' => $this->def['rows'] ?? 4,
            'maxlength' => $this->def['maxlength'] ?? null,
            'minlength' => $this->def['minlength'] ?? null,
        ]) . '>' . self::e($value === null ? '' : (string) $value) . '</textarea>';
    }

    public function format(mixed $value): string
    {
        $text = preg_replace('/\s+/u', ' ', (string) $value) ?? '';
        $max = (int) ($this->def['listLength'] ?? 80);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    public function fullText(mixed $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    }

    public function exportValue(mixed $value): mixed
    {
        return $this->fullText($value);
    }
}
