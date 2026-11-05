<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\Action;
use Quasar\Core\ModuleHooks;
use Quasar\Core\User;

/**
 * Notas personales del equipo (modules/notes.json). Cada una es de quien la crea (no se puede cambiar); solo la ve
 * él, salvo que la comparta con el equipo ("where" del módulo, también para los administradores). Marcarla como
 * hecha guarda cuándo.
 */
final class NotesHooks extends ModuleHooks
{
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        if ($id === null) {
            $data['user_id'] = $user->id();
        } else {
            unset($data['user_id']); // la nota sigue siendo de quien la creó
        }
        if (array_key_exists('done', $data)) {
            $wasDone = (int) ($existing['done'] ?? 0) === 1;
            if ((int) $data['done'] === 1 && !$wasDone) {
                $data['done_at'] = date('Y-m-d H:i:s');
            } elseif ((int) $data['done'] === 0) {
                $data['done_at'] = null;
            }
        }
        return null;
    }

    public function markDone(Action $a): string
    {
        $a->db->update('notes', ['done' => 1, 'done_at' => date('Y-m-d H:i:s')], 'id', (int) $a->id);
        return $a->t('flash.done');
    }

    public function markPending(Action $a): string
    {
        $a->db->update('notes', ['done' => 0, 'done_at' => null], 'id', (int) $a->id);
        return $a->t('flash.pending');
    }
}
