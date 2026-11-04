<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\Log;
use Quasar\Webchat\Webchat;

/**
 * Asteroid · el chat web que ven los visitantes de las webs de los clientes (modules/webchat_widget.json, página
 * pública: sin sesión ni cookies; el visitante se identifica con el token de su navegador, cabecera X-Visitor).
 *
 *   a=loader&k=CLAVE   script para pegar en la web: botón flotante y el iframe del chat
 *   (index)&k=CLAVE    el chat (iframe); solo se puede incrustar en los dominios del cliente (frame-ancestors)
 *   a=js               script del iframe (src/Webchat/widget.js)
 *   a=poll&k=&after=   mensajes nuevos de la conversación del visitante (JSON)
 *   a=send&k=          POST JSON {body, name?, email?, page?}: mensaje del visitante (crea la conversación)
 */
final class WebchatWidgetPage extends Page
{
    private const LOCALES = ['ca', 'es', 'en'];

    public function index(): void
    {
        $site = $this->site();
        $this->locale();
        if ($site === null) {
            http_response_code(404);
            echo '<!doctype html><meta charset="utf-8"><title>Chat</title>';
            return;
        }
        // Se incrusta en las webs del cliente: fuera el SAMEORIGIN de la aplicación, y solo sus dominios
        header_remove('X-Frame-Options');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors " . Webchat::frameAncestors($site));
        header('Referrer-Policy: no-referrer');
        $this->render('pages/webchat_widget', [
            'site' => $site,
            'config' => [
                'key' => $site['public_key'],
                'poll' => $this->pageUrl('poll', ['k' => $site['public_key']]),
                'send' => $this->pageUrl('send', ['k' => $site['public_key']]),
                'page' => mb_substr((string) $this->param('page', ''), 0, 500),
                'i18n' => array_map(fn ($k) => $this->text('widget.' . $k), [
                    'placeholder' => 'placeholder', 'send' => 'send', 'name' => 'name', 'email' => 'email',
                    'intro' => 'intro', 'error' => 'error', 'closed' => 'closed', 'close' => 'close', 'you' => 'you', 'too_many' => 'too_many',
                ]),
            ],
            'version' => (string) filemtime(QS_ROOT . '/src/Webchat/widget.js'),
        ], null);
    }

