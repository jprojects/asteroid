<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Qué registros de un módulo puede ver cada usuario ("scope" en el JSON del módulo):
 *
 *   "scope": {
 *     "equip":  true,                                              todos los registros
 *     "client": {
 *       "where": "issues.client_id = {user.client_id} AND issues.client_visible = 1",
 *       "set":   { "client_id": "{user.client_id}", "client_visible": 1 }
 *     },
 *     "*": false                                                   el resto de usuarios: ninguno
 *   }
 *
 * - La clave es un código de grupo o "*" (usuarios que no están en ningún grupo de la lista).
 * - El valor es true (todo), false (nada), una condición SQL o {"where", "set"}.
 * - {user.columna} se sustituye por el valor de esa columna de la tabla users (como parámetro de la
 *   consulta, nunca concatenado). {user.id} es el id del usuario.
 * - "set": columnas que se fijan al crear o modificar un registro, para que nadie pueda guardar uno
 *   fuera de lo que ve. Admite los mismos {user.…}.
 * - Un usuario de varios grupos ve lo que vería con cualquiera de ellos. Si no encaja en ninguna regla
 *   y no hay "*", no ve ningún registro. Los administradores (y los scripts de consola) lo ven todo.
 *
 * Las condiciones son configuración del desarrollador y se insertan tal cual en el SQL.
 */
final class Scope
{
    private const PLACEHOLDER = '/\{user\.([a-z_][a-z0-9_]*)\}/';

    /** @param array<string,array{where:?string,set:array<string,mixed>}|bool> $rules */
    private function __construct(private readonly array $rules)
    {
    }

    /**
     * @return self|null null = sin "scope" (todos ven todos los registros)
     * @throws \InvalidArgumentException si está mal escrito
     */
    public static function parse(mixed $def): ?self
    {
        if ($def === null) {
            return null;
        }
        if (!is_array($def) || array_is_list($def)) {
            throw new \InvalidArgumentException('debe ser un objeto {"código de grupo": regla}');
        }
        $rules = [];
        foreach ($def as $code => $rule) {
            $code = (string) $code;
            if ($code !== '*' && !preg_match('/^[a-z0-9_]+$/', $code)) {
                throw new \InvalidArgumentException("código de grupo no válido '$code' (minúsculas, números y _, o \"*\")");
            }
            if (is_bool($rule)) {
                $rules[$code] = $rule;
                continue;
            }
            if (is_string($rule)) {
                $rule = ['where' => $rule];
            }
            if (!is_array($rule) || array_is_list($rule) || array_diff(array_keys($rule), ['where', 'set'])) {
                throw new \InvalidArgumentException("la regla de '$code' debe ser true, false, una condición SQL o {\"where\", \"set\"}");
            }
            $where = $rule['where'] ?? null;
            if ($where !== null && (!is_string($where) || trim($where) === '')) {
                throw new \InvalidArgumentException("\"where\" de '$code' debe ser una condición SQL");
            }
            $set = $rule['set'] ?? [];
            if (!is_array($set) || ($set && array_is_list($set))) {
                throw new \InvalidArgumentException("\"set\" de '$code' debe ser un objeto {columna: valor}");
            }
            foreach ($set as $col => $value) {
                Database::ident((string) $col);
                if (!is_scalar($value) && $value !== null) {
                    throw new \InvalidArgumentException("\"set.$col\" de '$code' debe ser un valor simple o \"{user.columna}\"");
                }
            }
            $rules[$code] = ['where' => $where, 'set' => $set];
        }
        if (!$rules) {
            throw new \InvalidArgumentException('no tiene ninguna regla');
        }
        return new self($rules);
    }

    /**
     * Condición SQL de los registros visibles para $user.
     * @return array{0:string,1:list<mixed>}|null null = sin restricción
     */
    public function condition(?User $user): ?array
    {
        if ($user === null || $user->isAdmin()) {
            return null;
        }
        $or = [];
        $bind = [];
        foreach ($this->matching($user) as $rule) {
            if ($rule === true || ($rule !== false && $rule['where'] === null)) {
                return null;
            }
            if ($rule === false) {
                continue;
            }
            [$sql, $params] = self::bindUser($rule['where'], $user);
            $or[] = '(' . $sql . ')';
            array_push($bind, ...$params);
        }
        return $or ? [count($or) > 1 ? '(' . implode(' OR ', $or) . ')' : $or[0], $bind] : ['1 = 0', []];
    }

    /**
     * Columnas que se fijan al guardar un registro de $user (las de la primera regla que las indica).
     * @return array<string,mixed>
     */
    public function values(?User $user): array
    {
        if ($user === null || $user->isAdmin() || $this->condition($user) === null) {
            return [];
        }
        $out = [];
        foreach ($this->matching($user) as $rule) {
            foreach (is_array($rule) ? $rule['set'] : [] as $col => $value) {
                if (array_key_exists($col, $out)) {
                    continue;
                }
                $out[$col] = is_string($value) && preg_match('/^\{user\.([a-z_][a-z0-9_]*)\}$/', $value, $m)
                    ? self::userValue($user, $m[1])
                    : $value;
            }
        }
        return $out;
    }

    /** @return list<array{where:?string,set:array}|bool> reglas de los grupos del usuario (o la de "*") */
    private function matching(User $user): array
    {
        $out = [];
        foreach ($this->rules as $code => $rule) {
            if ($code !== '*' && in_array($code, $user->groups(), true)) {
                $out[] = $rule;
            }
        }
        if (!$out && isset($this->rules['*'])) {
            $out[] = $this->rules['*'];
        }
        return $out;
    }

    /**
     * Sustituye {user.columna} por parámetros "?" en una condición SQL.
     * @return array{0:string,1:list<mixed>}
     */
    public static function bindUser(string $sql, User $user): array
    {
        $bind = [];
        $sql = (string) preg_replace_callback(self::PLACEHOLDER, static function (array $m) use ($user, &$bind) {
            $bind[] = self::userValue($user, $m[1]);
            return '?';
        }, $sql);
        return [$sql, $bind];
    }

    private static function userValue(User $user, string $column): mixed
    {
        return $column === 'id' ? $user->id() : $user->get($column);
    }
}
