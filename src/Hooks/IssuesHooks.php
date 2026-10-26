<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use Quasar\Core\Chat;
use Quasar\Core\HtmlSanitizer;
use Quasar\Core\ModuleHooks;
use Quasar\Core\Notify;
use Quasar\Core\User;

/**
 * Incidencias: el cliente sale del proyecto, se anota quién la crea y cuándo se resuelve, y cada
 * novedad (alta, asignación, cambio de estado, visible para el cliente) deja un apunte en la
 * conversación y avisa a quien corresponde.
 */
final class IssuesHooks extends ModuleHooks
{
    /** Registro antes de guardar (para comparar en afterSave) */
    private ?array $before = null;

    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        $this->before = $existing;
        if (!empty($data['project_id'])) {
            $data['client_id'] = $this->db->fetchValue('SELECT client_id FROM projects WHERE id = ?', [(int) $data['project_id']]);
        }
        if ($id === null) {
            $data['created_by'] = $user->id();
            $data['created_by_client'] = Asteroid::isClient($user) ? 1 : 0;
        }
        if (array_key_exists('status', $data)) {
            $resolved = (int) $data['status'] === Asteroid::STATUS_RESOLVED;
            $wasResolved = (int) ($existing['status'] ?? 0) === Asteroid::STATUS_RESOLVED;
            if ($resolved && !$wasResolved) {
                $data['resolved_at'] = date('Y-m-d H:i:s');
            } elseif (!$resolved) {
                $data['resolved_at'] = null;
            }
        }
        return null;
    }

    public function afterSave(string $id, array $data, bool $isNew, User $user): void
    {
        $issue = Asteroid::issue($this->db, $id);
        if ($issue === null) {
            return;
        }
        $record = '#' . $id . ' · ' . $issue['title'];
        $visible = (int) $issue['client_visible'] === 1;

        if ($isNew) {
            Chat::system('issues', $id, ['issues:log.created', ['user' => $user->name()]], $user->id());
            $excerpt = mb_substr(HtmlSanitizer::toText((string) $issue['description']), 0, 300);
            if ((int) $issue['created_by_client'] === 1) {
                // Del cliente: al técnico asignado o, si no hay, a todo el equipo
                Notify::send($issue['worker_id'] ? [(int) $issue['worker_id']] : Asteroid::teamIds($this->db), [
                    'type' => 'issue.new',
                    'title' => ['issues:notify.new_from_client', ['client' => (string) $issue['client_name'], 'record' => $record]],
                    'body' => $excerpt,
                    'url' => Asteroid::issueUrl($id),
                    'module' => 'issues',
                    'record' => $id,
                    'actor' => $user->id(),
                    'urgent' => (int) $issue['priority'] === 2,
                ]);
            } elseif ($issue['worker_id'] && (int) $issue['worker_id'] !== $user->id()) {
                Asteroid::assigned($this->db, $issue, $user);
            }
            if ($visible && (int) $issue['created_by_client'] === 0) {
                $this->notifyClient($issue, $user, 'opened');
            }
            return;
        }

        $before = $this->before ?? [];
        if (array_key_exists('worker_id', $data) && (string) ($before['worker_id'] ?? '') !== (string) ($issue['worker_id'] ?? '') && $issue['worker_id']) {
            Asteroid::assigned($this->db, $issue, $user);
        }
        if (array_key_exists('status', $data) && (int) ($before['status'] ?? 0) !== (int) $issue['status']) {
            Asteroid::statusChanged($this->db, $issue, (int) ($before['status'] ?? 0), (int) $issue['status'], $user);
        }
        if ($visible && array_key_exists('client_visible', $data) && (int) ($before['client_visible'] ?? 0) === 0) {
            Chat::system('issues', $id, ['issues:log.shared', ['user' => $user->name()]], $user->id());
            $this->notifyClient($issue, $user, 'opened');
        }
    }

    /** Los usuarios del cliente reciben los mensajes de las incidencias que ven. */
    public function chatRecipients(string $id, array $row, User $author): array
    {
        $issue = Asteroid::issue($this->db, $id);
        if ($issue === null) {
            return [];
        }
        $ids = Asteroid::clientRecipients($this->db, $issue);
        // Sin técnico asignado, un mensaje del cliente llega a todo el equipo
        if (!$issue['worker_id'] && Asteroid::isClient($author)) {
            array_push($ids, ...Asteroid::teamIds($this->db));
        }
        return $ids;
    }

    private function notifyClient(array $issue, User $user, string $kind): void
    {
        $id = (string) $issue['id'];
        Notify::send(Asteroid::clientRecipients($this->db, $issue), [
            'type' => 'issue.' . $kind,
            'title' => ['issues:notify.' . $kind, ['record' => '#' . $id . ' · ' . $issue['title']]],
            'body' => mb_substr(HtmlSanitizer::toText((string) $issue['description']), 0, 300),
            'url' => Asteroid::issueUrl($id),
            'module' => 'issues',
            'record' => $id,
            'actor' => $user->id(),
        ]);
    }
}
