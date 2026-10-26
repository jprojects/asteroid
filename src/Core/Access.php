<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Regla de acceso de un elemento del JSON de un módulo (campo, sección, acción de fila…):
 *
 *   "access": "direccion"                                     solo ese grupo
 *   "access": ["direccion", "comercial"]                      alguno de esos grupos
 *   "access": { "groups": ["direccion"], "permission": "costs" }
 *                                                             esos grupos o quien tenga el permiso
 *                                                             "costs" del módulo (matriz de permisos)
 *
 * Los grupos se indican por su código (tabla user_groups). Los administradores pasan siempre.
 */
final class Access
{
    /** @param list<string> $groups */
    private function __construct(
        public readonly array $groups,
        public readonly ?string $permission,
    ) {
    }

    /**
     * @return self|null null = sin restricción
     * @throws \InvalidArgumentException si la regla está mal escrita
     */
    public static function parse(mixed $rule): ?self
    {
        if ($rule === null) {
            return null;
        }
        $permission = null;
        if (is_array($rule) && !array_is_list($rule)) {
            $unknown = array_diff(array_keys($rule), ['groups', 'permission']);
            if ($unknown) {
                throw new \InvalidArgumentException('clave desconocida "' . implode('", "', $unknown) . '" (usa "groups" y "permission")');
            }
            $permission = $rule['permission'] ?? null;
            if ($permission !== null && (!is_string($permission) || !preg_match('/^[a-z0-9_]+$/', $permission))) {
                throw new \InvalidArgumentException('"permission" debe ser el nombre de una acción del módulo');
            }
            $rule = $rule['groups'] ?? [];
        }
        $groups = is_string($rule) ? [$rule] : $rule;
        if (!is_array($groups)) {
            throw new \InvalidArgumentException('debe ser un código de grupo, una lista de códigos o {"groups": […], "permission": "…"}');
        }
        foreach ($groups as $code) {
            if (!is_string($code) || !preg_match('/^[a-z0-9_]+$/', $code)) {
                throw new \InvalidArgumentException('código de grupo no válido: ' . json_encode($code, JSON_UNESCAPED_UNICODE));
            }
        }
        if (!$groups && $permission === null) {
            throw new \InvalidArgumentException('la regla no indica ningún grupo ni permiso');
        }
        return new self(array_values(array_unique($groups)), $permission);
    }

    /** Sin usuario (scripts de consola) no se restringe nada. */
    public function allows(?User $user, string $module): bool
    {
        if ($user === null || $user->isAdmin()) {
            return true;
        }
        return $user->inGroup($this->groups)
            || ($this->permission !== null && $user->can($module, $this->permission));
    }
}
