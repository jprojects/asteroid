<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Usuario autenticado con sus permisos y grupos ya cargados.
 */
final class User
{
    /**
     * @param array<string,mixed> $data fila de la tabla users
     * @param array<string,array<string,bool>> $permissions [módulo => [acción => true]] propios y de sus grupos
     * @param list<string> $groups códigos de sus grupos (tabla user_groups)
     */
    public function __construct(
        private readonly array $data,
        private readonly array $permissions,
        private readonly array $groups = [],
    ) {
    }

    public function id(): int
    {
        return (int) $this->data['id'];
    }

    public function username(): string
    {
        return (string) $this->data['username'];
    }

    public function name(): string
    {
        return (string) ($this->data['name'] ?: $this->data['username']);
    }

    public function email(): ?string
    {
        return $this->data['email'] ?? null;
    }

    public function locale(): ?string
    {
        return $this->data['locale'] ?? null;
    }

    public function isAdmin(): bool
    {
        return (bool) ($this->data['is_admin'] ?? false);
    }

    /** Acceso a cualquier otra columna de la tabla users (excepto el hash de contraseña). */
    public function get(string $key, mixed $default = null): mixed
    {
        return $key === 'password_hash' ? $default : ($this->data[$key] ?? $default);
    }

    /** @return list<string> códigos de los grupos del usuario */
    public function groups(): array
    {
        return $this->groups;
    }

    /** Si pertenece a alguno de los grupos indicados (por código). Los administradores, siempre. */
    public function inGroup(string|array $codes): bool
    {
        return $this->isAdmin() || (bool) array_intersect((array) $codes, $this->groups);
    }

    /** Los administradores pueden todo; el resto según sus permisos y los de sus grupos. */
    public function can(string $module, string $action = 'view'): bool
    {
        return $this->isAdmin() || !empty($this->permissions[$module][$action]);
    }

    public function permissions(): array
    {
        return $this->permissions;
    }
}
