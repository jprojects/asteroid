<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/**
 * Proyectos: los «Contactes per als avisos» solo pueden ser usuarios activos del cliente del proyecto (el núcleo no
 * valida los selects múltiples dependientes; los que no lo son se quitan al guardar).
 */
final class ProjectsHooks extends ModuleHooks
{
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        if (array_key_exists('notify_users', $data) || array_key_exists('client_id', $data)) {
            $data['notify_users'] = Asteroid::onlyClientUsers(
                $this->db,
                $data['notify_users'] ?? ($existing['notify_users'] ?? null),
                $data['client_id'] ?? ($existing['client_id'] ?? null)
            );
        }
        return null;
    }
}
