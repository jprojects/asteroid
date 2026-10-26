<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/**
 * Usuarios de los clientes (tabla users con client_id): siempre en el grupo "client" y nunca
 * administradores, aunque se den de alta desde aquí.
 */
final class ClientUsersHooks extends ModuleHooks
{
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        $group = $this->db->fetchValue("SELECT id FROM user_groups WHERE code = 'client'");
        if ($group === null) {
            return 'errors.no_client_group';
        }
        $data['is_admin'] = 0;
        if ($id === null) {
            $data['groups'] = json_encode([(int) $group]);
        }
        return null;
    }

    public function afterSave(string $id, array $data, bool $isNew, User $user): void
    {
        if (!$isNew && isset($data['password_hash'])) {
            $this->db->query('DELETE FROM user_trusted_devices WHERE user_id = ?', [(int) $id]);
        }
    }
}
