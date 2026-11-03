<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\Action;
use Quasar\Core\ActionResult;
use Quasar\Core\ModuleHooks;
use Quasar\Core\PasswordReset;
use Quasar\Core\Session;
use Quasar\Core\User;

/**
 * Evita que un administrador se bloquee a sí mismo. Invitaciones: al crear un usuario con «Envia una
 * invitació» no se pone contraseña y le llega un enlace para elegirla; el botón de la ficha (sendLink)
 * le envía un enlace para cambiarla (o la invitación otra vez, si aún no ha entrado nunca).
 */
final class UsersHooks extends ModuleHooks
{
    private ?User $creator = null;

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
        if ($id === null && (int) ($this->values['invite'] ?? 0)) {
            if (!PasswordReset::service()->enabled()) {
                return 'errors.reset_disabled';
            }
            if (trim((string) ($data['email'] ?? '')) === '') {
                return 'errors.invite_email';
            }
            if (!(int) ($data['active'] ?? 1)) {
                return 'errors.link_inactive';
            }
            // Contraseña que nadie conoce: el usuario elige la suya con el enlace de la invitación
            $data['password_hash'] = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $this->creator = $user;
        }
        return null;
    }

    public function afterSave(string $id, array $data, bool $isNew, User $user): void
    {
        // Si un administrador cambia la contraseña, los dispositivos de confianza dejan de serlo
        if (!$isNew && isset($data['password_hash'])) {
            $this->db->query('DELETE FROM user_trusted_devices WHERE user_id = ?', [(int) $id]);
        }
        if ($isNew && $this->creator !== null) {
            $row = (array) $this->db->fetch('SELECT * FROM users WHERE id = ?', [(int) $id]);
            $sent = PasswordReset::service()->sendLink($row, 'invite', $user->name());
            Session::flash($sent ? 'success' : 'error', PasswordReset::service()->text($sent ? 'reset.invite_sent' : 'reset.link_failed', ['email' => (string) $row['email']]));
        }
    }

    public function beforeDelete(string $id, User $user): ?string
    {
        return (int) $id === $user->id() ? 'errors.self_delete' : null;
    }

    /** Botón de la ficha: enlace para elegir contraseña (invitación si aún no ha entrado nunca). */
    public function sendLink(Action $a): ActionResult|string
    {
        $reset = PasswordReset::service();
        if (!$reset->enabled()) {
            return ActionResult::error($a->t('errors.reset_disabled'));
        }
        $row = $a->db->fetch('SELECT * FROM users WHERE id = ?', [(int) $a->id]);
        if ($row === null || !(int) $row['active'] || trim((string) $row['email']) === '') {
            return ActionResult::error($a->t('errors.link_inactive'));
        }
        $kind = $row['last_login'] === null ? 'invite' : 'reset';
        if (!$reset->sendLink($row, $kind, $a->user->name())) {
            return ActionResult::error($a->t('reset.link_failed'));
        }
        return $a->t($kind === 'invite' ? 'reset.invite_sent' : 'reset.link_sent', ['email' => (string) $row['email']]);
    }
}
