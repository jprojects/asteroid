<?php
declare(strict_types=1);

namespace Quasar\Fields;

/**
 * Opciones: rows (por defecto 4), maxlength, minlength, listLength (recorte en la tabla, por defecto 80;
 * no se aplica en columnas "overflow": "wrap", en el detalle desplegable ni en las exportaciones),
 * "assist": true (botón de ayuda a redactar con la IA: mejorar, más formal, más breve, corregir y traducir).
 */
class TextareaField extends TextField
{
    public function render(mixed $value, array $opts = []): string
    {
        return $this->withAssist('<textarea' . $this->attrs($this->baseAttrs($opts) + [
            'rows' => $this->def['rows'] ?? 4,
            'maxlength' => $this->def['maxlength'] ?? null,
            'minlength' => $this->def['minlength'] ?? null,
        ]) . '>' . self::e($value === null ? '' : (string) $value) . '</textarea>', $opts);
    }

    /**
     * Envuelve el editor con la ayuda a redactar ("assist": true) si la IA está activada y se puede editar.
     * Solo en campos de primer nivel (no en los subcampos de un repeater).
     */
    protected function withAssist(string $html, array $opts): string
    {
        if (empty($this->def['assist']) || !$this->ctx->ai || !empty($opts['readonly']) || $this->isReadonly() || str_contains($this->path(), '.')) {
            return $html;
        }
        $actions = [];
        foreach (['improve', 'formal', 'shorter', 'fix', 'translate_ca', 'translate_es', 'translate_en'] as $action) {
            $actions[$action] = $this->ctx->t('assist.' . $action);
        }
        $config = [
            'url' => $this->ctx->url('write', ['m' => $this->ctx->domain, 'field' => $this->name]),
            'actions' => $actions,
            'i18n' => [
                'button' => $this->ctx->t('assist.button'),
                'title' => $this->ctx->t('assist.title'),
                'replace' => $this->ctx->t('assist.replace'),
                'empty' => $this->ctx->t('assist.empty'),
                'working' => $this->ctx->t('assist.working'),
            ],
        ];
        return '<div class="qs-assist" data-assist="' . self::e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '">' . $html . '</div>';
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
