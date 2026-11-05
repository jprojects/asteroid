<?php
declare(strict_types=1);

namespace Quasar\Telegram;

use Quasar\Core\Database;
use Quasar\Core\HtmlSanitizer;
use Quasar\Core\Lang;
use Quasar\Core\Log;
use Quasar\Core\User;
use Quasar\Hooks\Asteroid;

/**
 * Asteroid · bot de Telegram. Configuración en config.local.php:
 *
 *   'telegram' => [
 *       'token'     => '123456:ABC…',          // de @BotFather
 *       'chat_id'   => -1001234567890,          // grupo del equipo
 *       'thread_id' => 42,                       // tema del grupo (opcional)
 *       'panic_thread_id' => 51,                 // tema para las incidencias de pánico (opcional; si no, thread_id)
 *       'secret'    => '…',                      // lo comprueba el webhook (cabecera de Telegram)
 *       'events'    => ['issue.new', 'issue.message', 'issue.resolved'],   // también: issue.status (todos), issue.panic
 *       'locale'    => 'ca',
 *   ],
 *
 * Avisos al grupo (IssuesHooks): incidencias nuevas y mensajes de las conversaciones, con botones (imputar horas,
 * asignársela, resolverla). Responder a un aviso en Telegram publica la respuesta en la conversación de la incidencia
 * (TelegramHookPage), como el usuario de Asteroid vinculado a esa cuenta de Telegram.
 */
final class Telegram
{
    /** Mientras se publica un mensaje que viene de Telegram, no se avisa de él a Telegram (no rebota). */
    public static bool $muted = false;

    private static ?array $config = null;
    private static ?Lang $lang = null;

    /** @return array<string,mixed> configuración de 'telegram' (vacía si no está configurado) */
    public static function config(): array
    {
        if (self::$config === null) {
            $config = require QS_ROOT . '/config/config.php';
            if (is_file(QS_ROOT . '/config/config.local.php')) {
                $config = array_replace_recursive($config, require QS_ROOT . '/config/config.local.php');
            }
            self::$config = ['_base_url' => (string) ($config['base_url'] ?? ''), '_app' => (string) ($config['app_name'] ?? 'Asteroid')]
                + (array) ($config['telegram'] ?? []);
        }
        return self::$config;
    }

    public static function enabled(): bool
    {
        return trim((string) (self::config()['token'] ?? '')) !== '';
    }

    public static function bot(): Bot
    {
        $api = self::config()['api_url'] ?? null; // solo para pruebas locales
        return new Bot((string) self::config()['token'], is_string($api) && $api !== '' ? $api : null);
    }

    /** ¿Está activado ese aviso al grupo? */
    public static function wants(string $event): bool
    {
        $c = self::config();
        return self::enabled() && !empty($c['chat_id'])
            && in_array($event, (array) ($c['events'] ?? ['issue.new', 'issue.message']), true);
    }

    /** ¿Se avisa al grupo de este cambio de estado? (issue.status: todos; issue.resolved: solo al resolverla) */
    public static function announcesStatus(int $to): bool
    {
        return self::wants('issue.status') || ($to === Asteroid::STATUS_RESOLVED && self::wants('issue.resolved'));
    }

    /** Texto de lang/{locale}/telegram.json (los mensajes del bot, en el idioma de la configuración). */
    public static function t(string $key, array $vars = []): string
    {
        return self::text('telegram:' . $key, $vars);
    }

