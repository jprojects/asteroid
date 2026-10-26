<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\Chat;
use Quasar\Core\Database;
use Quasar\Core\Notify;
use Quasar\Core\User;

/**
 * Asteroid · utilidades compartidas por los hooks: quién es del equipo o de un cliente, y lo que pasa
 * cuando una incidencia cambia de estado o de técnico (apunte en su conversación y avisos).
 */
final class Asteroid
{
    public const STATUS_PENDING = 1;
    public const STATUS_IN_PROGRESS = 2;
    public const STATUS_RESOLVED = 3;
    public const STATUS_DISCARDED = 4;
    private const STATUS_KEYS = [1 => 'pending', 2 => 'in_progress', 3 => 'resolved', 4 => 'discarded'];

    /** Usuario de un cliente (y no del equipo ni administrador). */
    public static function isClient(User $user): bool
    {
        return !$user->isAdmin() && in_array('client', $user->groups(), true) && !in_array('equip', $user->groups(), true);
    }

    /** @return list<int> usuarios activos del equipo (y administradores) */
    public static function teamIds(Database $db): array
    {
        return array_map('intval', array_column($db->fetchAll(
            "SELECT u.id FROM users u WHERE u.active = 1 AND (u.is_admin = 1
               OR JSON_CONTAINS(u.`groups`, (SELECT CONCAT(g.id) FROM user_groups g WHERE g.code = 'equip')))"
        ), 'id'));
    }

    /** @return list<int> usuarios activos de un cliente */
    public static function clientUserIds(Database $db, mixed $clientId): array
    {
        if ($clientId === null || $clientId === '') {
            return [];
        }
        return array_map('intval', array_column($db->fetchAll('SELECT id FROM users WHERE client_id = ? AND active = 1', [(int) $clientId]), 'id'));
    }

    /**
     * Usuarios del cliente a los que avisar de una incidencia visible: quien la abrió, si es del cliente;
     * si la abrió el equipo, todos los usuarios del cliente.
     * @return list<int>
     */
    public static function clientRecipients(Database $db, array $issue): array
    {
        if ((int) $issue['client_visible'] !== 1) {
            return [];
        }
        $users = self::clientUserIds($db, $issue['client_id']);
        $creator = (int) ($issue['created_by'] ?? 0);
        return in_array($creator, $users, true) ? [$creator] : $users;
    }

    /** [clave de idioma] del estado, para textos que se traducen al idioma de cada destinatario. */
    public static function statusLabel(int $status): array
    {
        return ['issues:status.' . (self::STATUS_KEYS[$status] ?? 'pending')];
    }

    /** @return array<string,mixed>|null */
    public static function issue(Database $db, string|int $id): ?array
    {
        return $db->fetch('SELECT i.*, c.name AS client_name FROM issues i LEFT JOIN clients c ON c.id = i.client_id WHERE i.id = ?', [(int) $id]);
    }

    public static function issueUrl(string|int $id): string
    {
        return 'index.php?r=edit&m=issues&id=' . (int) $id;
    }

    /** Cambia el estado (p. ej. al imputar horas) con sus efectos. No hace nada si ya lo tiene. */
    public static function setStatus(Database $db, string|int $issueId, int $status, User $user): void
    {
        $issue = self::issue($db, $issueId);
        if ($issue === null || (int) $issue['status'] === $status || !isset(self::STATUS_KEYS[$status])) {
            return;
        }
        $db->update('issues', [
            'status' => $status,
            'resolved_at' => $status === self::STATUS_RESOLVED ? date('Y-m-d H:i:s') : null,
        ], 'id', (int) $issueId);
        self::statusChanged($db, $issue, (int) $issue['status'], $status, $user);
    }

    /** Apunte y avisos de un cambio de estado ya guardado. */
    public static function statusChanged(Database $db, array $issue, int $from, int $to, User $user): void
    {
        $id = (string) $issue['id'];
        Chat::system('issues', $id, ['issues:log.status', ['from' => self::statusLabel($from), 'to' => self::statusLabel($to)]], $user->id());
        $record = '#' . $id . ' · ' . $issue['title'];
        $recipients = $issue['worker_id'] ? [(int) $issue['worker_id']] : [];
        array_push($recipients, ...self::clientRecipients($db, $issue));
        Notify::send($recipients, [
            'type' => 'issue.status',
            'title' => ['issues:notify.status', ['record' => $record, 'status' => self::statusLabel($to)]],
            'body' => ['issues:notify.status_body', ['user' => $user->name(), 'status' => self::statusLabel($to)]],
            'url' => self::issueUrl($id),
            'module' => 'issues',
            'record' => $id,
            'actor' => $user->id(),
            'urgent' => $to === self::STATUS_RESOLVED,
        ]);
    }

    /** Apunte y aviso al técnico al que se le asigna una incidencia. */
    public static function assigned(Database $db, array $issue, User $user): void
    {
        $id = (string) $issue['id'];
        $worker = $issue['worker_id'] ? $db->fetch('SELECT id, name, username FROM users WHERE id = ?', [(int) $issue['worker_id']]) : null;
        if ($worker === null) {
            return;
        }
        Chat::system('issues', $id, ['issues:log.assigned', ['user' => (string) ($worker['name'] ?: $worker['username'])]], $user->id());
        Notify::send([(int) $worker['id']], [
            'type' => 'issue.assigned',
            'title' => ['issues:notify.assigned', ['record' => '#' . $id . ' · ' . $issue['title']]],
            'body' => ['issues:notify.assigned_body', ['user' => $user->name(), 'client' => (string) ($issue['client_name'] ?? '')]],
            'url' => self::issueUrl($id),
            'module' => 'issues',
            'record' => $id,
            'actor' => $user->id(),
            'urgent' => true,
        ]);
    }
}
