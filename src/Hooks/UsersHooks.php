<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/**
 * Evita que un administrador se bloquee a sí mismo.
 */
final class UsersHooks extends ModuleHooks
{
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        if ($id !== null && (int) $id === $user->id()) {
            if (array_key_exists('is_admin', $data) && !(int) $data['is_admin']) {
                return 'errors.self_admin';
            }
            if (array_key_exists('active', $data) && !(int) $data['active']) {
                return 'errors.self_active';
            }
        }
        return null;
    }

    public function afterSave(string $id, array $data, bool $isNew, User $user): void
    {
        // Si un administrador cambia la contraseña, los dispositivos de confianza dejan de serlo
        if (!$isNew && isset($data['password_hash'])) {
            $this->db->query('DELETE FROM user_trusted_devices WHERE user_id = ?', [(int) $id]);
        }
    }

    public function beforeDelete(string $id, User $user): ?string
    {
        return (int) $id === $user->id() ? 'errors.self_delete' : null;
    }
}
