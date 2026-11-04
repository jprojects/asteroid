<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Hooks\Asteroid;
use Quasar\Telegram\Telegram;

/**
 * Asteroid · Equip → Telegram (modules/telegram.json): cada miembro del equipo vincula aquí su cuenta de Telegram
 * (un código de un solo uso, o el enlace t.me/bot?start=CODI). Los administradores ven el estado del bot y del
 * webhook, lo activan (setWebhook con el 'secret') y envían un mensaje de prueba al grupo.
 */
final class TelegramPage extends Page
{
    private const CODE_TTL = 600;

    public function index(): void
    {
        $user = $this->user();
        if (Asteroid::isClient($user)) {
            throw new HttpException(403);
        }
        $enabled = Telegram::enabled();
        $bot = $enabled ? Telegram::bot()->call('getMe') : null;
        $link = $this->app->db->fetch('SELECT telegram_name, linked_at FROM telegram_users WHERE user_id = ?', [$user->id()]);
        $code = $this->app->settings()->get('telegram.pending.' . $user->id());
        $code = is_array($code) && (int) ($code['expires'] ?? 0) > time() ? (string) $code['code'] : null;

        $admin = $user->isAdmin();
        $c = Telegram::config();
        $this->view([
            'enabled' => $enabled,
            'botName' => $bot['username'] ?? null,
            'link' => $link,
            'code' => $code,
            'admin' => $admin,
            'webhook' => $admin && $enabled ? Telegram::bot()->call('getWebhookInfo') : null,
            'hookUrl' => $this->hookUrl(),
            'groupOk' => !empty($c['chat_id']),
            'events' => (array) ($c['events'] ?? ['issue.new', 'issue.message']),
            'linked' => $admin ? $this->app->db->fetchAll(
                'SELECT u.name, t.telegram_name, t.linked_at FROM telegram_users t JOIN users u ON u.id = t.user_id ORDER BY u.name'
            ) : [],
            'urls' => [
                'code' => $this->pageUrl('code'),
                'unlink' => $this->pageUrl('unlink'),
                'webhook' => $this->pageUrl('webhook'),
                'test' => $this->pageUrl('test'),
            ],
        ]);
    }

    /** POST: código para vincular la cuenta (10 minutos). */
    public function actionCode(): void
    {
        $this->requirePost();
        $user = $this->user();
        if (Asteroid::isClient($user) || !Telegram::enabled()) {
            throw new HttpException(403);
        }
        $settings = $this->app->settings();
        $old = $settings->get('telegram.pending.' . $user->id());
        if (is_array($old) && isset($old['code'])) {
            $settings->delete('telegram.link.' . $old['code']);
        }
        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $expires = time() + self::CODE_TTL;
        $settings->set('telegram.link.' . $code, ['user' => $user->id(), 'expires' => $expires]);
        $settings->set('telegram.pending.' . $user->id(), ['code' => $code, 'expires' => $expires]);
        $this->app->redirect($this->pageUrl());
    }

    public function actionUnlink(): void
    {
        $this->requirePost();
        $this->app->db->query('DELETE FROM telegram_users WHERE user_id = ?', [$this->user()->id()]);
        $this->flash('success', $this->text('unlinked'));
        $this->app->redirect($this->pageUrl());
    }

    /** POST (administradores): apunta el webhook del bot a esta instalación, con el 'secret'. */
    public function actionWebhook(): void
    {
        $this->requirePost();
        $this->requireAdmin();
        $secret = (string) (Telegram::config()['secret'] ?? '');
        if ($secret === '' || !preg_match('/^[A-Za-z0-9_-]{16,256}$/', $secret)) {
            $this->flash('error', $this->text('secret_missing'));
            $this->app->redirect($this->pageUrl());
        }
        $ok = Telegram::bot()->call('setWebhook', [
            'url' => $this->hookUrl(),
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => true,
        ]) !== null;
        Log::info('Telegram: webhook {result} en {url}', ['result' => $ok ? 'configurado' : 'NO configurado', 'url' => $this->hookUrl()]);
        $this->flash($ok ? 'success' : 'error', $this->text($ok ? 'webhook_ok' : 'webhook_failed'));
        $this->app->redirect($this->pageUrl());
    }

    /** POST (administradores): mensaje de prueba al grupo y tema configurados. */
    public function actionTest(): void
    {
        $this->requirePost();
        $this->requireAdmin();
        $c = Telegram::config();
        $ok = !empty($c['chat_id']) && Telegram::bot()->send(
            $c['chat_id'],
            Telegram::e(Telegram::t('test', ['app' => (string) $c['_app'], 'user' => $this->user()->name()])),
            isset($c['thread_id']) ? (int) $c['thread_id'] : null
        ) !== null;
        $this->flash($ok ? 'success' : 'error', $this->text($ok ? 'test_ok' : 'test_failed'));
        $this->app->redirect($this->pageUrl());
    }

    private function requireAdmin(): void
    {
        if (!$this->user()->isAdmin() || !Telegram::enabled()) {
            throw new HttpException(403);
        }
    }

    private function hookUrl(): string
    {
        return $this->app->absoluteUrl($this->app->url('public', ['m' => 'telegram_hook']));
    }
}
