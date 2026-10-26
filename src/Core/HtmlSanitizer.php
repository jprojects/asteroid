<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Limpia HTML escrito por los usuarios (campo richtext) con una lista cerrada de etiquetas y atributos.
 * Se reconstruye el documento nodo a nodo: nada que no esté en la lista llega a la salida.
 *
 * - Etiquetas permitidas: párrafos, saltos, negrita, cursiva, subrayado, tachado, listas, citas, código,
 *   títulos (h1–h6 pasan a h3/h4), tablas sencillas, enlaces e imágenes.
 * - Enlaces: solo http, https, mailto y rutas internas (index.php?…). Los externos abren en otra pestaña.
 * - Imágenes: solo archivos de Quasar (index.php?r=media&id=N); las demás se eliminan.
 * - script, style, iframe, formularios, svg… se eliminan con su contenido; el resto de etiquetas
 *   desconocidas se quitan conservando su texto.
 */
final class HtmlSanitizer
{
    private const ALLOWED = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'h3', 'h4', 'hr', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];
    private const RENAME = ['h1' => 'h3', 'h2' => 'h3', 'h5' => 'h4', 'h6' => 'h4', 'div' => 'p', 'strike' => 's', 'del' => 's'];
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'meta', 'link', 'svg', 'math', 'form',
        'input', 'button', 'select', 'textarea', 'noscript', 'template', 'video', 'audio', 'canvas',
    ];
    private const VOID = ['br', 'hr', 'img'];
    private const MEDIA_SRC = '~^index\.php\?r=media&(?:amp;)?id=(\d+)(?:&(?:amp;)?thumb=1)?$~';

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $doc->getElementsByTagName('body')->item(0);
        $out = $body !== null ? self::children($body) : '';
        // Párrafos vacíos que dejan los editores al final
        $out = preg_replace('~(<p>(\s|&nbsp;|<br>)*</p>\s*)+$~u', '', $out) ?? $out;
        return trim($out);
    }

    /** Texto plano del HTML (para la tabla, la búsqueda y las exportaciones), con saltos de línea. */
    public static function toText(string $html): string
    {
        $text = preg_replace(['~<br\s*/?>~i', '~</(p|li|h[1-6]|tr|blockquote|pre)>~i', '~<li[^>]*>~i'], ["\n", "\n", '• '], $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace(["~[ \t\u{00A0}]+~u", "~\n{3,}~"], [' ', "\n\n"], $text) ?? $text;
        return trim(implode("\n", array_map('trim', explode("\n", $text))));
    }

    /**
     * Ids de media de las imágenes del HTML.
     * @return list<int>
     */
    public static function mediaIds(string $html): array
    {
        preg_match_all('~index\.php\?r=media&(?:amp;)?id=(\d+)~', $html, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    private static function children(\DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::node($child);
        }
        return $out;
    }

    private static function node(\DOMNode $node): string
    {
        if ($node instanceof \DOMText) {
            return htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        }
        if (!$node instanceof \DOMElement) {
            return ''; // comentarios, instrucciones de proceso…
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP, true)) {
            return '';
        }
        $tag = self::RENAME[$tag] ?? $tag;
        if (!in_array($tag, self::ALLOWED, true)) {
            return self::children($node);
        }
        $attrs = '';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (!preg_match('~^(https?://|mailto:|index\.php\?)~i', $href)) {
                return self::children($node); // enlace no permitido: queda el texto
            }
            $attrs = ' href="' . self::attr($href) . '"';
            if (!str_starts_with($href, 'index.php?')) {
                $attrs .= ' target="_blank" rel="noopener noreferrer"';
            }
        } elseif ($tag === 'img') {
            $src = html_entity_decode(trim($node->getAttribute('src')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match(self::MEDIA_SRC, $src)) {
                return '';
            }
            $attrs = ' src="' . self::attr($src) . '"';
            if (($alt = trim($node->getAttribute('alt'))) !== '') {
                $attrs .= ' alt="' . self::attr(mb_substr($alt, 0, 200)) . '"';
            }
        } elseif (in_array($tag, ['td', 'th'], true)) {
            foreach (['colspan', 'rowspan'] as $a) {
                $n = (int) $node->getAttribute($a);
                if ($n > 1 && $n <= 50) {
                    $attrs .= " $a=\"$n\"";
                }
            }
        }
        if (in_array($tag, self::VOID, true)) {
            return "<$tag$attrs>";
        }
        return "<$tag$attrs>" . self::children($node) . "</$tag>";
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
