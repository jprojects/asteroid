<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Permisos módulo × acción. Se conceden a usuarios (user_permissions) y a grupos
 * (group_permissions); un usuario tiene los suyos más los de todos sus grupos.
 * Los grupos de un usuario se guardan como lista JSON de ids en users.groups.
 */
final class Permissions
{
    public const DEFAULT_ACTIONS = ['view', 'create', 'edit', 'delete', 'export'];

    /** sujeto => [tabla, columna] */
    private const SUBJECTS = [
        'user' => ['user_permissions', 'user_id'],
        'group' => ['group_permissions', 'group_id'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Permisos efectivos: los propios del usuario más los de sus grupos.
     * @param list<int> $groupIds
     * @return array<string,array<string,bool>>
     */
    public function forUser(int $userId, array $groupIds = []): array
    {
        $out = $this->own('user', $userId);
        foreach ($this->fromGroups($groupIds) as $module => $actions) {
            foreach ($actions as $action => $_) {
                $out[$module][$action] = true;
            }
        }
        return $out;
    }

    /**
     * Permisos concedidos directamente a un usuario o a un grupo.
     * @param 'user'|'group' $subject
     * @return array<string,array<string,bool>>
     */
    public function own(string $subject, int $id): array
    {
        [$table, $column] = self::SUBJECTS[$subject];
        $out = [];
        foreach ($this->db->fetchAll("SELECT module, action FROM $table WHERE $column = ?", [$id]) as $r) {
            $out[$r['module']][$r['action']] = true;
        }
        return $out;
    }

    /**
     * Permisos que dan unos grupos, con el nombre de los grupos que conceden cada uno.
     * @param list<int> $groupIds
     * @return array<string,array<string,list<string>>> [módulo => [acción => [nombre de grupo…]]]
     */
    public function fromGroups(array $groupIds): array
    {
        if (!$groupIds) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT p.module, p.action, g.name FROM group_permissions p JOIN user_groups g ON g.id = p.group_id
             WHERE p.group_id IN (' . implode(',', array_fill(0, count($groupIds), '?')) . ') ORDER BY g.name',
            array_values($groupIds)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['module']][$r['action']][] = (string) $r['name'];
        }
        return $out;
    }

    /**
     * Grupos existentes de un usuario a partir de su columna users.groups.
     * @return array<int,string> id => código
     */
    public function groupsOf(mixed $json): array
    {
        $ids = self::groupIds($json);
        if (!$ids) {
            return [];
        }
        $out = [];
        $rows = $this->db->fetchAll(
            'SELECT id, code FROM user_groups WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (string) $r['code'];
        }
        return $out;
    }

    /** @return list<int> ids de la lista JSON de users.groups */
    public static function groupIds(mixed $json): array
    {
        $list = is_string($json) && $json !== '' ? json_decode($json, true) : $json;
        if (!is_array($list)) {
            return [];
        }
        $ids = array_filter(array_map(static fn ($v) => is_scalar($v) && ctype_digit((string) $v) ? (int) $v : 0, $list));
        return array_values(array_unique($ids));
    }

    /**
     * Reemplaza los permisos de un usuario o de un grupo.
     *
     * @param 'user'|'group' $subject
     * @param array $matrix   [módulo => [acción => '1']] tal como llega del formulario
     * @param array<string,list<string>> $allowed [módulo => acciones válidas]
     */
    public function save(string $subject, int $id, array $matrix, array $allowed): void
    {
        [$table, $column] = self::SUBJECTS[$subject];
        $this->db->transaction(function (Database $db) use ($table, $column, $id, $matrix, $allowed): void {
            $db->query("DELETE FROM $table WHERE $column = ?", [$id]);
            foreach ($allowed as $module => $actions) {
                $granted = is_array($matrix[$module] ?? null) ? $matrix[$module] : [];
                $granted = array_values(array_intersect($actions, array_keys(array_filter($granted))));
                // Cualquier permiso implica poder ver el módulo
                if ($granted && !in_array('view', $granted, true) && in_array('view', $actions, true)) {
                    $granted[] = 'view';
                }
                foreach ($granted as $action) {
                    $db->query("INSERT INTO $table ($column, module, action) VALUES (?, ?, ?)", [$id, $module, $action]);
                }
            }
        });
    }
}
