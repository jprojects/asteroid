<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\Chat;
use Quasar\Core\Database;
use Quasar\Core\Log;
use Quasar\Core\Notify;
use Quasar\Core\User;

/**
 * Asteroid · encuesta de satisfacción al resolver una incidencia (casilla "send_survey" de la ficha o de
 * las horas). Cada usuario del cliente recibe un aviso (campana y correo) con su propio enlace a la página
 * pública "survey", que vale una sola vez y caduca a los DAYS días. Las respuestas, en el módulo "surveys".
 */
final class Survey
{
    public const DAYS = 30;

    /** Envía la encuesta de una incidencia. @return int destinatarios */
    public static function send(Database $db, array $issue, User $user): int
    {
        $id = (string) $issue['id'];
        $recipients = self::recipients($db, $issue);
        if (!$recipients) {
            Chat::system('issues', $id, ['issues:log.survey_none'], $user->id());
            return 0;
        }
        $expires = date('Y-m-d H:i:s', time() + self::DAYS * 86400);
        foreach ($recipients as $r) {
            $token = bin2hex(random_bytes(20));
            $db->insert('surveys', [
                'issue_id' => (int) $id,
                'client_id' => $issue['client_id'] !== null ? (int) $issue['client_id'] : null,
                'user_id' => (int) $r['id'],
                'sent_by' => $user->id(),
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expires,
            ]);
            Notify::send([(int) $r['id']], [
                'type' => 'issue.survey',
                'title' => ['issues:notify.survey', ['record' => '#' . $id . ' · ' . $issue['title']]],
                'body' => ['issues:notify.survey_body', ['days' => self::DAYS]],
                'url' => 'index.php?' . http_build_query(['r' => 'public', 'm' => 'survey', 't' => $token]),
                'module' => 'issues',
                'record' => $id,
                'actor' => $user->id(),
                'urgent' => true,   // llega aunque acabe de recibir el aviso de "resuelta"
            ]);
        }
        $names = implode(', ', array_map(static fn ($r) => (string) ($r['name'] ?: $r['username']), $recipients));
        Chat::system('issues', $id, ['issues:log.survey_sent', ['users' => $names]], $user->id());
        Log::info('Encuesta de la incidencia {id} enviada a {n} usuario(s)', ['id' => $id, 'n' => count($recipients)]);
        return count($recipients);
    }

    /**
     * Usuarios del cliente con correo: quien abrió la incidencia, si es del cliente; si la abrió el equipo,
     * todos los usuarios activos del cliente.
     * @return list<array{id:int|string,name:string,username:string}>
     */
    private static function recipients(Database $db, array $issue): array
    {
        if ($issue['client_id'] === null) {
            return [];
        }
        $users = $db->fetchAll(
            "SELECT id, name, username FROM users WHERE client_id = ? AND active = 1 AND email IS NOT NULL AND email <> '' ORDER BY name",
            [(int) $issue['client_id']]
        );
        $creator = (int) ($issue['created_by'] ?? 0);
        foreach ($users as $u) {
            if ((int) $u['id'] === $creator) {
                return [$u];
            }
        }
        return $users;
    }

    /** "★★★★☆" */
    public static function stars(int $rating): string
    {
        $rating = max(0, min(5, $rating));
        return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
    }
}