    /** Script que el cliente pega en su web. Si el chat no está activo, no pinta nada. */
    public function actionLoader(): void
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        header('X-Content-Type-Options: nosniff');
        $site = $this->site();
        if ($site === null) {
            echo "/* Asteroid: chat no disponible */\n";
            return;
        }
        $cfg = [
            'frame' => $this->app->absoluteUrl($this->pageUrl('index', ['k' => $site['public_key']])),
            'color' => Webchat::validColor((string) $site['color']) ? $site['color'] : Webchat::COLOR,
            'name' => (string) $site['name'],
            'position' => $site['position'] === 'left' ? 'left' : 'right',
            'domains' => Webchat::domains($site),
            'label' => $this->text('widget.open'),
        ];
        echo '(function (cfg) {' . "\n" . file_get_contents(QS_ROOT . '/src/Webchat/loader.js') . "\n})("
            . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ");\n";
    }

    public function actionJs(): void
    {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile(QS_ROOT . '/src/Webchat/widget.js');
    }

    public function actionPoll(): void
    {
        $site = $this->site();
        $hash = $site !== null ? Webchat::visitorHash($site, (string) ($_SERVER['HTTP_X_VISITOR'] ?? '')) : null;
        if ($hash === null) {
            $this->json(['error' => 'not_found'], 404);
            return;
        }
        $this->locale();
        $conv = $this->conversation($site, $hash);
        $this->json([
            'status' => $conv !== null ? ((int) $conv['status'] === 2 ? 'closed' : 'open') : 'new',
            'messages' => $conv !== null ? $this->messages((int) $conv['id'], max(0, (int) $this->param('after', '0'))) : [],
        ]);
    }

    public function actionSend(): void
    {
        $site = $this->site();
        $hash = $site !== null ? Webchat::visitorHash($site, (string) ($_SERVER['HTTP_X_VISITOR'] ?? '')) : null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || $hash === null) {
            $this->json(['error' => 'not_found'], 404);
            return;
        }
        $this->locale();
        $in = json_decode((string) file_get_contents('php://input'), true);
        $in = is_array($in) ? $in : [];
        $body = trim((string) ($in['body'] ?? ''));
        if (!empty($in['website']) || $body === '' || mb_strlen($body) > Webchat::MAX_BODY) {
            $this->json(['error' => $this->text('widget.error')], 422); // trampa para bots o mensaje no válido
            return;
        }
        $page = mb_substr((string) ($in['page'] ?? ''), 0, 500);
        if (!Webchat::originAllowed($site, $page !== '' ? $page : null) && Webchat::domains($site)) {
            $this->json(['error' => $this->text('widget.error')], 403);
            return;
        }
        $db = $this->app->db;
        $conv = $this->conversation($site, $hash);
        if ($conv === null) {
            $recent = (int) $db->fetchValue(
                'SELECT COUNT(*) FROM webchat_conversations WHERE ip_hash = ? AND created_at > ?',
                [Webchat::ipHash(), date('Y-m-d H:i:s', time() - 3600)]
            );
            if ($recent >= Webchat::MAX_NEW_PER_IP) {
                $this->json(['error' => $this->text('widget.too_many')], 429);
                return;
            }
            $email = trim((string) ($in['email'] ?? ''));
            $id = (int) $db->insert('webchat_conversations', [
                'client_id' => (int) $site['client_id'],
                'visitor_hash' => $hash,
                'visitor_name' => mb_substr(trim((string) ($in['name'] ?? '')) ?: $this->text('widget.visitor'), 0, 80),
                'visitor_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 150) : null,
                'page_url' => $page !== '' ? $page : null,
                'ip_hash' => Webchat::ipHash(),
                'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
            $conv = $db->fetch('SELECT * FROM webchat_conversations WHERE id = ?', [$id]);
            Log::info('Chat web: conversación {id} nueva (cliente {client})', ['id' => $id, 'client' => $site['client_id']]);
        } else {
            $count = (int) $db->fetchValue(
                "SELECT COUNT(*) FROM messages WHERE module = 'webchat' AND record_id = ? AND user_id IS NULL AND kind = 'text' AND created_at > ?",
                [(string) $conv['id'], date('Y-m-d H:i:s', time() - 600)]
            );
            if ($count >= Webchat::MAX_MESSAGES) {
                $this->json(['error' => $this->text('widget.too_many')], 429);
                return;
            }
        }

        $module = $this->otherModule('webchat');
        $id = (string) $conv['id'];
        $row = $conv;
        try {
            $this->app->chat()->postExternal($module, $id, $row, (string) $conv['visitor_name'], $body);
        } catch (\InvalidArgumentException) {
            $this->json(['error' => $this->text('widget.error')], 422);
            return;
        }
        // Un mensaje del visitante reabre la conversación
        $db->update('webchat_conversations', ['status' => 1, 'last_message_at' => date('Y-m-d H:i:s')], 'id', (int) $id);
        $this->json(['ok' => true, 'messages' => $this->messages((int) $id, max(0, (int) ($in['after'] ?? 0)))]);
    }

    // ---------------------------------------------------------------- utilidades

    private function site(): ?array
    {
        return Webchat::siteByKey($this->app->db, (string) $this->param('k', ''));
    }

    private function conversation(array $site, string $hash): ?array
    {
        return $this->app->db->fetch(
            'SELECT * FROM webchat_conversations WHERE client_id = ? AND visitor_hash = ? ORDER BY id DESC LIMIT 1',
            [(int) $site['client_id'], $hash]
        );
    }

    /** Mensajes para el visitante: los suyos, las respuestas (con el nombre de pila de quien responde) y los avisos. */
    private function messages(int $conversationId, int $after): array
    {
        $rows = $this->app->db->fetchAll(
            "SELECT m.id, m.user_id, m.kind, m.body, m.meta, m.created_at, u.name
               FROM messages m LEFT JOIN users u ON u.id = m.user_id
              WHERE m.module = 'webchat' AND m.record_id = ? AND m.id > ? AND m.deleted_at IS NULL AND m.kind IN ('text', 'system')
              ORDER BY m.id LIMIT 200",
            [(string) $conversationId, $after]
        );
        $out = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'system') {
                $meta = (array) json_decode((string) $r['meta'], true);
                $body = $r['body'] ?? $this->app->lang->get((string) ($meta['t'] ?? ''), $this->app->lang->vars((array) ($meta['v'] ?? [])));
                $out[] = ['id' => (int) $r['id'], 'from' => 'system', 'body' => (string) $body, 'at' => date('c', (int) strtotime((string) $r['created_at']))];
                continue;
            }
            $agent = $r['user_id'] !== null;
            $out[] = [
                'id' => (int) $r['id'],
                'from' => $agent ? 'agent' : 'visitor',
                'name' => $agent ? (string) strtok((string) $r['name'], ' ') : null,
                'body' => (string) $r['body'],
                'at' => date('c', (int) strtotime((string) $r['created_at'])),
            ];
        }
        return $out;
    }

    private function locale(): void
    {
        $lang = (string) $this->param('lang', '');
        $this->app->lang->setLocale(in_array($lang, self::LOCALES, true) ? $lang : 'ca');
    }
}
