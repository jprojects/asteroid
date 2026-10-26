<?php
declare(strict_types=1);

namespace Quasar\Fields;

use Quasar\Core\HtmlSanitizer;

/**
 * Texto con formato (negrita, listas, enlaces, imágenes…) con un editor propio, sin librerías.
 * Se guarda como HTML limpio (ver HtmlSanitizer) en una columna TEXT/MEDIUMTEXT/LONGTEXT.
 *
 * Opciones: rows (alto aproximado del editor, por defecto 8), listLength (recorte en la tabla),
 * "images": true (pegar, arrastrar o elegir imágenes; se suben como archivos de Quasar),
 * maxSize (de cada imagen, por defecto "5M").
 * En la tabla, la búsqueda y las exportaciones se usa el texto sin formato.
 */
final class RichTextField extends TextareaField
{
    public function render(mixed $value, array $opts = []): string
    {
        $html = HtmlSanitizer::clean((string) ($value ?? ''));
        $readonly = !empty($opts['readonly']) || $this->isReadonly();
        if ($readonly) {
            return '<div class="qs-richtext-view" id="' . self::e($this->inputId()) . '">' . $html . '</div>';
        }
        $config = [
            'upload' => !empty($this->def['images'])
                ? $this->ctx->url('upload', ['m' => $this->ctx->domain, 'field' => $this->path()])
                : null,
            'media' => $this->ctx->url('media', ['id' => '']),
            'i18n' => [
                'bold' => $this->ctx->t('richtext.bold'),
                'italic' => $this->ctx->t('richtext.italic'),
                'underline' => $this->ctx->t('richtext.underline'),
                'ul' => $this->ctx->t('richtext.ul'),
                'ol' => $this->ctx->t('richtext.ol'),
                'quote' => $this->ctx->t('richtext.quote'),
                'link' => $this->ctx->t('richtext.link'),
                'linkPrompt' => $this->ctx->t('richtext.link_prompt'),
                'image' => $this->ctx->t('richtext.image'),
                'clear' => $this->ctx->t('richtext.clear'),
                'uploading' => $this->ctx->t('media.uploading'),
            ],
        ];
        return '<div class="qs-richtext" data-richtext="' . self::e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '"'
            . ' style="--rows:' . max(3, (int) ($this->def['rows'] ?? 8)) . '">'
            . '<textarea' . $this->attrs(['hidden' => true, 'class' => null, 'required' => null] + $this->baseAttrs($opts)) . '>' . self::e($html) . '</textarea>'
            . '</div>';
    }

    public function fromRequest(array $input): mixed
    {
        $value = $input[$this->name] ?? '';
        return is_string($value) ? HtmlSanitizer::clean($value) : '';
    }

    protected function isEmpty(mixed $value): bool
    {
        $html = (string) ($value ?? '');
        return trim(HtmlSanitizer::toText($html)) === '' && !str_contains($html, '<img');
    }

    /** Campo media equivalente para subir las imágenes del editor (mismo nombre y ruta). */
    public function imageField(): ?MediaField
    {
        if (empty($this->def['images'])) {
            return null;
        }
        $field = new MediaField($this->name, [
            'type' => 'media',
            'accept' => ['image/*'],
            'maxSize' => $this->def['maxSize'] ?? '5M',
        ], $this->ctx);
        if (($parent = substr($this->path(), 0, -strlen($this->name))) !== '') {
            $field->nestUnder(rtrim($parent, '.'));
        }
        return $field;
    }

    public function mediaIds(mixed $dbValue): array
    {
        return HtmlSanitizer::mediaIds((string) ($dbValue ?? ''));
    }

    public function mediaPattern(int $id): string
    {
        return '[?&;]id=' . $id . '([^0-9]|$)';
    }

    public function format(mixed $value): string
    {
        return parent::format(HtmlSanitizer::toText((string) ($value ?? '')));
    }

    public function fullText(mixed $value): string
    {
        return HtmlSanitizer::toText((string) ($value ?? ''));
    }
}
