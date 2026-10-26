<?php
declare(strict_types=1);

namespace Quasar\Core;

use Quasar\Mail\Mail;

/**
 * Avisos a los usuarios: aparecen en la campana de la barra superior y, según la preferencia de cada
 * usuario (users.notify_email) y la configuración 'notifications', también por correo.
 *
 *   Notify::send([3, 7], [
 *       'type'   => 'issue.assigned',
 *       'title'  => ['issues:notify.assigned', ['id' => 12]],   // texto o [clave de idioma, variables]
 *       'body'   => 'No funciona el login',                      // opcional, igual que title
 *       'url'    => 'index.php?r=edit&m=issues&id=12',           // ruta interna
 *       'module' => 'issues', 'record' => '12',                  // opcional: agrupa los avisos del registro
 *       'actor'  => $user->id(),                                 // opcional: quién lo provoca (no se le avisa)
 *       'email'  => true,                                        // opcional: false = solo la campana
 *       'urgent' => false,                                       // opcional: true = correo aunque haya otro reciente
 *   ]);
 *
 * Los textos se traducen al idioma de cada destinatario. Correo:
 * - 'notifications.email' = 'instant' (al momento), 'queue' (lo envía bin/notifications.php desde el cron,
 *   agrupando los de cada usuario en un solo correo) u 'off'.
 * - No se repite el correo de un mismo registro mientras el usuario no haya leído el anterior y no hayan
 *   pasado 'throttle_minutes' (una conversación rápida no llena el buzón).
 */
final class Notifier
{
    private const MAX_TEXT = 1000;

    /** @param \Closure(string):string $absoluteUrl convierte "index.php?…" en una URL completa (para el correo) */
    public function __construct(
        private readonly Database $db,
        private readonly Lang $lang,
        private readonly array $config,
        private readonly \Closure $absoluteUrl,
    ) {
    }