    /** Escapado para parse_mode HTML. */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $relative): string
    {
        $base = rtrim((string) self::config()['_base_url'], '/');
        return $base !== '' ? $base . '/' . ltrim($relative, '/') : $relative;
    }

    /**
     * Envía al grupo (y tema) del equipo; recuerda de qué incidencia es para poder responderle. Lo de las incidencias de
     * pánico va a su tema (panic_thread_id), si lo hay.
     */
    public static function toGroup(Database $db, string $html, ?string $issueId = null, ?array $issue = null, ?array $buttons = null): void
    {
        $c = self::config();
        $thread = $issue !== null && (int) ($issue['priority'] ?? 0) === 2 && !empty($c['panic_thread_id'])
            ? (int) $c['panic_thread_id']
            : (isset($c['thread_id']) ? (int) $c['thread_id'] : null);
        try {
            $msgId = self::bot()->send($c['chat_id'], $html, $thread, null,
                $buttons ?? ($issueId !== null ? self::issueButtons($issueId) : null));
            if ($msgId !== null && $issueId !== null) {
                self::remember($db, (string) $c['chat_id'], $msgId, $issueId);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram: no se ha podido enviar el aviso', ['exception' => $e]);
        }
    }

    /**
     * Botones de un aviso de incidencia. callback_data (máx. 64 bytes): "h:ID" imputar horas, "t:ID" asignársela,
     * "r:ID" resolverla; los atiende TelegramHookPage::callback().
     * @return list<list<array{text:string,callback_data:string}>>
     */
    public static function issueButtons(string $issueId, bool $closed = false): array
    {
        if ($closed) { // resuelta o descartada: solo imputar horas
            return [[['text' => self::t('btn.hours'), 'callback_data' => 'h:' . $issueId]]];
        }
        return [[
            ['text' => self::t('btn.hours'), 'callback_data' => 'h:' . $issueId],
            ['text' => self::t('btn.take'), 'callback_data' => 't:' . $issueId],
            ['text' => self::t('btn.resolve'), 'callback_data' => 'r:' . $issueId],
        ]];
    }

    /** 90 → "1:30" */
    public static function duration(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** De qué es un mensaje del bot: incidencia ('issues') o horas imputadas desde Telegram ('hours'). */
    public static function remember(Database $db, string $chatId, int $messageId, string $recordId, string $module = 'issues'): void
    {
        try {
            $db->query(
                'INSERT IGNORE INTO telegram_messages (chat_id, message_id, module, record_id) VALUES (?, ?, ?, ?)',
                [$chatId, $messageId, $module, $recordId]
            );
        } catch (\Throwable $e) {
            Log::warning('Telegram: no se ha podido guardar el mensaje', ['exception' => $e]);
        }
    }

    // ---------------------------------------------------------------- avisos de Asteroid

    /** Incidencia nueva (IssuesHooks::afterSave). */
    public static function issueCreated(Database $db, array $issue, User $user): void
    {
        $panic = (int) $issue['priority'] === 2;
        if (!self::wants('issue.new') && !($panic && self::wants('issue.panic'))) {
            return;
        }
        $id = (string) $issue['id'];
        $lines = [
            '<b>' . self::e(self::t('notify.new', ['id' => $id])) . '</b>' . ($panic ? ' · <b>' . self::e(self::t('panic')) . '</b>' : ''),
            '<b>' . self::e((string) $issue['title']) . '</b>',
            self::e(self::issueContext($db, $issue)),
        ];
        $text = trim(mb_substr(HtmlSanitizer::toText((string) ($issue['description'] ?? '')), 0, 500));
        if ($text !== '') {
            $lines[] = '';
            $lines[] = self::e($text);
        }
        $lines[] = '';
        $lines[] = self::e(self::t('notify.by', ['user' => $user->name()])) . ' · ' . self::link($id);
        self::toGroup($db, implode("\n", $lines), $id, $issue);
    }

    /** Mensaje en la conversación de una incidencia (IssuesHooks::chatRecipients, justo después de guardarlo). */
    public static function messagePosted(Database $db, array $issue, User $author): void
    {
        if (self::$muted || !self::wants('issue.message')) {
            return;
        }
        $id = (string) $issue['id'];
        $msg = $db->fetch(
            "SELECT kind, body, media FROM messages WHERE module = 'issues' AND record_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1",
            [$id, $author->id()]
        );
        if ($msg === null || $msg['kind'] === 'system') {
            return;
        }
        if ($msg['kind'] === 'meet') {
            $body = self::t('notify.meet');
        } else {
            $body = trim((string) $msg['body']);
            $files = $msg['media'] ? count((array) json_decode((string) $msg['media'], true)) : 0;
            if ($files > 0) {
                $body .= ($body !== '' ? "\n" : '') . self::t('notify.files', ['n' => $files]);
            }
        }
        $html = '<b>' . self::e($author->name()) . '</b> · ' . self::e(self::t('notify.message', ['id' => $id])) . "\n"
            . '<i>' . self::e((string) $issue['title']) . '</i> · ' . self::e(self::issueContext($db, $issue)) . "\n\n"
            . self::e(mb_substr($body, 0, 1500)) . "\n\n" . self::link($id) . ' · ' . self::e(self::t('notify.reply_hint'));
        self::toGroup($db, $html, $id, $issue);
    }

    /** Cambio de estado (Asteroid::statusChanged). */
    public static function statusChanged(Database $db, array $issue, int $to, User $user): void
    {
        if (!self::announcesStatus($to)) {
            return;
        }
        $id = (string) $issue['id'];
        $closed = in_array($to, [Asteroid::STATUS_RESOLVED, 4], true);
        if ($to === Asteroid::STATUS_RESOLVED) {
            $minutes = (int) $db->fetchValue('SELECT COALESCE(SUM(minutes), 0) FROM hours WHERE issue_id = ?', [(int) $id]);
            $html = '<b>' . self::e(self::t('notify.resolved', ['id' => $id, 'user' => $user->name()])) . "</b>\n"
                . self::e((string) $issue['title']) . ' · ' . self::e(self::issueContext($db, $issue)) . "\n"
                . self::e(self::t($minutes > 0 ? 'notify.resolved_hours' : 'notify.resolved_no_hours', ['time' => self::duration($minutes)]))
                . "\n" . self::link($id);
        } else {
            $html = '<b>' . self::e(self::t('notify.status', ['id' => $id, 'status' => self::statusText($to), 'user' => $user->name()])) . "</b>\n"
                . self::e((string) $issue['title']) . ' · ' . self::e(self::issueContext($db, $issue)) . "\n" . self::link($id);
        }
        self::toGroup($db, $html, $id, $issue, self::issueButtons($id, $closed));
    }

    /** Texto de otro fichero de idioma (p. ej. "issues:status.pending"), en el idioma del bot. */
    public static function text(string $key, array $vars = []): string
    {
        self::$lang ??= new Lang(QS_ROOT . '/lang', (string) (self::config()['locale'] ?? 'ca'), 'es');
        return self::$lang->get($key, $vars);
    }

    public static function statusText(int $status): string
    {
        return self::text('issues:status.' . ([1 => 'pending', 2 => 'in_progress', 3 => 'resolved', 4 => 'discarded'][$status] ?? 'pending'));
    }

    public static function priorityText(int $priority): string
    {
        return self::text('issues:priority.' . ([0 => 'normal', 1 => 'urgent', 2 => 'panic'][$priority] ?? 'normal'));
    }

    /** "Client · Projecte · Tècnic" */
    public static function issueContext(Database $db, array $issue): string
    {
        $project = $issue['project_id'] ? (string) $db->fetchValue('SELECT title FROM projects WHERE id = ?', [(int) $issue['project_id']]) : '';
        $worker = $issue['worker_id'] ? (string) $db->fetchValue('SELECT name FROM users WHERE id = ?', [(int) $issue['worker_id']]) : '';
        return implode(' · ', array_filter([(string) ($issue['client_name'] ?? ''), $project, $worker !== '' ? $worker : self::t('unassigned')]));
    }

    public static function link(string $issueId): string
    {
        return '<a href="' . self::e(self::url(Asteroid::issueUrl($issueId))) . '">' . self::e(self::t('open')) . '</a>';
    }
}
