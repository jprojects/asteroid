<?php
declare(strict_types=1);

namespace Quasar\Core;

use Quasar\Fields\MediaField;

/**
 * Conversación de los registros de los módulos con "chat" (ver Module::chatConfig):
 * mensajes, adjuntos, leído/no leído por usuario, menciones, videollamadas y avisos.
 *
 * Todo pasa por record(): solo puede leer o escribir quien ve el módulo, el registro ("scope") y la
 * conversación ("chat.access"). Los avisos solo llegan a usuarios que cumplen lo mismo.
 */
final class ChatService
{
    public const MEDIA_FIELD = '_chat';
    private const PAGE = 50;
    private const MAX_BODY = 5000;
    /** Segundos desde la última consulta en que se considera que alguien tiene la conversación abierta */
    private const LIVE_SECONDS = 25;

    /** @var array<int,?User> */
    private array $users = [];

    public function __construct(private readonly App $app)
    {
    }

    /** El registro (valores del formulario) si $user puede usar su conversación; si no, null. */
    public function record(Module $m, string $id, User $user): ?array
    {
        if ($m->chatConfig() === null || !ModuleRegistry::canAccess($user, $m, 'view') || !$m->chatAllows($user)) {
            return null;
        }
        return (new FormHandler($m, $this->app->db))->load($id);
    }

    /**
     * Mensajes en orden cronológico: los últimos, los posteriores a $after o una página anterior a $before.
     * @return list<array<string,mixed>>
     */
    public function messages(Module $m, string $id, User $me, int $after = 0, int $before = 0): array
    {
        $sql = 'SELECT m.*, u.name AS user_name, u.username AS user_username FROM messages m
                  LEFT JOIN users u ON u.id = m.user_id WHERE m.module = ? AND m.record_id = ?';
        $params = [$m->name, $id];
        if ($after > 0) {
            $rows = $this->app->db->fetchAll("$sql AND m.id > ? ORDER BY m.id LIMIT 200", [...$params, $after]);
        } else {
            $rows = $before > 0
                ? $this->app->db->fetchAll("$sql AND m.id < ? ORDER BY m.id DESC LIMIT " . self::PAGE, [...$params, $before])
                : $this->app->db->fetchAll("$sql ORDER BY m.id DESC LIMIT " . self::PAGE, $params);
            $rows = array_reverse($rows);
        }
        $mediaIds = [];
        foreach ($rows as $r) {
            array_push($mediaIds, ...self::ids($r['media']));
        }
        $media = array_column($this->app->media()->find($mediaIds), null, 'id');
        return array_map(fn (array $r) => $this->present($r, $me, $media), $rows);
    }

    /** Si hay mensajes anteriores al primero cargado. */
    public function hasOlder(Module $m, string $id, int $firstId): bool
    {
        return $firstId > 0 && (bool) $this->app->db->fetchValue(
            'SELECT 1 FROM messages WHERE module = ? AND record_id = ? AND id < ? LIMIT 1',
            [$m->name, $id, $firstId]
        );
    }

    /**
     * Publica un mensaje y avisa a los participantes.
     * @param list<int> $mediaIds adjuntos ya subidos por el autor (upload())
     * @param list<int> $mentions usuarios mencionados con @ (los elige el navegador; se comprueban aquí)
     * @return array<string,mixed> el mensaje
     * @throws \InvalidArgumentException con clave de idioma si el mensaje no es válido
     */
    public function post(Module $m, string $id, array $row, User $author, string $body, array $mediaIds = [], array $mentions = []): array
    {
        $body = trim(str_replace(["\r\n", "\r"], "\n", $body));
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new \InvalidArgumentException('chat.too_long');
        }
        $mediaIds = $this->ownMedia($m, $author, $mediaIds);
        if ($body === '' && !$mediaIds) {
            throw new \InvalidArgumentException('chat.empty_message');
        }
        $msgId = $this->insert($m->name, $id, $author->id(), 'text', $body, $mediaIds);
        $this->markRead($m, $id, $author->id(), $msgId, false);

