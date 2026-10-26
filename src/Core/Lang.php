<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Traducciones en lang/{locale}/{dominio}.json.
 *
 * Cada vista/módulo tiene su propio dominio (p. ej. "clients") y existe un
 * dominio compartido "common". Las claves anidadas se acceden con puntos:
 * "fields.name". Se puede forzar un dominio con el prefijo "dominio:clave".
 * Si una clave no existe se busca en common y, si tampoco, se devuelve la propia clave.
 */
final class Lang
{
    /** @var array<string,array<string,string>> */
    private array $cache = [];

    public function __construct(
        private readonly string $dir,
        private string $locale,
        private readonly string $fallback,
    ) {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale) && is_dir($this->dir . '/' . $locale)) {
            $this->locale = $locale;
        }
    }

    public function get(string $key, array $vars = [], ?string $domain = null): string
    {
        if (preg_match('/^([a-z0-9_]+):(.+)$/', $key, $m)) {
            [, $domain, $key] = $m;
        }
        // "app": textos propios de la instalación compartidos por todos los módulos (grupos del menú…),
        // en lang/{idioma}/app.json; no es del núcleo, así que una actualización no lo sobrescribe
        $domains = ($domain !== null && $domain !== 'common' && $domain !== 'app') ? [$domain, 'app', 'common'] : ['app', 'common'];
        foreach ($domains as $d) {
            $value = $this->strings($d)[$key] ?? null;
            if ($value !== null) {
                return $this->interpolate($value, $vars);
            }
        }
        return $this->interpolate($key, $vars);
    }

    /**
     * Variables de un texto en las que un valor [clave, variables] se sustituye por su traducción
     * (p. ej. ['status' => ['issues:status.resolved']]).
     */
    public function vars(array $vars): array
    {
        foreach ($vars as $k => $v) {
            if (is_array($v)) {
                $vars[$k] = $this->get((string) ($v[0] ?? ''), (array) ($v[1] ?? []));
            }
        }
        return $vars;
    }

    public function has(string $key, string $domain = 'common'): bool
    {
        return isset($this->strings($domain)[$key]);
    }

    /** @return array<string,string> claves planas del dominio (idioma actual sobre el de reserva) */
    public function strings(string $domain): array
    {
        $cacheKey = $this->locale . '/' . $domain;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        if (!preg_match('/^[a-z0-9_]+$/', $domain)) {
            return $this->cache[$cacheKey] = [];
        }
        $strings = $this->loadFile($this->fallback, $domain);
        if ($this->locale !== $this->fallback) {
            $strings = array_replace($strings, $this->loadFile($this->locale, $domain));
        }
        return $this->cache[$cacheKey] = $strings;
    }

    private function loadFile(string $locale, string $domain): array
    {
        $file = "{$this->dir}/{$locale}/{$domain}.json";
        if (!is_file($file)) {
            return [];
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("JSON de idioma no válido en $file: " . $e->getMessage(), 0, $e);
        }
        return is_array($data) ? self::flatten($data) : [];
    }

    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix . $k;
            if (is_array($v)) {
                $out += self::flatten($v, $key . '.');
            } else {
                $out[$key] = (string) $v;
            }
        }
        return $out;
    }

    private function interpolate(string $text, array $vars): string
    {
        if (!$vars) {
            return $text;
        }
        $replace = [];
        foreach ($vars as $k => $v) {
            $replace['{' . $k . '}'] = (string) $v;
        }
        return strtr($text, $replace);
    }
}
