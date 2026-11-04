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
 * (el 'secret' de la configuración); sin ella no se hace nada.
 *
 * Quien escribe tiene que tener su cuenta de Telegram vinculada a un usuario del equipo (Equip → Telegram):
 * todo se hace como ese usuario, con sus permisos, y deja los mismos avisos que en la web.
 *   - Respuesta a un aviso del bot → mensaje en la conversación de esa incidencia.
 *   - /pissarra [nom|totes|sense] · /meves · /noves · /incidencia N · /agafa N · /resol N · /vincular CODI · /ajuda
 */
final class TelegramHookPage extends Page
{
    private const LIST_LIMIT = 10;

    private array $msg = [];

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
                default => $this->reply(Telegram::e(Telegram::t('bot.unknown'))),
            };
            return;
        }

        // Respuesta a un aviso del bot → a la conversación de su incidencia
        $replyTo = $this->msg['reply_to_message']['message_id'] ?? null;
        if ($replyTo === null || $text === '') {
            return;
        }
        $issueId = $this->app->db->fetchValue(
            "SELECT record_id FROM telegram_messages WHERE chat_id = ? AND message_id = ? AND module = 'issues'",
            [(string) $this->msg['chat']['id'], (int) $replyTo]
        );
        if ($issueId === null) {
            return;
        }
        $user = $this->linkedUser();
        if ($user !== null) {
            $this->comment($user, (string) $issueId, $text);
        }
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
            $this->reply(Telegram::e(Telegram::t('bot.not_linked')));
            return null;
        }
        $this->app->auth->actAs($row, 0);
        $user = $this->app->auth->user();
        if ($user === null || Asteroid::isClient($user)) {
            $this->reply(Telegram::e(Telegram::t('bot.team_only')));
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
        $msgId = $this->reply(implode("\n", $lines));
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
            $this->reply(Telegram::e(Telegram::t('bot.forbidden')));
            return;
        }
        $result = (new FormHandler($m, $this->app->db))->process($values, (string) $row['id'], $user, true);
        if (!$result['ok']) {
            $this->reply(Telegram::e(Telegram::t('bot.not_saved', ['error' => implode(' · ', $result['errors'])])));
            return;
        }
        Log::info('Telegram: incidencia {id} modificada por {user}', ['id' => $row['id'], 'user' => $user->id(), 'values' => $values]);
        $this->reply(Telegram::e(Telegram::t($done, ['id' => $row['id'], 'title' => $row['title']])));
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
            $this->reply(Telegram::e(Telegram::t('bot.need_id')));
            return null;
        }
        $row = ModuleRegistry::canAccess($user, $m) ? (new FormHandler($m, $this->app->db))->load((string) $id) : null;
        if ($row === null) {
            $this->reply(Telegram::e(Telegram::t('bot.not_found', ['id' => $id])));
            return null;
        }
        return [$m, $row + ['id' => $id]];
    }

    /** Responde en el mismo chat (y tema) al mensaje recibido. */
    private function reply(string $html): ?int
    {
        return Telegram::bot()->send(
            $this->msg['chat']['id'],
            $html,
            isset($this->msg['message_thread_id']) ? (int) $this->msg['message_thread_id'] : null,
            (int) $this->msg['message_id']
        );
    }
}
