<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\FormHandler;
use Quasar\Core\HtmlSanitizer;
use Quasar\Core\Log;
use Quasar\Core\ModuleRegistry;
use Quasar\Core\User;
use Quasar\Hooks\Asteroid;
use Quasar\Telegram\Telegram;

/**
 * Asteroid · webhook del bot de Telegram (modules/telegram_hook.json, página pública):
 * index.php?r=public&m=telegram_hook. Telegram envía cada mensaje con la cabecera X-Telegram-Bot-Api-Secret-Token
 * (el 'secret' de la configuración); sin ella no se hace nada. Si el hosting no deja entrar a Telegram, el modo
 * consulta ('mode' => 'poll') hace lo mismo desde el cron con tools/telegram-poll.php (process()).
 *
 * Quien escribe tiene que tener su cuenta de Telegram vinculada a un usuario del equipo (Equip → Telegram):
 * todo se hace como ese usuario, con sus permisos, y deja los mismos avisos que en la web.
 *   - Respuesta a un aviso del bot → mensaje en la conversación de esa incidencia.
 *   - Botones de los avisos (callback()): imputar horas a la incidencia (15 min … 4 h; luego, respondiendo a la
 *     confirmación, la descripción o otro tiempo), asignársela y resolverla.
 *   - /pissarra [nom|totes|sense] · /meves · /noves · /incidencia N · /agafa N · /resol N · /vincular CODI · /ajuda
 *   - /nota TEXT (nota personal; respondiendo a un aviso, enlazada a esa incidencia) · /notes (las pendientes, con
 *     un botón «Feta» cada una)
 */
final class TelegramHookPage extends Page
{
    private const LIST_LIMIT = 10;
    /** Tiempos de los botones de «Imputa hores», en minutos (dos filas). */
    private const DURATIONS = [[15, 30, 45, 60], [90, 120, 180, 240]];

    private array $msg = [];
    /** Mientras se atiende un botón: su id (los avisos van en la respuesta al botón, no al grupo). */
    private ?string $callbackId = null;