        $excerpt = $body !== '' ? $body : implode(', ', array_column($this->app->media()->find($mediaIds), 'name'));
        $this->notify($m, $id, $row, $author, $excerpt, array_map('intval', $mentions), 'message');
        return $this->messages($m, $id, $author, $msgId - 1)[0];
    }

    /**
     * Mensaje de videollamada (Google Meet) y aviso urgente a los participantes.
     * @param array{uri:string,code:?string} $meeting
     */
    public function postMeeting(Module $m, string $id, array $row, User $author, array $meeting): array
    {
        $msgId = $this->insert($m->name, $id, $author->id(), 'meet', null, [], $meeting);
        $this->markRead($m, $id, $author->id(), $msgId, false);
        $this->notify($m, $id, $row, $author, $meeting['uri'], [], 'meet');
        return $this->messages($m, $id, $author, $msgId - 1)[0];
    }

    /**
     * Apunte del sistema en la conversación (p. ej. "Estado: Resuelta"), sin avisos. Lo usan los hooks:
     *   Chat::system('issues', $id, 'Factura 25/012 enviada', $user->id());
     *   Chat::system('issues', $id, ['issues:log.status', ['to' => ['issues:status.resolved']]], $user->id());
     * Con [clave, variables] se traduce al idioma de quien lo lee; una variable [clave] también se traduce.
     */
    public function system(string $module, string $id, string|array $text, ?int $actorId = null): int
    {
        if (is_array($text)) {
            return $this->insert($module, $id, $actorId, 'system', null, [], ['t' => (string) ($text[0] ?? ''), 'v' => (array) ($text[1] ?? [])]);
        }
        return $this->insert($module, $id, $actorId, 'system', mb_substr(trim($text), 0, self::MAX_BODY), []);
    }

    /** Borra (vacía) un mensaje propio, o cualquiera si es administrador. */
    public function delete(Module $m, string $id, int $messageId, User $user): bool
    {
        $msg = $this->app->db->fetch('SELECT * FROM messages WHERE id = ? AND module = ? AND record_id = ?', [$messageId, $m->name, $id]);
        if ($msg === null || $msg['deleted_at'] !== null || $msg['kind'] === 'system'
            || ((int) $msg['user_id'] !== $user->id() && !$user->isAdmin())) {
            return false;
        }
        $this->app->db->query('UPDATE messages SET body = NULL, media = NULL, meta = NULL, deleted_at = NOW() WHERE id = ?', [$messageId]);
        return true;
    }

    /** Sube un adjunto de la conversación (queda del autor hasta que lo publica en un mensaje). */
    public function upload(Module $m, array $file, User $user): array
    {
        $cfg = $m->chatConfig();
        if ($cfg === null || !$cfg['attachments']) {
            throw new MediaException('media.type');
        }
        $field = new MediaField(self::MEDIA_FIELD, ['type' => 'media', 'maxSize' => $cfg['maxSize']], $this->app->fieldContextFactory()($m->name));
        return $this->app->media()->store($file, $m->name, $field, $user->id());
    }

    /**
     * Hasta qué mensaje ha leído el usuario. Con $live (el navegador la tiene a la vista), además cuenta
     * como "tiene la conversación abierta" durante unos segundos: no se le avisa de lo que ya está viendo.
     */
    public function markRead(Module $m, string $id, int $userId, int $lastId, bool $live = true): void
    {
        $this->app->db->query(
            'INSERT INTO message_reads (user_id, module, record_id, last_read_id, seen_at) VALUES (?, ?, ?, ?, ' . ($live ? 'NOW()' : "'2000-01-01'") . ')
             ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))' . ($live ? ', seen_at = NOW()' : ''),
            [$userId, $m->name, $id, $lastId]
        );
        $this->app->notifier()->markRecordRead($userId, $m->name, $id);
    }

    /** Último mensaje que el usuario había leído (antes de abrir la conversación). */
    public function lastRead(Module $m, string $id, int $userId): int
    {
        return (int) $this->app->db->fetchValue(
            'SELECT last_read_id FROM message_reads WHERE user_id = ? AND module = ? AND record_id = ?',
            [$userId, $m->name, $id]
        );
    }

    public function setMuted(Module $m, string $id, int $userId, bool $muted): void
    {
        $this->app->db->query(
            'INSERT INTO message_reads (user_id, module, record_id, muted) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE muted = VALUES(muted)',
            [$userId, $m->name, $id, (int) $muted]
        );
    }

    public function isMuted(Module $m, string $id, int $userId): bool
    {
        return (bool) $this->app->db->fetchValue(
            'SELECT muted FROM message_reads WHERE user_id = ? AND module = ? AND record_id = ?',
            [$userId, $m->name, $id]
        );
    }

    /**
     * Hasta dónde ha leído cada uno de los demás participantes (para "visto"). Solo quienes pueden ver el
     * registro ahora: si alguien pierde el acceso (otro cliente, visibilidad retirada…), deja de aparecer.
     * @return list<array{user:int,name:string,lastRead:int}>
     */
    public function reads(Module $m, string $id, User $me): array
    {
        $rows = $this->app->db->fetchAll(
            'SELECT r.user_id, r.last_read_id, u.name, u.username FROM message_reads r JOIN users u ON u.id = r.user_id
              WHERE r.module = ? AND r.record_id = ? AND r.user_id <> ? AND r.last_read_id > 0 AND u.active = 1',
            [$m->name, $id, $me->id()]
        );
        $out = [];
        foreach ($rows as $r) {
            $u = $this->user((int) $r['user_id']);
            if ($u !== null && $this->canSee($m, $id, $u)) {
                $out[] = ['user' => $u->id(), 'name' => (string) ($r['name'] ?: $r['username']), 'lastRead' => (int) $r['last_read_id']];
            }
        }
        return $out;
    }

    /**
     * Mensajes y no leídos por registro para el listado.
     * @param list<string> $ids
     * @return array<string,array{0:int,1:int}> id => [total, sin leer]
     */
    public function counts(Module $m, array $ids, User $user): array
    {
        if (!$ids || !$m->chatAllows($user)) {
            return [];
        }
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->app->db->fetchAll(
            "SELECT m.record_id, COUNT(*) AS total,
                    SUM(m.id > COALESCE(r.last_read_id, 0) AND (m.user_id IS NULL OR m.user_id <> ?) AND m.kind <> 'system') AS unread
               FROM messages m
               LEFT JOIN message_reads r ON r.user_id = ? AND r.module = m.module AND r.record_id = m.record_id
              WHERE m.module = ? AND m.record_id IN ($ph) AND m.deleted_at IS NULL AND m.kind <> 'system'
              GROUP BY m.record_id",
            [$user->id(), $user->id(), $m->name, ...$ids]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['record_id']] = [(int) $r['total'], (int) $r['unread']];
        }
        return $out;
    }

    /**
     * Usuarios que se pueden mencionar: los que pueden ver el registro y su conversación.
     * @return list<array{id:int,name:string,username:string}>
     */
    public function people(Module $m, string $id, User $me): array
    {
        $out = [];
        foreach ($this->app->db->fetchAll('SELECT id FROM users WHERE active = 1 ORDER BY name, username LIMIT 300') as $r) {
            $u = $this->user((int) $r['id']);
            if ($u !== null && $u->id() !== $me->id() && $this->canSee($m, $id, $u)) {
                $out[] = ['id' => $u->id(), 'name' => $u->name(), 'username' => $u->username()];
            }
        }
        return $out;
    }

    /** Si un archivo de las conversaciones se le puede servir a $user. */
    public function canSeeMedia(array $mediaRow, User $user): bool
    {
        if ((int) $mediaRow['user_id'] === $user->id()) {
            return true; // subido por él (aún sin publicar, o publicado)
        }
        $msg = $this->app->db->fetch(
            'SELECT module, record_id FROM messages WHERE module = ? AND JSON_CONTAINS(media, ?) LIMIT 1',
            [(string) $mediaRow['module'], (string) (int) $mediaRow['id']]
        );
        $m = $msg !== null ? $this->app->modules()->get((string) $msg['module']) : null;
        return $m !== null && $this->record($m, (string) $msg['record_id'], $user) !== null;
    }

    // ---------------------------------------------------------------- piezas

    /** @param list<int> $media */
    private function insert(string $module, string $id, ?int $userId, string $kind, ?string $body, array $media, array $meta = []): int
    {
        return (int) $this->app->db->insert('messages', [
            'module' => $module,
            'record_id' => $id,
            'user_id' => $userId,
            'kind' => $kind,
            'body' => $body,
            'media' => $media ? json_encode(array_values($media)) : null,
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /**
     * Adjuntos válidos: archivos de las conversaciones de este módulo, subidos por el autor y que no
     * estén ya en otro mensaje.
     * @return list<int>
     */
    private function ownMedia(Module $m, User $author, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if (!$ids) {
            return [];
        }
        if (!($m->chatConfig()['attachments'] ?? false)) {
            throw new \InvalidArgumentException('chat.no_attachments');
        }
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $owned = array_map('intval', array_column($this->app->db->fetchAll(
            "SELECT id FROM media WHERE id IN ($ph) AND module = ? AND field = ? AND user_id = ?",
            [...$ids, $m->name, self::MEDIA_FIELD, $author->id()]
        ), 'id'));
        foreach ($owned as $mid) {
            if ($this->app->db->fetchValue('SELECT 1 FROM messages WHERE module = ? AND JSON_CONTAINS(media, ?) LIMIT 1', [$m->name, (string) $mid])) {
                throw new \InvalidArgumentException('chat.attachment_used');
            }
        }
        return array_values(array_intersect($ids, $owned));
    }

    /**
     * Avisa de un mensaje: campos de "chat.notify", quienes ya han escrito, el hook chatRecipients() y
     * los mencionados. Se omite a quien la tiene silenciada (salvo mención) o abierta en ese momento.
     * @param list<int> $mentions
     */
    private function notify(Module $m, string $id, array $row, User $author, string $excerpt, array $mentions, string $kind): void
    {
        $cfg = $m->chatConfig() ?? [];
        $candidates = [];
        foreach ($cfg['notify'] ?? [] as $field) {
            foreach ((array) ($row[$field] ?? []) as $v) {
                if (is_numeric($v)) {
                    $candidates[] = (int) $v;
                }
            }
        }
        foreach ($this->app->db->fetchAll(
            "SELECT DISTINCT user_id FROM messages WHERE module = ? AND record_id = ? AND user_id IS NOT NULL AND kind <> 'system'",
            [$m->name, $id]
        ) as $r) {
            $candidates[] = (int) $r['user_id'];
        }
        array_push($candidates, ...array_map('intval', $m->hooks($this->app->db)?->chatRecipients($id, $row, $author) ?? []));
        array_push($candidates, ...$mentions);

        $state = [];
        foreach ($this->app->db->fetchAll('SELECT user_id, muted, seen_at FROM message_reads WHERE module = ? AND record_id = ?', [$m->name, $id]) as $r) {
            $state[(int) $r['user_id']] = $r;
        }
        $live = date('Y-m-d H:i:s', time() - self::LIVE_SECONDS);
        $byType = ['message' => [], 'mention' => [], 'meet' => []];
        foreach (array_unique($candidates) as $uid) {
            $u = $uid !== $author->id() ? $this->user($uid) : null;
            if ($u === null || !$this->canSee($m, $id, $u)) {
                continue;
            }
            $mentioned = in_array($uid, $mentions, true);
            $s = $state[$uid] ?? null;
            if ($kind !== 'meet' && $s !== null && (string) $s['seen_at'] > $live) {
                continue; // la está leyendo ahora mismo
            }
            if ($kind !== 'meet' && !$mentioned && $s !== null && (int) $s['muted'] === 1) {
                continue;
            }
            $byType[$kind === 'meet' ? 'meet' : ($mentioned ? 'mention' : 'message')][] = $uid;
        }

        $record = '#' . $id . (($t = $m->recordTitle($row)) !== null ? ' · ' . $t : '');
        $url = $this->app->url('edit', ['m' => $m->name, 'id' => $id]) . '#chat';
        foreach ($byType as $type => $ids) {
            if (!$ids) {
                continue;
            }
            $this->app->notifier()->send($ids, [
                'type' => 'chat.' . $type,
                'title' => ['notifications.chat_' . $type, ['user' => $author->name(), 'record' => $record, 'module' => $m->title()]],
                'body' => mb_substr($excerpt, 0, 300),
                'url' => $url,
                'module' => $m->name,
                'record' => $id,
                'actor' => $author->id(),
                'urgent' => $type !== 'message',
            ]);
        }
    }

    /** Si $u puede ver el módulo, la conversación y el registro. */
    private function canSee(Module $m, string $id, User $u): bool
    {
        return ModuleRegistry::canAccess($u, $m, 'view') && $m->chatAllows($u) && $m->isVisible($id, $u);
    }

    private function user(int $id): ?User
    {
        if (!array_key_exists($id, $this->users)) {
            $this->users[$id] = $this->app->auth->userById($id);
        }
        return $this->users[$id];
    }

    /** @param array<int,array> $media info de los archivos por id */
    private function present(array $r, User $me, array $media): array
    {
        $deleted = $r['deleted_at'] !== null;
        $name = (string) ($r['user_name'] ?: $r['user_username'] ?: '');
        if ($r['kind'] === 'system' && $r['body'] === null && $r['meta'] !== null) {
            $meta = (array) json_decode((string) $r['meta'], true);
            $r['body'] = $this->app->lang->get((string) ($meta['t'] ?? ''), $this->app->lang->vars((array) ($meta['v'] ?? [])));
            $r['meta'] = null;
        }
        return [
            'id' => (int) $r['id'],
            'kind' => (string) $r['kind'],
            'user' => $r['user_id'] !== null ? [
                'id' => (int) $r['user_id'],
                'name' => $name,
                'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            ] : null,
            'mine' => $r['user_id'] !== null && (int) $r['user_id'] === $me->id(),
            'body' => $deleted ? null : $r['body'],
            'media' => $deleted ? [] : array_values(array_filter(array_map(static fn ($i) => $media[$i] ?? null, self::ids($r['media'])))),
            'meta' => $deleted || $r['meta'] === null ? null : json_decode((string) $r['meta'], true),
            'at' => date('c', strtotime((string) $r['created_at'])),
            'deleted' => $deleted,
            'canDelete' => !$deleted && $r['kind'] !== 'system' && ((int) $r['user_id'] === $me->id() || $me->isAdmin()),
        ];
    }

    /** @return list<int> */
    private static function ids(mixed $json): array
    {
        return $json === null ? [] : array_map('intval', (array) json_decode((string) $json, true));
    }
}
