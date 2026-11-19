<?php
declare(strict_types=1);

namespace Quasar\Telegram;

use Quasar\Core\App;
use Quasar\Core\Log;
use Quasar\Pages\TelegramHookPage;

/**
 * Asteroid · modo consulta de los bots ('mode' => 'poll'): pide a Telegram los mensajes nuevos (getUpdates) de cada bot
 * configurado (el del equipo y el de los clientes) y los atiende como el webhook. Lo usan el cron
 * (tools/telegram-poll.php, esperando hasta ~55 s) y las páginas donde se vincula una cuenta (una consulta rápida al
 * abrirlas, para no depender del cron). Una sola consulta a la vez.
 */
final class Poller
{
    /** Con los dos bots, cada consulta larga espera como mucho esto, y se van alternando. */
    private const SHARED_WAIT = 5;

    /**
     * @param ?string $only solo ese bot (Telegram::TEAM o Telegram::CLIENTS); por defecto, todos los configurados
     * @return ?int mensajes atendidos, o null si ya hay otra consulta en marcha
     */
    public static function run(App $app, int $seconds = 0, ?string $only = null): ?int
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
            $settings = $worker->settings();
            $bots = array_values(array_filter(Telegram::bots(), static fn (string $b) => $only === null || $b === $only));
            $maxWait = count($bots) > 1 ? self::SHARED_WAIT : 25;

            // Mientras haya webhook, Telegram no responde getUpdates
            foreach ($bots as $b) {
                $info = Telegram::bot($b)->call('getWebhookInfo');
                if (!empty($info['url'])) {
                    Telegram::bot($b)->call('deleteWebhook', ['drop_pending_updates' => false]);
                    Log::notice('Telegram: webhook quitado para el modo consulta ({bot})', ['bot' => $b]);
                }
            }

            $until = time() + $seconds;
            $handled = 0;
            do {
                $failed = 0;
                foreach ($bots as $b) {
                    $wait = $seconds > 0 ? max(1, min($maxWait, $until - time() - 3)) : 0;
                    $updates = Telegram::bot($b)->call('getUpdates', [
                        'offset' => (int) $settings->get(Telegram::key($b, 'offset'), 0),
                        'timeout' => $wait,
                        'allowed_updates' => ['message', 'callback_query'],
                    ], $wait + 10);
                    $settings->set(Telegram::key($b, 'polled_at'), time());
                    if ($updates === null) {
                        $failed++; // error (ya en el log)
                        continue;
                    }
                    foreach ($updates as $update) {
                        if (!is_array($update) || !isset($update['update_id'])) {
                            continue;
                        }
                        // Se marca antes de atenderlo: si algo falla, no se repite en bucle
                        $settings->set(Telegram::key($b, 'offset'), (int) $update['update_id'] + 1);
                        $_GET = [];
                        (new TelegramHookPage($worker, $module))->process($update, $b);
                        $handled++;
                        // Lo último recibido, para Equip → Telegram (qué ha llegado y por dónde)
                        $type = (string) (array_keys(array_diff_key($update, ['update_id' => 0]))[0] ?? '?');
                        $settings->set(Telegram::key($b, 'last_update'), ['at' => time(), 'type' => $type, 'via' => $seconds > 0 ? 'cron' : 'web']);
                    }
                }
                if ($failed === count($bots)) {
                    break; // ninguno responde: no insistir en bucle
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
