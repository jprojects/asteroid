<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/**
 * Wiki (modules/wiki_pages.json): el cliente de la página es siempre el de su proyecto (de él depende quién la ve si es
 * para el cliente) y se guarda quién la ha escrito.
 */
final class WikiPagesHooks extends ModuleHooks
{
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        $project = $data['project_id'] ?? $existing['project_id'] ?? null;
        if ($project !== null && $project !== '') {
            $data['client_id'] = $this->db->fetchValue('SELECT client_id FROM projects WHERE id = ?', [(int) $project]);
        }
        $data['updated_by'] = $user->id();
        if ($id === null) {
            $data['created_by'] = $user->id();
        }
        if (isset($data['section'])) {
            $data['section'] = trim((string) $data['section']);
        }
        return null;
    }
}
