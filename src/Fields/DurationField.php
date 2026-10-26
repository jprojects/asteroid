<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Duración (horas y minutos). Se guarda en minutos en una columna INT y se muestra como "1:30".
 * Se puede escribir "1:30", "1h30", "1h", "45m", "1,5" o "1.5" (horas con decimales).
 * Opciones: min, max (en minutos), "bare": "hours" (por defecto) | "minutes" (un número solo,
 * sin ":" ni unidad, son minutos), "decimal": true (la tabla muestra "1,50 h" en lugar de "1:30").
 * Exporta a Excel como duración real ([h]:mm): se puede sumar.
 */
final class DurationField extends NumberField
{
    public function defaultOperator(): string
    {
        return 'between';
    }

    /** Minutos a partir de un texto, o null si no es una duración válida. */
    public function parse(mixed $text): ?int
    {
        if (is_int($text)) {
            return $text;
        }
        $t = strtolower(str_replace([' ', ','], ['', '.'], trim((string) $text)));
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d{1,4}):([0-5]?\d)(?::[0-5]\d)?$/', $t, $m)) {   // 1:30 (o 01:30:00)
            return (int) $m[1] * 60 + (int) $m[2];
        }
        if (preg_match('/^(\d{1,4}(?:\.\d+)?)h(?:(\d{1,2})m?)?$/', $t, $m)) {   // 1h30, 1.5h, 2h
            return (int) round((float) $m[1] * 60) + (int) ($m[2] ?? 0);
        }
        if (preg_match('/^(\d{1,5})m(?:in)?$/', $t, $m)) {   // 45m
            return (int) $m[1];
        }
        if (preg_match('/^\d{1,4}(?:\.\d+)?$/', $t)) {
            return ($this->def['bare'] ?? 'hours') === 'minutes' ? (int) round((float) $t) : (int) round((float) $t * 60);
        }
        return null;
    }

    public function render(mixed $value, array $opts = []): string
    {
        return '<input' . $this->attrs($this->baseAttrs($opts) + [
            'type' => 'text',
            'value' => is_int($value) || is_numeric($value) ? self::hm((int) $value) : (string) ($value ?? ''),
            'inputmode' => 'text',
            'placeholder' => $this->def['placeholder'] ?? '0:00',
            'size' => 8,
        ]) . '>';
    }

    /** Entero (minutos) si es válido; si no, el texto tal cual para mostrar el error. */
    public function fromRequest(array $input): mixed
    {
        $raw = $input[$this->name] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';
        return $raw === '' ? '' : ($this->parse($raw) ?? $raw);
    }

    protected function validateValue(mixed $value): array
    {
        if (!is_int($value)) {
            return [$this->ctx->t('validation.duration')];
        }
        if (isset($this->def['min']) && $value < (int) $this->def['min']) {
            return [$this->ctx->t('validation.min', ['min' => self::hm((int) $this->def['min'])])];
        }
        if (isset($this->def['max']) && $value > (int) $this->def['max']) {
            return [$this->ctx->t('validation.max', ['max' => self::hm((int) $this->def['max'])])];
        }
        return [];
    }

    public function toDb(mixed $value): mixed
    {
        return is_int($value) ? $value : null;
    }

    public function fromDb(mixed $value): mixed
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    public function format(mixed $value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '';
        }
        if (!empty($this->def['decimal'])) {
            return number_format((int) $value / 60, 2, $this->ctx->t('format.decimal_point'), $this->ctx->t('format.thousands_sep')) . ' h';
        }
        return self::hm((int) round((float) $value));
    }

    /** "H:MM" (con signo si es negativa). */
    public static function hm(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);
        return sprintf('%s%d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    public function exportValue(mixed $value): mixed
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (float) $value / 1440;   // fracción de día
    }

    public function excelFormat(): ?string
    {
        return '[h]:mm';
    }

    public function renderFilter(mixed $value, string $operator): string
    {
        if ($operator === 'between') {
            return $this->renderRange('text', $value, ['size' => 6]);
        }
        return parent::renderFilter($value, $operator);
    }

    public function filterClause(mixed $value, string $operator): ?array
    {
        if ($operator === '=' && is_scalar($value)) {
            $minutes = $this->parse($value);
            return $minutes === null ? null : [$this->sqlExpr() . ' = ?', [$minutes]];
        }
        return parent::filterClause($value, $operator);
    }

    protected function filterBound(mixed $value, bool $upper): mixed
    {
        return is_string($value) && trim($value) !== '' ? $this->parse($value) : null;
    }

    public function describeFilter(mixed $value, string $operator): ?string
    {
        if ($operator === 'between' && is_array($value)) {
            $from = $this->filterBound($value['from'] ?? null, false);
            $to = $this->filterBound($value['to'] ?? null, true);
            if ($from === null && $to === null) {
                return null;
            }
            return ($from !== null ? self::hm($from) : '…') . ' – ' . ($to !== null ? self::hm($to) : '…');
        }
        return parent::describeFilter($value, $operator);
    }
}
