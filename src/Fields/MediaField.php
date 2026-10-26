<?php
declare(strict_types=1);

namespace Quasar\Fields;

use Quasar\Core\MediaStorage;

/**
 * Subida de imágenes y documentos con zona de arrastre (dropzone).
 *
 *   "photos": {
 *     "type": "media",
 *     "multiple": true,               // false = un solo archivo (columna INT); true = lista (columna JSON/TEXT)
 *     "maxFiles": 10,
 *     "maxSize": "5M",                // por archivo; por defecto 10M
 *     "accept": ["image/*", ".pdf"]   // MIME, MIME/* o extensiones; por defecto todos los tipos permitidos
 *   }
 *
 * En memoria el valor siempre es una lista de ids de la tabla media.
 */
class MediaField extends Field
{
    public function multiple(): bool
    {
        return !empty($this->def['multiple']);
    }

    public function maxFiles(): int
    {
        return $this->multiple() ? max(1, (int) ($this->def['maxFiles'] ?? 20)) : 1;
    }

    public function maxSize(): int
    {
        return MediaStorage::parseSize($this->def['maxSize'] ?? '10M');
    }

    /** @return list<string> */
    public function accept(): array
    {
        return array_values(array_map('strval', (array) ($this->def['accept'] ?? [])));
    }

    public function accepts(string $ext, string $mime): bool
    {
        $accept = $this->accept();
        if (!$accept) {
            return true;
        }
        foreach ($accept as $rule) {
            $rule = strtolower(trim($rule));
            if ($rule === '.' . $ext || $rule === $mime
                || (str_ends_with($rule, '/*') && str_starts_with($mime, substr($rule, 0, -1)))) {
                return true;
            }
        }
        return false;
    }

    public function isSortable(): bool
    {
        return false;
    }

    public function isSearchable(): bool
    {
        return false;
    }

    public function defaultValue(): mixed
    {
        return [];
    }

    protected function isEmpty(mixed $value): bool
    {
        return !is_array($value) || $value === [];
    }

    // ---------------------------------------------------------------- petición / validación

    /** @return list<int> */
    public function fromRequest(array $input): mixed
    {
        return self::ids($input[$this->name] ?? null);
    }

    public function validate(mixed $value, bool $isNew): array
    {
        $errors = parent::validate($value, $isNew);
        if ($errors || $this->isEmpty($value)) {
            return $errors;
        }
        if (count($value) > $this->maxFiles()) {
            return [$this->ctx->t('validation.max_files', ['max' => $this->maxFiles()])];
        }
        // Solo se aceptan archivos subidos para este mismo módulo y campo
        if ($this->ctx->media()->countOwned($value, $this->ctx->domain, $this->path()) !== count($value)) {
            return [$this->ctx->t('validation.media')];
        }
        return [];
    }

    // ---------------------------------------------------------------- conversión

    public function toDb(mixed $value): mixed
    {
        $ids = self::ids($value);
        if ($this->multiple()) {
            return json_encode($ids, JSON_THROW_ON_ERROR);
        }
        return $ids[0] ?? null;
    }

    public function toJson(mixed $value): mixed
    {
        $ids = self::ids($value);
        return $this->multiple() ? $ids : ($ids[0] ?? null);
    }

    public function fromDb(mixed $value): mixed
    {
        return self::ids($value);
    }

    public function mediaIds(mixed $dbValue): array
    {
        return self::ids($dbValue);
    }

    /**
     * Normaliza cualquier representación (int, "5", "[1,2]", [1,"2"]) a lista de ids únicos.
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $value = json_decode($value, true) ?? [];
        }
        $list = is_array($value) ? $value : [$value];
        $ids = [];
        foreach ($list as $v) {
            if ((is_int($v) && $v > 0) || (is_string($v) && ctype_digit($v) && (int) $v > 0)) {
                $ids[(int) $v] = (int) $v;
            }
        }
        return array_values($ids);
    }

    public function format(mixed $value): string
    {
        $n = count(self::ids($value));
        return $n ? $this->ctx->t('media.count', ['n' => $n]) : '';
    }

    public function renderFilter(mixed $value, string $operator): string
    {
        return '';
    }

    public function filterClause(mixed $value, string $operator): ?array
    {
        return null;
    }

    // ---------------------------------------------------------------- render

    public function render(mixed $value, array $opts = []): string
    {
        $readonly = !empty($opts['readonly']) || $this->isReadonly();
        $accept = $this->accept();
        return $this->ctx->render('fields/media', [
            'field' => $this,
            'id' => $this->inputId(),
            'name' => $this->inputName(),
            'multiple' => $this->multiple(),
            'readonly' => $readonly,
            'files' => $this->ctx->media()->find(self::ids($value)),
            'config' => [
                'uploadUrl' => $this->ctx->url('upload', ['m' => $this->ctx->domain, 'field' => $this->path()]),
                'name' => $this->inputName(),
                'multiple' => $this->multiple(),
                'maxFiles' => $this->maxFiles(),
                'maxSize' => $this->maxSize(),
                'accept' => $accept,
                'i18n' => [
                    'tooLarge' => $this->ctx->t('media.too_large', ['max' => MediaStorage::humanSize($this->maxSize())]),
                    'type' => $this->ctx->t('media.type'),
                    'maxFiles' => $this->ctx->t('validation.max_files', ['max' => $this->maxFiles()]),
                    'error' => $this->ctx->t('media.upload_error'),
                    'remove' => $this->ctx->t('media.remove'),
                    'download' => $this->ctx->t('media.download'),
                    'wait' => $this->ctx->t('media.wait'),
                ],
            ],
            'acceptAttr' => implode(',', $accept),
            'hint' => $this->ctx->t($this->multiple() ? 'media.hint' : 'media.hint_one', [
                'max' => MediaStorage::humanSize($this->maxSize()),
                'files' => $this->maxFiles(),
            ]),
        ]);
    }
}
