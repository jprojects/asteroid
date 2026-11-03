<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\ModuleHooks;
use Quasar\Core\User;
use Quasar\Fields\MediaField;

/**
 * Archivos: el cliente es el de la carpeta (el "scope" de los clientes lo usa). Se pueden subir varios a la vez, pero
 * cada registro guarda uno: el primero se queda en este y por cada uno de los demás se crea otro en la misma carpeta,
 * con su nombre como título. Sin título, el del nombre del archivo.
 */
final class FilesHooks extends ModuleHooks
{
    /** @var list<int> archivos de más que se convierten en registros nuevos en afterSave */
    private array $extra = [];

    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        if (!empty($data['folder_id'])) {
            $data['client_id'] = $this->db->fetchValue('SELECT client_id FROM folders WHERE id = ?', [(int) $data['folder_id']]);
        }
        $this->extra = [];
        if (array_key_exists('file', $data)) {
            $ids = MediaField::ids($data['file']);
            $data['file'] = $ids[0] ?? null;   // la columna guarda un solo archivo
            $this->extra = array_slice($ids, 1);
            if ($data['file'] !== null && trim((string) ($data['title'] ?? $existing['title'] ?? '')) === '') {
                $data['title'] = $this->titleOf($data['file']);
            }
        }
        return null;
    }

    public function afterSave(string $id, array $data, bool $isNew, User $user): void
    {
        if (!$this->extra) {
            return;
        }
        $row = $this->db->fetch('SELECT folder_id, client_id, notes FROM files WHERE id = ?', [(int) $id]);
        foreach ($this->extra as $mediaId) {
            $this->db->insert('files', [
                'folder_id' => (int) $row['folder_id'],
                'client_id' => $row['client_id'] !== null ? (int) $row['client_id'] : null,
                'title' => $this->titleOf($mediaId),
                'file' => $mediaId,
                'notes' => $row['notes'],
            ]);
        }
        $this->extra = [];
    }

    /** "Pressupost 2026.pdf" → "Pressupost 2026" */
    private function titleOf(int $mediaId): string
    {
        $name = (string) $this->db->fetchValue('SELECT original_name FROM media WHERE id = ?', [$mediaId]);
        $title = trim((string) preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $name));
        return mb_substr($title !== '' ? $title : $name, 0, 150);
    }
}
