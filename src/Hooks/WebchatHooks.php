<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\Chat;
use Quasar\Core\ModuleHooks;
use Quasar\Core\User;
use Quasar\Webchat\Webchat;

/**
 * Chat web (modules/webchat.json): los mensajes de los visitantes avisan a todos los usuarios del cliente, y cerrar o
 * reabrir una conversación deja un apunte en ella.
 */
final class WebchatHooks extends ModuleHooks
{
    private ?array $before = null;

    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        $this->before = $existing;
        return null;
    }

    public function afterSave(string $id, array $data, bool $isNew, User $user): void
    {
        if (!$isNew && array_key_exists('status', $data) && (int) ($this->before['status'] ?? 1) !== (int) $data['status']) {
            Chat::system('webchat', $id, [(int) $data['status'] === 2 ? 'webchat:log.closed' : 'webchat:log.reopened', ['user' => $user->name()]], $user->id());
        }
    }

    /** Respuesta de un usuario del cliente: la conversación sube en el listado. */
    public function chatRecipients(string $id, array $row, User $author): array
    {
        $this->db->update('webchat_conversations', ['last_message_at' => date('Y-m-d H:i:s')], 'id', (int) $id);
        return [];
    }

    public function externalRecipients(string $id, array $row): array
    {
        $clientId = (int) $this->db->fetchValue('SELECT client_id FROM webchat_conversations WHERE id = ?', [(int) $id]);
        return $clientId > 0 ? Webchat::agents($this->db, $clientId) : [];
    }
}
