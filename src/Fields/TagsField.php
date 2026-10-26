<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Lista libre de etiquetas de texto ("urgent", "export"…). Se guarda como JSON
 * en una columna JSON/TEXT.
 *
 *   "tags": {
 *     "type": "tags",
 *     "maxTags": 10,                      // por defecto 20
 *     "maxLength": 30,                    // longitud de cada etiqueta (por defecto 40)
 *     "lowercase": true,                  // normaliza a minúsculas
 *     "suggestions": ["urgent", "vip"]    // o "existing": las ya usadas en otros registros
 *     "colors": { "urgent": "red", "*": "blue" }
 *   }
 *
 * En el listado se muestra una etiqueta de color por valor; el filtro busca dentro de ellas.
 */
class TagsField extends Field
{
    public function maxTags(): int
    {
        return max(1, (int) ($this->def['maxTags'] ?? 20));
    }

    public function maxLength(): int
    {
        return max(1, (int) ($this->def['maxLength'] ?? 40));
    }

    public function suggestsExisting(): bool
    {
        return ($this->def['suggestions'] ?? null) === 'existing';
    }

    /** @return list<string> sugerencias fijas del JSON */
    public function staticSuggestions(): array
    {
        $s = $this->def['suggestions'] ?? [];
        return is_array($s) ? $this->normalize($s) : [];
    }

    public function defaultValue(): mixed
    {
        return [];
    }

    public function isSortable(): bool
    {
        return false;
    }

    protected function isEmpty(mixed $value): bool
    {
        return !is_array($value) || $value === [];
    }

    // ---------------------------------------------------------------- normalización

    /**
     * Limpia una lista de etiquetas: recorta, colapsa espacios, quita vacías y
     * duplicadas (sin distinguir mayúsculas). No corta por longitud ni número
     * (eso lo comprueba validate() para poder avisar).
     * @return list<string>
     */
    public function normalize(array $raw): array
    {
        $out = [];
        foreach ($raw as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }
            $tag = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $tag));
            $tag = trim($tag, ',');
            if ($tag === '') {
                continue;
            }
            if (!empty($this->def['lowercase'])) {
                $tag = mb_strtolower($tag);
            }
            $out[mb_strtolower($tag)] ??= $tag;
        }
        return array_values($out);
    }

    /** @return list<string> */
    public function fromRequest(array $input): mixed
    {
        $raw = $input[$this->name] ?? [];
        // Sin JavaScript llega como texto separado por comas
        $list = [];
        foreach (is_array($raw) ? $raw : [$raw] as $item) {
            if (is_scalar($item)) {
                array_push($list, ...explode(',', (string) $item));
            }
        }
        return $this->normalize($list);
    }

    public function validate(mixed $value, bool $isNew): array
    {
        $errors = parent::validate($value, $isNew);
        if ($errors || $this->isEmpty($value)) {
            return $errors;
        }
        if (count($value) > $this->maxTags()) {
            return [$this->ctx->t('validation.max_tags', ['max' => $this->maxTags()])];
        }
        foreach ($value as $tag) {
            if (mb_strlen($tag) > $this->maxLength()) {
                return [$this->ctx->t('validation.tag_length', ['max' => $this->maxLength(), 'tag' => $tag])];
            }
        }
        return [];
    }

    // ---------------------------------------------------------------- conversión

    public function toDb(mixed $value): mixed
    {
        return json_encode($this->toJson($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function toJson(mixed $value): mixed
    {
        return $this->normalize(is_array($value) ? $value : []);
    }

    /** @return list<string> */
    public function fromDb(mixed $value): mixed
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            // Compatibilidad: columnas antiguas con etiquetas separadas por comas
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }
        return $this->normalize(is_array($value) ? $value : []);
    }

    public function format(mixed $value): string
    {
        return implode(', ', $this->fromDb($value));
    }

    public function exportValue(mixed $value): mixed
    {
        return $this->format($value);
    }

    /**
     * Etiquetas para la celda del listado: una por valor, con su color.
     * @return list<array{0:string,1:string}>|null
     */
    public function cellTags(mixed $value): ?array
    {
        $tags = $this->fromDb($value);
        if (!$tags) {
            return null;
        }
        $colors = $this->colors();
        return array_map(
            static fn ($t) => [$t, $colors[$t] ?? $colors[mb_strtolower($t)] ?? $colors['*'] ?? 'gray'],
            $tags
        );
    }

    public function badgeColor(mixed $value): ?string
    {
        return null; // varias etiquetas por celda: ver cellTags()
    }

    // ---------------------------------------------------------------- filtro

    public function filterClause(mixed $value, string $operator): ?array
    {
        $col = $this->sqlExpr();
        if ($col === null || !is_string($value) || trim($value) === '') {
            return null;
        }
        // Busca dentro del JSON guardado (las etiquetas se guardan sin escapar Unicode)
        return ["$col LIKE ?", ['%' . addcslashes(trim($value), '%_\\') . '%']];
    }

    // ---------------------------------------------------------------- render

    public function render(mixed $value, array $opts = []): string
    {
        $readonly = !empty($opts['readonly']) || $this->isReadonly();
        $tags = is_array($value) ? $this->normalize($value) : $this->fromDb($value);
        return $this->ctx->render('fields/tags', [
            'field' => $this,
            'id' => $this->inputId(),
            'name' => $this->inputName(),
            'tags' => $this->cellTags($tags) ?? [],
            'readonly' => $readonly,
            'config' => [
                'name' => $this->inputName() . '[]',
                'maxTags' => $this->maxTags(),
                'maxLength' => $this->maxLength(),
                'lowercase' => !empty($this->def['lowercase']),
                'suggestions' => $this->staticSuggestions(),
                'suggestUrl' => $this->suggestsExisting()
                    ? $this->ctx->url('options', ['m' => $this->ctx->domain, 'field' => $this->path()])
                    : null,
                'colors' => $this->colors(),
                'i18n' => [
                    'remove' => $this->ctx->t('tags.remove'),
                    'maxTags' => $this->ctx->t('validation.max_tags', ['max' => $this->maxTags()]),
                    'tooLong' => $this->ctx->t('validation.tag_length', ['max' => $this->maxLength(), 'tag' => '']),
                ],
            ],
            'placeholder' => $this->ctx->t((string) ($this->def['placeholder'] ?? 'tags.placeholder')),
        ]);
    }
}