    public function index(): void
    {
        // Telegram reintenta si no recibe un 200: se contesta siempre 200 y los errores van al log
        http_response_code(200);
        $c = Telegram::config();
        $secret = (string) ($c['secret'] ?? '');
        $header = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !Telegram::enabled() || $secret === '' || !hash_equals($secret, $header)) {
            http_response_code(403);
            return;
        }
        $update = json_decode((string) file_get_contents('php://input'), true);
        if (is_array($update)) {
            $this->process($update);
        }
    }

    /** Atiende una actualización de Telegram (del webhook o de tools/telegram-poll.php en el modo consulta). */
    public function process(array $update): void
    {
        if (is_array($update['callback_query'] ?? null)) {
            $this->callback($update['callback_query']);
            return;
        }
        $msg = is_array($update['message'] ?? null) ? $update['message'] : null;
        if ($msg === null || !isset($msg['chat']['id'], $msg['from']['id'])) {
            return;
        }
        $this->msg = $msg;
        try {
            $this->handle(trim((string) ($msg['text'] ?? $msg['caption'] ?? '')));
        } catch (\Throwable $e) {
            Log::error('Telegram: error al atender un mensaje', ['exception' => $e]);
            $this->reply(Telegram::e(Telegram::t('bot.error')));
        }
    }

    private function handle(string $text): void
    {
        if (str_starts_with($text, '/')) {
            [$cmd, $args] = array_pad(preg_split('/\s+/', $text, 2), 2, '');
            $cmd = strtolower(preg_replace('/@.*$/', '', substr($cmd, 1)) ?? '');
            $args = trim($args);
            if ($cmd === 'start' && $args !== '') {
                $this->link($args); // enlace t.me/bot?start=CODI
                return;
            }
            if (in_array($cmd, ['start', 'ajuda', 'help'], true)) {
                $this->reply($this->help());
                return;
            }
            if ($cmd === 'vincular') {
                $this->link($args);
                return;
            }
            $user = $this->linkedUser();
            if ($user === null) {
                return;
            }
            match ($cmd) {
                'desvincular' => $this->unlink($user),
                'pissarra' => $this->pissarra($user, $args),
                'meves' => $this->issues($user, 'mine'),
                'noves' => $this->issues($user, 'unassigned'),
                'incidencia', 'i' => $this->issue($user, $args),
                'agafa' => $this->update($user, $args, ['worker_id' => (string) $user->id()], 'bot.taken'),
                'resol' => $this->update($user, $args, ['status' => (string) Asteroid::STATUS_RESOLVED], 'bot.resolved'),
                'hores' => $this->hoursCommand($user, $args),
                'nota' => $this->createNote($user, $args),
                'notes' => $this->listNotes($user),
                default => $this->reply(Telegram::e(Telegram::t('bot.unknown'))),
            };
            return;
        }

        // Respuesta a un aviso del bot → a la conversación de su incidencia (o a las horas imputadas desde aquí)
        $replyTo = $this->msg['reply_to_message']['message_id'] ?? null;
        if ($replyTo === null || $text === '') {
            return;
        }
        $ref = $this->app->db->fetch(
            'SELECT module, record_id FROM telegram_messages WHERE chat_id = ? AND message_id = ?',
            [(string) $this->msg['chat']['id'], (int) $replyTo]
        );
        if ($ref === null || !in_array($ref['module'], ['issues', 'hours'], true)) {
            return;
        }
        $user = $this->linkedUser();
        if ($user !== null) {
            $ref['module'] === 'hours' ? $this->editHours($user, (string) $ref['record_id'], $text) : $this->comment($user, (string) $ref['record_id'], $text);
        }
    }

    // ---------------------------------------------------------------- botones

    /**
     * Botón de un mensaje del bot. callback_data: "h:ID" (pregunta cuánto tiempo), "h:ID:MIN:TG" (imputa MIN minutos;
     * solo quien lo pidió, TG), "x:TG" (cancela la pregunta), "t:ID" (asignársela), "r:ID" (resolverla).
     */
    private function callback(array $cq): void
    {
        if (!isset($cq['id'], $cq['from']['id'], $cq['message']['chat']['id'], $cq['message']['message_id'])) {
            return;
        }
        $this->callbackId = (string) $cq['id'];
        // Las respuestas van al mensaje del botón; quien actúa es quien lo ha pulsado
        $this->msg = ['from' => $cq['from']] + $cq['message'];
        try {
            $parts = explode(':', (string) ($cq['data'] ?? ''));
            $owner = isset($parts[3]) ? (int) $parts[3] : (($parts[0] ?? '') === 'x' ? (int) ($parts[1] ?? 0) : null);
            if ($owner !== null && $owner !== (int) $cq['from']['id']) {
                $name = (string) $this->app->db->fetchValue('SELECT telegram_name FROM telegram_users WHERE telegram_id = ?', [$owner]);
                $this->notice(Telegram::t('bot.not_yours', ['user' => $name !== '' ? $name : '?']));
                return;
            }
            if ($parts[0] === 'x') {
                Telegram::bot()->call('deleteMessage', ['chat_id' => $this->msg['chat']['id'], 'message_id' => (int) $this->msg['message_id']]);
                return;
            }
            $user = $this->linkedUser();
            if ($user === null) {
                return;
            }
            $id = $parts[1] ?? '';
            match ($parts[0]) {
                'h' => isset($parts[2]) ? $this->logHours($user, $id, Telegram::duration(max(0, (int) $parts[2])), true) : $this->askHours($user, $id),
                't' => $this->update($user, $id, ['worker_id' => (string) $user->id()], 'bot.taken_by'),
                'r' => $this->update($user, $id, ['status' => (string) Asteroid::STATUS_RESOLVED], 'bot.resolved_by'),
                'n' => $this->noteDone($user, $id),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Telegram: error al atender un botón', ['exception' => $e]);
            $this->notice(Telegram::t('bot.error'));
        } finally {
            if ($this->callbackId !== null) {
                Telegram::bot()->answer($this->callbackId); // si no se ha contestado ya, quita el «cargando»
            }
            $this->callbackId = null;
        }
    }

    /** «Imputa hores» (o /hores N): pregunta cuánto tiempo, con botones que solo puede usar quien lo ha pedido. */
    private function askHours(User $user, string $issueId): void
    {
        [, $row] = $this->loadIssue($user, $issueId) ?? [null, null];
        $hours = $this->app->modules()->get('hours');
        if ($row === null) {
            return;
        }
        if ($hours === null || !ModuleRegistry::canAccess($user, $hours, 'create')) {
            $this->notice(Telegram::t('bot.forbidden'));
            return;
        }
        $tg = (int) $this->msg['from']['id'];
        $buttons = array_map(static fn (array $line) => array_map(static fn (int $min) => [
            'text' => Telegram::duration($min), 'callback_data' => 'h:' . (int) $row['id'] . ':' . $min . ':' . $tg,
        ], $line), self::DURATIONS);
        $buttons[] = [['text' => Telegram::t('btn.cancel'), 'callback_data' => 'x:' . $tg]];
        $this->reply(Telegram::e(Telegram::t('bot.hours_ask', ['id' => $row['id'], 'title' => $row['title'], 'user' => $user->name()])), $buttons);
    }

    /** /hores N [temps] [descripció]: con tiempo, lo imputa directamente; sin él, pregunta con botones. */
    private function hoursCommand(User $user, string $args): void
    {
        [$id, $time, $comment] = array_pad(preg_split('/\s+/', trim($args), 3) ?: [], 3, '');
        if ($time === '') {
            $this->askHours($user, $id);
            return;
        }
        $this->logHours($user, $id, $time, false, $comment);
    }

    /**
     * Apunta las horas (hoy, del usuario, de esa incidencia y su proyecto). Desde un botón ($button) lo dice en el mensaje
     * de la pregunta; desde /hores, en una respuesta. A ese mensaje se le puede responder para cambiarlas.
     * @param string $time como en el formulario: 1:30, 45m, 2h
     */
    private function logHours(User $user, string $issueId, string $time, bool $button, string $comment = ''): void
    {
        $chat = (string) $this->msg['chat']['id'];
        $msgId = (int) $this->msg['message_id'];
        // Dos pulsaciones seguidas (o el mismo botón en otro dispositivo): una sola imputación
        if ($this->app->db->fetchValue('SELECT 1 FROM telegram_messages WHERE chat_id = ? AND message_id = ?', [$chat, $msgId]) !== null) {
            $this->notice(Telegram::t('bot.already'));
            return;
        }
        [, $row] = $this->loadIssue($user, $issueId) ?? [null, null];
        $hours = $this->app->modules()->get('hours');
        if ($row === null) {
            return;
        }
        if ($hours === null || !ModuleRegistry::canAccess($user, $hours, 'create')) {
            $this->notice(Telegram::t('bot.forbidden'));
            return;
        }
        $result = (new FormHandler($hours, $this->app->db))->process([
            'worked_on' => date('Y-m-d'),
            'user_id' => (string) $user->id(),
            'minutes' => $time,
            'billing' => '0',
            'issue_id' => (string) $row['id'],
        ] + ($comment !== '' ? ['comment' => mb_substr($comment, 0, 2000)] : []), null, $user, true);
        if (!$result['ok']) {
            $this->notice(Telegram::t('bot.not_saved', ['error' => implode(' · ', $result['errors'])]));
            return;
        }
        $minutes = (int) $this->app->db->fetchValue('SELECT minutes FROM hours WHERE id = ?', [(int) $result['id']]);
        Log::info('Telegram: {user} imputa {min} min a la incidencia {id}', ['user' => $user->id(), 'min' => $minutes, 'id' => $row['id']]);
        $vars = ['id' => $row['id'], 'title' => $row['title'], 'user' => $user->name(), 'time' => Telegram::duration($minutes)];
        $html = '<b>' . Telegram::e(Telegram::t('bot.hours_done', $vars)) . "</b>\n" . Telegram::e(Telegram::t('bot.hours_hint'));
        if ($button) {
            Telegram::remember($this->app->db, $chat, $msgId, (string) $result['id'], 'hours');
            Telegram::bot()->edit($chat, $msgId, $html);
            $this->notice(Telegram::t('bot.hours_done', $vars), false);
        } elseif (($reply = $this->reply($html)) !== null) {
            Telegram::remember($this->app->db, $chat, $reply, (string) $result['id'], 'hours');
        }
    }

    /** Respuesta a la confirmación de unas horas: un tiempo (1:15, 45m, 2h) cambia la duración; otro texto es la descripción. */
    private function editHours(User $user, string $hoursId, string $text): void
    {
        $m = $this->app->modules()->get('hours');
        $row = $m !== null ? $this->app->db->fetch('SELECT id, user_id, issue_id, minutes FROM hours WHERE id = ?', [(int) $hoursId]) : null;
        if ($row === null || (int) $row['user_id'] !== $user->id() || !ModuleRegistry::canAccess($user, $m, 'edit')) {
            $this->reply(Telegram::e(Telegram::t('bot.forbidden')));
            return;
        }
        $isTime = (bool) preg_match('/^\s*(\d{1,2}:[0-5]\d|\d{1,2}([.,]\d+)?\s*h(\s*\d{1,2}\s*m?)?|\d{1,4}\s*m(in)?)\s*$/i', $text);
        $values = $isTime ? ['minutes' => trim($text)] : ['comment' => mb_substr($text, 0, 2000)];
        $result = (new FormHandler($m, $this->app->db))->process($values, (string) $row['id'], $user, true);
        if (!$result['ok']) {
            $this->reply(Telegram::e(Telegram::t('bot.not_saved', ['error' => implode(' · ', $result['errors'])])));
            return;
        }
        $minutes = (int) $this->app->db->fetchValue('SELECT minutes FROM hours WHERE id = ?', [(int) $row['id']]);
        $this->reply(Telegram::e(Telegram::t($isTime ? 'bot.hours_time' : 'bot.hours_comment', ['id' => $row['issue_id'], 'time' => Telegram::duration($minutes)])));
    }

    /** Aviso a quien ha escrito o pulsado: en un botón, como respuesta al botón (ventana si $alert); si no, en el chat. */
    private function notice(string $text, bool $alert = true): void
    {
        if ($this->callbackId !== null) {
            Telegram::bot()->answer($this->callbackId, $text, $alert);
            $this->callbackId = null;
            return;
        }
        $this->reply(Telegram::e($text));
    }

    // ---------------------------------------------------------------- notas (modules/notes.json)

    /** /nota TEXT: nota personal. Respondiendo a un aviso de una incidencia, queda enlazada a ella y a su cliente. */
    private function createNote(User $user, string $text): void
    {
        $m = $this->app->modules()->get('notes');
        if ($m === null || !ModuleRegistry::canAccess($user, $m, 'create')) {
            $this->reply(Telegram::e(Telegram::t('bot.forbidden')));
            return;
        }
        if ($text === '') {
            $this->reply(Telegram::e(Telegram::t('bot.note_usage')));
            return;
        }
        $data = ['body' => mb_substr($text, 0, 2000), 'shared' => '0', 'done' => '0'];
        $replyTo = $this->msg['reply_to_message']['message_id'] ?? null;
        $issueId = $replyTo !== null ? $this->app->db->fetchValue(
            "SELECT record_id FROM telegram_messages WHERE chat_id = ? AND message_id = ? AND module = 'issues'",
            [(string) $this->msg['chat']['id'], (int) $replyTo]
        ) : null;
        if ($issueId !== null && ($issue = Asteroid::issue($this->app->db, (string) $issueId)) !== null) {
            $data['issue_id'] = (string) $issue['id'];
            $data['client_id'] = $issue['client_id'] !== null ? (string) $issue['client_id'] : '';
        }
        $result = (new FormHandler($m, $this->app->db))->process($data, null, $user, true);
        if (!$result['ok']) {
            $this->reply(Telegram::e(Telegram::t('bot.not_saved', ['error' => implode(' · ', $result['errors'])])));
            return;
        }
        Log::info('Telegram: nota {id} de {user}', ['id' => $result['id'], 'user' => $user->id()]);
        $this->reply(Telegram::e(Telegram::t(isset($data['issue_id']) ? 'bot.note_saved_issue' : 'bot.note_saved', ['issue' => $data['issue_id'] ?? ''])),
            [[['text' => Telegram::t('btn.note_done'), 'callback_data' => 'n:' . (int) $result['id']]]]);
    }

    /** /notes: las pendientes que ve el usuario (las suyas y las compartidas), con un botón «Feta» cada una. */
    private function listNotes(User $user): void
    {
        [$html, $buttons] = $this->notesMessage($user);
        $this->reply($html, $buttons);
    }

    /** Botón «Feta»: marca la nota (si el usuario la ve) y, si es la lista de /notes, la actualiza. */
    private function noteDone(User $user, string $id): void
    {
        $m = $this->app->modules()->get('notes');
        $handler = $m !== null ? new FormHandler($m, $this->app->db) : null;
        if ($handler === null || !ModuleRegistry::canAccess($user, $m, 'edit') || !ctype_digit($id) || $handler->load($id) === null) {
            $this->notice(Telegram::t('bot.forbidden'));
            return;
        }
        $result = $handler->process(['done' => '1'], $id, $user, true);
        if (!$result['ok']) {
            $this->notice(Telegram::t('bot.not_saved', ['error' => implode(' · ', $result['errors'])]));
            return;
        }
        $chat = $this->msg['chat']['id'];
        $msgId = (int) $this->msg['message_id'];
        $isList = str_contains((string) ($this->msg['text'] ?? ''), Telegram::t('bot.notes_title'));
        if ($isList) {
            [$html, $buttons] = $this->notesMessage($user);
            Telegram::bot()->edit($chat, $msgId, $html, $buttons ?: null);
        } else {
            Telegram::bot()->edit($chat, $msgId, Telegram::e(Telegram::t('bot.note_done', ['id' => $id])));
        }
        $this->notice(Telegram::t('bot.note_done', ['id' => $id]), false);
    }

    /** @return array{0:string,1:list<list<array>>} el texto de las notas pendientes y sus botones */
    private function notesMessage(User $user): array
    {
        $m = $this->app->modules()->get('notes');
        if ($m === null || !ModuleRegistry::canAccess($user, $m)) {
            return [Telegram::e(Telegram::t('bot.forbidden')), []];
        }
        [$where, $bind] = $m->rowCondition() ?? ['1 = 1', []];
        $rows = $this->app->db->fetchAll(
            "SELECT notes.id, notes.body, notes.due_on, notes.shared, notes.issue_id FROM notes
              WHERE ($where) AND notes.done = 0 ORDER BY notes.due_on IS NULL, notes.due_on, notes.id DESC LIMIT " . self::LIST_LIMIT,
            $bind
        );
        if (!$rows) {
            return [Telegram::e(Telegram::t('bot.notes_empty')), []];
        }
        $lines = ['<b>' . Telegram::e(Telegram::t('bot.notes_title')) . '</b>'];
        $buttons = [];
        foreach ($rows as $r) {
            $meta = array_filter([
                $r['due_on'] ? date('d/m', (int) strtotime((string) $r['due_on'])) : null,
                $r['issue_id'] ? '#' . (int) $r['issue_id'] : null,
                (int) $r['shared'] ? Telegram::t('bot.note_shared') : null,
            ]);
            $lines[] = '<b>' . (int) $r['id'] . '.</b> ' . Telegram::e(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $r['body']) ?? '', 0, 120, '…'))
                . ($meta ? ' <i>(' . Telegram::e(implode(' · ', $meta)) . ')</i>' : '');
            $buttons[] = ['text' => Telegram::t('btn.note_done_n', ['id' => $r['id']]), 'callback_data' => 'n:' . (int) $r['id']];
        }
        return [implode("\n", $lines), array_chunk($buttons, 3)];
    }

    // ---------------------------------------------------------------- vincular cuentas

    /** /vincular CODI: el código se genera en Equip → Telegram (10 minutos). */
    private function link(string $code): void
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        $key = 'telegram.link.' . $code;
        $data = $code !== '' ? $this->app->settings()->get($key) : null;
        if (!is_array($data) || (int) ($data['expires'] ?? 0) < time()) {
            $this->reply(Telegram::e(Telegram::t('bot.link_invalid')));
            return;
        }
        $this->app->settings()->delete($key);
        $from = $this->msg['from'];
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: (string) ($from['username'] ?? '');
        $db = $this->app->db;
        $db->query('DELETE FROM telegram_users WHERE telegram_id = ? OR user_id = ?', [(int) $from['id'], (int) $data['user']]);
        $db->insert('telegram_users', ['user_id' => (int) $data['user'], 'telegram_id' => (int) $from['id'], 'telegram_name' => mb_substr($name, 0, 100)]);
        $userName = (string) $db->fetchValue('SELECT name FROM users WHERE id = ?', [(int) $data['user']]);
        Log::info('Telegram: cuenta {tg} vinculada al usuario {user}', ['tg' => (int) $from['id'], 'user' => (int) $data['user']]);
        $this->reply(Telegram::e(Telegram::t('bot.linked', ['name' => $userName])) . "\n\n" . $this->help());
    }

    private function unlink(User $user): void
    {
        $this->app->db->query('DELETE FROM telegram_users WHERE user_id = ?', [$user->id()]);
        $this->reply(Telegram::e(Telegram::t('bot.unlinked')));
    }

    /** Usuario del equipo vinculado a quien escribe (y la petición actúa como él), o null con un aviso. */
    private function linkedUser(): ?User
    {
        $row = $this->app->db->fetch(
            'SELECT u.* FROM telegram_users t JOIN users u ON u.id = t.user_id WHERE t.telegram_id = ? AND u.active = 1',
            [(int) $this->msg['from']['id']]
        );
        if ($row === null) {
            $this->notice(Telegram::t('bot.not_linked'));
            return null;
        }
        $this->app->auth->actAs($row, 0);
        $user = $this->app->auth->user();
        if ($user === null || Asteroid::isClient($user)) {
            $this->notice(Telegram::t('bot.team_only'));
            return null;
        }
        $this->app->lang->setLocale((string) (Telegram::config()['locale'] ?? 'ca'));
        return $user;
    }

    // ---------------------------------------------------------------- órdenes

    private function help(): string
    {
        return '<b>' . Telegram::e(Telegram::t('bot.help_title')) . "</b>\n" . Telegram::e(Telegram::t('bot.help'));
    }

    /** /pissarra [nom|totes|sense]: las tarjetas de la Pissarra (las del usuario por defecto). */
    private function pissarra(User $user, string $args): void
    {
        $m = $this->app->modules()->get('pissarra');
        if ($m === null || !ModuleRegistry::canAccess($user, $m)) {
            $this->reply(Telegram::e(Telegram::t('bot.forbidden')));
            return;
        }
        $worker = (string) $user->id();
        $a = mb_strtolower($args);
        if (in_array($a, ['totes', 'tots', 'all', 'equip'], true)) {
            $worker = 'all';
        } elseif (in_array($a, ['sense', 'none', 'noves'], true)) {
            $worker = 'none';
        } elseif ($a !== '') {
            $found = $this->app->db->fetchValue('SELECT id FROM users WHERE active = 1 AND (LOWER(name) LIKE ? OR LOWER(username) = ?) LIMIT 1', ['%' . $a . '%', $a]);
            $worker = $found !== null ? (string) $found : $worker;
        }
        $_GET['worker'] = $worker;
        $page = new PissarraPage($this->app, $m);
        $data = $page->api() ?? ['cards' => [], 'total' => 0];
        if (!$data['cards']) {
            $this->reply(Telegram::e(Telegram::t('bot.board_empty')));
            return;
        }
        $lines = ['<b>' . Telegram::e(Telegram::t('bot.board_title', ['issues' => $data['total'], 'projects' => count($data['cards'])])) . '</b>'];
        foreach (array_slice($data['cards'], 0, 8) as $c) {
            $flags = array_filter([
                $c['panic'] ? Telegram::priorityText(2) . ' ' . $c['panic'] : null,
                $c['urgent'] ? Telegram::priorityText(1) . ' ' . $c['urgent'] : null,
                $c['due_on'] ? Telegram::t($c['overdue'] ? 'bot.overdue' : 'bot.due', ['date' => date('d/m', (int) strtotime($c['due_on']))]) : null,
            ]);
            $lines[] = '';
            $lines[] = '<b>' . Telegram::e($c['project']['title']) . '</b> · ' . (int) $c['open'] . ($flags ? ' · ' . Telegram::e(implode(' · ', $flags)) : '');
            foreach (array_slice($c['issues'], 0, 3) as $i) {
                $lines[] = '  <a href="' . Telegram::e(Telegram::url(Asteroid::issueUrl($i['id']))) . '">#' . (int) $i['id'] . '</a> ' . Telegram::e(mb_strimwidth($i['title'], 0, 60, '…'));
            }
        }
        $this->reply(implode("\n", $lines));
    }

    /** /meves o /noves: incidencias abiertas asignadas al usuario o sin asignar. */
    private function issues(User $user, string $which): void
    {
        $m = $this->app->modules()->get('issues');
        if ($m === null || !ModuleRegistry::canAccess($user, $m)) {
            $this->reply(Telegram::e(Telegram::t('bot.forbidden')));
            return;
        }
        $where = $which === 'mine' ? 'i.worker_id = ?' : 'i.worker_id IS NULL';
        $rows = $this->app->db->fetchAll(
            "SELECT i.id, i.title, i.priority, i.status, i.due_on, c.name AS client FROM issues i LEFT JOIN clients c ON c.id = i.client_id
              WHERE $where AND i.status IN (1, 2) ORDER BY i.priority DESC, i.due_on IS NULL, i.due_on, i.id DESC LIMIT " . (self::LIST_LIMIT + 1),
            $which === 'mine' ? [$user->id()] : []
        );
        if (!$rows) {
            $this->reply(Telegram::e(Telegram::t($which === 'mine' ? 'bot.mine_empty' : 'bot.new_empty')));
            return;
        }
        $lines = ['<b>' . Telegram::e(Telegram::t($which === 'mine' ? 'bot.mine_title' : 'bot.new_title')) . '</b>'];
        foreach (array_slice($rows, 0, self::LIST_LIMIT) as $r) {
            $lines[] = '<a href="' . Telegram::e(Telegram::url(Asteroid::issueUrl($r['id']))) . '">#' . (int) $r['id'] . '</a> '
                . Telegram::e(mb_strimwidth((string) $r['title'], 0, 70, '…'))
                . ' · ' . Telegram::e(trim((string) $r['client']) ?: '—')
                . ((int) $r['priority'] > 0 ? ' · <b>' . Telegram::e(Telegram::priorityText((int) $r['priority'])) . '</b>' : '');
        }
        if (count($rows) > self::LIST_LIMIT) {
            $lines[] = Telegram::e(Telegram::t('bot.more'));
        }
        $this->reply(implode("\n", $lines));
    }

    /** /incidencia N: resumen y últimos mensajes. */
    private function issue(User $user, string $args): void
    {
        [$m, $row] = $this->loadIssue($user, $args) ?? [null, null];
        if ($row === null) {
            return;
        }
        $issue = Asteroid::issue($this->app->db, (int) $row['id']) ?? [];
        $lines = [
            '<b>#' . (int) $row['id'] . ' ' . Telegram::e((string) $row['title']) . '</b>',
            Telegram::e(Telegram::issueContext($this->app->db, $issue)),
            Telegram::e(Telegram::statusText((int) $row['status']) . ' · ' . Telegram::priorityText((int) $row['priority'])),
        ];
        $desc = trim(mb_substr(HtmlSanitizer::toText((string) ($row['description'] ?? '')), 0, 400));
        if ($desc !== '') {
            $lines[] = '';
            $lines[] = Telegram::e($desc);
        }
        $msgs = $this->app->db->fetchAll(
            "SELECT m.body, u.name FROM messages m LEFT JOIN users u ON u.id = m.user_id
              WHERE m.module = 'issues' AND m.record_id = ? AND m.kind = 'text' AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 3",
            [(string) $row['id']]
        );
        if ($msgs) {
            $lines[] = '';
            foreach (array_reverse($msgs) as $msg) {
                $lines[] = '<b>' . Telegram::e((string) $msg['name']) . ':</b> ' . Telegram::e(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $msg['body']) ?? '', 0, 200, '…'));
            }
        }
        $lines[] = '';
        $lines[] = Telegram::link((string) $row['id']) . ' · ' . Telegram::e(Telegram::t('notify.reply_hint'));
        $msgId = $this->reply(implode("\n", $lines), Telegram::issueButtons((string) $row['id']));
        if ($msgId !== null) {
            Telegram::remember($this->app->db, (string) $this->msg['chat']['id'], $msgId, (string) $row['id']);
        }
    }

    /** /agafa N, /resol N: cambio parcial con los permisos y hooks de siempre (avisos, apunte en la conversación…). */
    private function update(User $user, string $args, array $values, string $done): void
    {
        [$m, $row] = $this->loadIssue($user, $args) ?? [null, null];
        if ($row === null) {
            return;
        }
        if (!ModuleRegistry::canAccess($user, $m, 'edit')) {
            $this->notice(Telegram::t('bot.forbidden'));
            return;
        }
        $result = (new FormHandler($m, $this->app->db))->process($values, (string) $row['id'], $user, true);
        if (!$result['ok']) {
            $this->notice(Telegram::t('bot.not_saved', ['error' => implode(' · ', $result['errors'])]));
            return;
        }
        Log::info('Telegram: incidencia {id} modificada por {user}', ['id' => $row['id'], 'user' => $user->id(), 'values' => $values]);
        $vars = ['id' => $row['id'], 'title' => $row['title'], 'user' => $user->name()];
        if ($this->callbackId !== null) {
            $this->notice(Telegram::t($done, $vars), false);
        }
        // Si el grupo ya recibe el aviso del cambio de estado, no se repite allí
        $inGroup = (string) $this->msg['chat']['id'] === (string) (Telegram::config()['chat_id'] ?? '');
        if ($inGroup && isset($values['status']) && Telegram::announcesStatus((int) $values['status'])) {
            return;
        }
        $this->reply(Telegram::e(Telegram::t($done, $vars))); // en el grupo, para que el resto lo vea
    }

    private function comment(User $user, string $issueId, string $text): void
    {
        $m = $this->app->modules()->get('issues');
        $row = $m !== null ? $this->app->chat()->record($m, $issueId, $user) : null;
        if ($row === null) {
            $this->reply(Telegram::e(Telegram::t('bot.forbidden')));
            return;
        }
        Telegram::$muted = true; // ya está en Telegram: no se vuelve a avisar al grupo
        try {
            $this->app->chat()->post($m, $issueId, $row, $user, $text);
        } finally {
            Telegram::$muted = false;
        }
        Log::info('Telegram: mensaje de {user} en la incidencia {id}', ['user' => $user->id(), 'id' => $issueId]);
        $this->reply(Telegram::e(Telegram::t('bot.commented', ['id' => $issueId])));
    }

    /** @return array{0:\Quasar\Core\Module,1:array}|null la incidencia N si el usuario la puede ver (si no, avisa) */
    private function loadIssue(User $user, string $args): ?array
    {
        $id = (int) preg_replace('/\D/', '', $args);
        $m = $this->app->modules()->get('issues');
        if ($id <= 0 || $m === null) {
            $this->notice(Telegram::t('bot.need_id'));
            return null;
        }
        $row = ModuleRegistry::canAccess($user, $m) ? (new FormHandler($m, $this->app->db))->load((string) $id) : null;
        if ($row === null) {
            $this->notice(Telegram::t('bot.not_found', ['id' => $id]));
            return null;
        }
        return [$m, $row + ['id' => $id]];
    }

    /** Responde en el mismo chat (y tema) al mensaje recibido (o al del botón pulsado). */
    private function reply(string $html, ?array $buttons = null): ?int
    {
        return Telegram::bot()->send(
            $this->msg['chat']['id'],
            $html,
            isset($this->msg['message_thread_id']) ? (int) $this->msg['message_thread_id'] : null,
            (int) $this->msg['message_id'],
            $buttons
        );
    }
}
