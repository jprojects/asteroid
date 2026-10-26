<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/**
 * Al borrar un grupo se quita de los usuarios que lo tenían (users.groups es una lista JSON de ids).
 * Sus permisos (group_permissions) se borran solos por la clave foránea.
 */
final class GroupsHooks extends ModuleHooks
{
    public function afterDelete(string $id, User $user): void
    {
        $rows = $this->db->fetchAll(
            'SELECT id, `groups` FROM users
             WHERE CASE WHEN JSON_VALID(`groups`) THEN JSON_CONTAINS(`groups`, JSON_QUOTE(?)) ELSE 0 END',
            [$id]
        );
        foreach ($rows as $row) {
            $groups = array_values(array_filter(
                (array) json_decode((string) $row['groups'], true),
                static fn ($g) => (string) $g !== $id
            ));
            $this->db->update('users', ['groups' => json_encode(array_map('strval', $groups))], 'id', $row['id']);
        }
    }
}
