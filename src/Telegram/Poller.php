<?php
declare(strict_types=1);

namespace Quasar\Telegram;

use Quasar\Core\App;
use Quasar\Core\Log;
use Quasar\Pages\TelegramHookPage;

/**
 * Asteroid · modo consulta del bot ('mode' => 'poll'): pide a Telegram los mensajes nuevos (getUpdates) y los atiende
 * como el webhook. Lo usan el cron (tools/telegram-poll.php, esperando hasta ~55 s) y la página Equip → Telegram (una
 * consulta rápida al abrirla, para no depender del cron al vincular una cuenta). Una sola consulta a la vez.
 */
final class Poller
{
    /** @return ?int mensajes atendidos, o null si ya hay otra consulta en marcha */
    public static function run(App $app, int $seconds = 0): ?int
    {
        $lock = fopen(sys_get_temp_dir() . '/asteroid-telegram-' . md5(QS_ROOT) . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($seconds > 0) {
                $app->settings()->set('telegram.cron_busy_at', time()); // se ve en Equip → Telegram
            }
            return null;
        }
        if ($seconds > 0) {
            $app->settings()->set('telegram.cron_at', time());
        }
        // Cada mensaje se atiende como su usuario de Asteroid (Auth::actAs): en una App aparte, para no cambiar el
        // usuario de la página desde la que se consulta
        $worker = new App($app->config);
        $get = $_GET;
        try {
            $module = $worker->modules()->get('telegram_hook') ?? throw new \RuntimeException('Falta el módulo telegram_hook');
            $bot = Telegram::bot();
            $settings = $worker->settings();

            // Mientras haya webhook, Telegram no responde getUpdates
            $info = $bot->call('getWebhookInfo');
            if (!empty($info['url'])) {
                $bot->call('deleteWebhook', ['drop_pending_updates' => false]);
                Log::notice('Telegram: webhook quitado para el modo consulta');
            }

            $until = time() + $seconds;
            $handled = 0;
            do {
                $wait = $seconds > 0 ? max(1, min(25, $until - time() - 3)) : 0;
                $updates = $bot->call('getUpdates', [
                    'offset' => (int) $settings->get('telegram.offset', 0),
                    'timeout' => $wait,
                    'allowed_updates' => ['message', 'callback_query'],
                ], $wait + 10);
                $settings->set('telegram.polled_at', time());
                if ($updates === null) {
                    break; // error (ya en el log)
                }
                foreach ($updates as $update) {
                    if (!is_array($update) || !isset($update['update_id'])) {
                        continue;
                    }
                    // Se marca antes de atenderlo: si algo falla, no se repite en bucle
                    $settings->set('telegram.offset', (int) $update['update_id'] + 1);
                    $_GET = [];
                    (new TelegramHookPage($worker, $module))->process($update);
                    $handled++;
                    // Lo último recibido, para Equip → Telegram (qué ha llegado y por dónde)
                    $type = (string) (array_keys(array_diff_key($update, ['update_id' => 0]))[0] ?? '?');
                    $settings->set('telegram.last_update', ['at' => time(), 'type' => $type, 'via' => $seconds > 0 ? 'cron' : 'web']);
                }
            } while ($seconds > 0 && time() < $until - 3);

            if ($handled > 0) {
                Log::info('Telegram (consulta): {n} mensaje(s) atendidos', ['n' => $handled]);
            }
            return $handled;
        } finally {
            $_GET = $get;
            flock($lock, LOCK_UN);
        }
    }
}