    /**
     * @param list<int> $userIds
     * @return int avisos creados
     */
    public function send(array $userIds, array $n): int
    {
        $type = (string) ($n['type'] ?? 'info');
        if (!preg_match('/^[a-z0-9_.]{1,40}$/', $type)) {
            throw new \InvalidArgumentException("Tipo de aviso no válido: '$type'");
        }
        $url = isset($n['url']) ? (string) $n['url'] : null;
        if ($url !== null && !str_starts_with($url, 'index.php?')) {
            throw new \InvalidArgumentException('La URL de un aviso debe ser una ruta interna (index.php?…)');
        }
        $actor = isset($n['actor']) ? (int) $n['actor'] : null;
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn ($id) => $id > 0 && $id !== $actor)));
        if (!$ids) {
            return 0;
        }
        $mode = $this->emailMode();
        $wantsEmail = ($n['email'] ?? true) !== false && $mode !== 'off';
        $previous = $this->lang->locale();
        $created = 0;
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $users = $this->db->fetchAll("SELECT id, username, name, email, locale, notify_email FROM users WHERE active = 1 AND id IN ($placeholders)", $ids);
        try {
            foreach ($users as $u) {
                $this->lang->setLocale((string) ($u['locale'] ?: $previous));
                $title = mb_substr($this->text($n['title'] ?? $type), 0, 255);
                $body = isset($n['body']) ? mb_substr($this->text($n['body']), 0, self::MAX_TEXT) : null;
                $email = $wantsEmail && !empty($u['email']) && $u['notify_email'] !== 'off'
                    && (!empty($n['urgent']) || !$this->recentlyEmailed((int) $u['id'], $n['module'] ?? null, $n['record'] ?? null));
                $id = (int) $this->db->insert('notifications', [
                    'user_id' => (int) $u['id'],
                    'type' => $type,
                    'module' => $n['module'] ?? null,
                    'record_id' => isset($n['record']) ? (string) $n['record'] : null,
                    'actor_id' => $actor,
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                    'email_status' => $email ? 'pending' : 'none',
                ]);
                $created++;
                if ($email && $mode === 'instant') {
                    $this->mail($u, [['id' => $id, 'title' => $title, 'body' => $body, 'url' => $url]]);
                }
            }
        } finally {
            $this->lang->setLocale($previous);
        }
        Log::info('Avisos {type}: {n} usuario(s)', ['type' => $type, 'n' => $created, 'record' => ($n['module'] ?? '') . ':' . ($n['record'] ?? '')]);
        return $created;
    }

    /** Sin leer de un usuario. */
    public function unreadCount(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /**
     * Últimos avisos (los más recientes primero).
     * @return list<array<string,mixed>>
     */
    public function latest(int $userId, int $limit = 20, int $afterId = 0): array
    {
        return $this->db->fetchAll(sprintf(
            'SELECT n.id, n.type, n.title, n.body, n.url, n.created_at, n.read_at, a.name AS actor_name, a.username AS actor_username
               FROM notifications n LEFT JOIN users a ON a.id = n.actor_id
              WHERE n.user_id = ? AND n.id > ? ORDER BY n.id DESC LIMIT %d',
            max(1, min(100, $limit))
        ), [$userId, $afterId]);
    }

    public function get(int $userId, int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** Marca como leído un aviso o, sin $id, todos. */
    public function markRead(int $userId, ?int $id = null): void
    {
        $sql = 'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL';
        $this->db->query($id === null ? $sql : "$sql AND id = ?", $id === null ? [$userId] : [$userId, $id]);
    }

    /** Marca como leídos los avisos de un registro (p. ej. al abrir su conversación). */
    public function markRecordRead(int $userId, string $module, string $recordId): void
    {
        $this->db->query(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND module = ? AND record_id = ? AND read_at IS NULL',
            [$userId, $module, $recordId]
        );
    }

    /**
     * Envía los correos pendientes (modo 'queue'): uno por usuario con todos sus avisos.
     * @return array{users:int,notifications:int,failed:int}
     */
    public function sendPending(int $limit = 500): array
    {
        $rows = $this->db->fetchAll(sprintf(
            "SELECT n.id, n.user_id, n.title, n.body, n.url FROM notifications n
              WHERE n.email_status = 'pending' AND n.created_at > ? ORDER BY n.user_id, n.id LIMIT %d",
            max(1, $limit)
        ), [date('Y-m-d H:i:s', time() - 7 * 86400)]);
        $byUser = [];
        foreach ($rows as $r) {
            $byUser[(int) $r['user_id']][] = $r;
        }
        $stats = ['users' => 0, 'notifications' => 0, 'failed' => 0];
        $previous = $this->lang->locale();
        try {
            foreach ($byUser as $userId => $items) {
                $u = $this->db->fetch('SELECT id, username, name, email, locale, notify_email FROM users WHERE id = ? AND active = 1', [$userId]);
                if ($u === null || empty($u['email']) || $u['notify_email'] === 'off') {
                    $this->setEmailStatus(array_column($items, 'id'), 'skipped');
                    continue;
                }
                $this->lang->setLocale((string) ($u['locale'] ?: $previous));
                $ok = $this->mail($u, $items);
                $stats['users']++;
                $stats['notifications'] += count($items);
                $stats['failed'] += $ok ? 0 : count($items);
            }
        } finally {
            $this->lang->setLocale($previous);
        }
        return $stats;
    }

    // ---------------------------------------------------------------- correo

    private function emailMode(): string
    {
        $mode = (string) ($this->config['email'] ?? 'instant');
        return in_array($mode, ['instant', 'queue', 'off'], true) ? $mode : 'instant';
    }

    /** Si ya se le envió un correo de ese registro que aún no ha leído, hace menos de 'throttle_minutes'. */
    private function recentlyEmailed(int $userId, ?string $module, mixed $record): bool
    {
        $minutes = (int) ($this->config['throttle_minutes'] ?? 10);
        if ($module === null || $record === null || $minutes <= 0) {
            return false;
        }
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM notifications WHERE user_id = ? AND module = ? AND record_id = ? AND read_at IS NULL
                AND email_status IN ('sent', 'pending') AND created_at > ? LIMIT 1",
            [$userId, $module, (string) $record, date('Y-m-d H:i:s', time() - $minutes * 60)]
        );
    }

    /** @param list<array{id:int|string,title:string,body:?string,url:?string}> $items */
    private function mail(array $user, array $items): bool
    {
        $abs = $this->absoluteUrl;
        $items = array_map(static fn ($i) => $i + ['link' => $i['url'] !== null ? $abs((string) $i['url']) : null], $items);
        $first = $items[0];
        $single = count($items) === 1;
        $subject = $single ? $first['title'] : $this->lang->get('notifications.digest_subject', ['n' => count($items)]);
        try {
            $msg = Mail::compose('emails/notification', [
                'name' => (string) ($user['name'] ?: $user['username']),
                'items' => $items,
                'preheader' => $single ? (string) ($first['body'] ?? $first['title']) : $subject,
                'centerUrl' => $abs('index.php?r=profile'),
            ])->to((string) $user['email'], (string) ($user['name'] ?: $user['username']))->subject($subject);
            $ok = Mail::trySend($msg);
        } catch (\Throwable $e) {
            Log::error('Avisos: no se ha podido preparar el correo', ['exception' => $e]);
            $ok = false;
        }
        $this->setEmailStatus(array_column($items, 'id'), $ok ? 'sent' : 'failed');
        return $ok;
    }

    /** @param list<int|string> $ids */
    private function setEmailStatus(array $ids, string $status): void
    {
        if (!$ids) {
            return;
        }
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->query(
            "UPDATE notifications SET email_status = ?, emailed_at = IF(? = 'sent', NOW(), emailed_at) WHERE id IN ($ph)",
            [$status, $status, ...array_map('intval', $ids)]
        );
    }

    /** Texto tal cual o [clave de idioma, variables] traducido al idioma actual. */
    private function text(mixed $value): string
    {
        if (is_array($value)) {
            return $this->lang->get((string) ($value[0] ?? ''), $this->lang->vars((array) ($value[1] ?? [])));
        }
        return (string) $value;
    }
}
