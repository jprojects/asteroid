<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/** Archivos: el cliente es el de la carpeta (el "scope" de los clientes lo usa). */
final class FilesHooks extends ModuleHooks
{
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        if (!empty($data['folder_id'])) {
            $data['client_id'] = $this->db->fetchValue('SELECT client_id FROM folders WHERE id = ?', [(int) $data['folder_id']]);
        }
        return null;
    }
}
