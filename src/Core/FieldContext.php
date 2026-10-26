<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Lo que un campo necesita de su entorno: BD (para opciones dinámicas),
 * idioma ligado al dominio del módulo, la plantilla activa (para campos
 * complejos que se pintan con un parcial), URLs, el almacén de archivos y el usuario
 * actual (para las reglas "access").
 */
final class FieldContext
{
    /**
     * @param \Closure(string,array):string|null $renderer  pinta un parcial de la plantilla
     * @param \Closure(string,array):string|null $urlBuilder construye URLs internas
     * @param \Closure():?User|null $userProvider usuario de la sesión (null en consola)
     * @param \Closure(string):?Module|null $moduleProvider otro módulo por su nombre (selects con "source.module")
     */
    public function __construct(
        public readonly Database $db,
        public readonly Lang $lang,
        public readonly string $domain,
        private readonly ?\Closure $renderer = null,
        private readonly ?\Closure $urlBuilder = null,
        public readonly ?MediaStorage $media = null,
        private readonly ?\Closure $userProvider = null,
        private readonly ?\Closure $moduleProvider = null,
    ) {
    }

    /** Otro módulo de la aplicación, o null si no existe (o en consola sin registro). */
    public function module(string $name): ?Module
    {
        return $this->moduleProvider !== null ? ($this->moduleProvider)($name) : null;
    }

    /** Usuario de la sesión; null en scripts de consola (sin restricciones de acceso). */
    public function user(): ?User
    {
        return $this->userProvider !== null ? ($this->userProvider)() : null;
    }

    public function t(string $key, array $vars = []): string
    {
        return $this->lang->get($key, $vars, $this->domain);
    }

    /** Pinta templates/{plantilla}/{template}.php sin layout. */
    public function render(string $template, array $vars): string
    {
        if ($this->renderer === null) {
            throw new \LogicException('FieldContext sin renderer de plantillas');
        }
        return ($this->renderer)($template, $vars);
    }

    public function url(string $route, array $params = []): string
    {
        return $this->urlBuilder !== null
            ? ($this->urlBuilder)($route, $params)
            : 'index.php?' . http_build_query(['r' => $route] + $params);
    }

    public function media(): MediaStorage
    {
        return $this->media ?? throw new \LogicException('FieldContext sin almacén de media');
    }
}
