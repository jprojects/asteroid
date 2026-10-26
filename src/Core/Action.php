<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Lo que recibe una acción de un módulo (botón con "action" en el JSON), un método de su clase hooks:
 *
 *   public function duplicate(Action $a): ActionResult|string|null
 *   {
 *       $newId = $a->db->insert('clients', ['name' => $a->row['name'] . ' (copia)']);
 *       return ActionResult::redirect($a->editUrl($newId), 'Cliente duplicado');
 *   }
 *
 * Devolver un texto equivale a ActionResult::success($texto); null, a terminar sin mensaje.
 * Cuando llega aquí ya se han comprobado la sesión, el token CSRF, el permiso "view" del módulo,
 * la regla "access" del botón y su "showon" sobre el registro.
 */
final class Action
{
    /**
     * @param ?string $id registro sobre el que se ejecuta (botones de fila y de formulario); null en la barra
     * @param ?array<string,mixed> $row valores del registro, como en el formulario (null en la barra)
     */
    public function __construct(
        public readonly string $name,
        public readonly Module $module,
        public readonly User $user,
        public readonly Database $db,
        public readonly ?string $id,
        public readonly ?array $row,
        private readonly \Closure $urlBuilder,
        private readonly array $config = [],
    ) {
    }

    /** Clave de la configuración de la instalación (config.php + config.local.php). */
    public function config(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /** Texto del idioma del módulo (y de common). */
    public function t(string $key, array $vars = []): string
    {
        return $this->module->t($key, $vars);
    }

    public function url(string $route, array $params = []): string
    {
        return ($this->urlBuilder)($route, $params);
    }

    /** Ficha de un registro del módulo (por defecto, el de la acción). */
    public function editUrl(string|int|null $id = null): string
    {
        return $this->url('edit', ['m' => $this->module->name, 'id' => (string) ($id ?? $this->id)]);
    }

    public function listUrl(): string
    {
        return $this->url('list', ['m' => $this->module->name]);
    }
}
