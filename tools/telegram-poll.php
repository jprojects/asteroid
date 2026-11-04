#!/usr/bin/env php
<?php
/**
 * Asteroid · modo consulta del bot de Telegram ('telegram' => ['mode' => 'poll', …]): cuando el hosting no deja entrar
 * las peticiones de Telegram al webhook, el cron pregunta a Telegram por los mensajes nuevos (getUpdates) y los atiende
 * igual que el webhook (TelegramHookPage::process). Cron cada minuto:
 *
 *   * * * * * php /ruta/a/asteroid/tools/telegram-poll.php > /dev/null 2>&1
 *
 * Cada ejecución espera mensajes hasta ~55 s (consulta larga), así que las respuestas llegan casi al momento. Si queda
 * otra ejecución en marcha, esta no hace nada. Mientras haya webhook, Telegram no responde getUpdates: este script lo
 * quita (los avisos al grupo no lo necesitan).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;
use Quasar\Core\Log;
use Quasar\Pages\TelegramHookPage;
use Quasar\Telegram\Telegram;

$tg = (array) ($config['telegram'] ?? []);
if (!Telegram::enabled() || ($tg['mode'] ?? 'webhook') !== 'poll') {
    fwrite(STDERR, "Telegram no está en modo consulta ('mode' => 'poll' en la configuración de telegram)\n");
    exit(0);
}

// Una sola ejecución a la vez
$lock = fopen(sys_get_temp_dir() . '/asteroid-telegram-' . md5(QS_ROOT) . '.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$app = new App($config);
$module = $app->modules()->get('telegram_hook') ?? throw new RuntimeException('Falta el módulo telegram_hook');
$bot = Telegram::bot();
$settings = $app->settings();

$info = $bot->call('getWebhookInfo');
if (!empty($info['url'])) {
    $bot->call('deleteWebhook', ['drop_pending_updates' => false]);
    Log::notice('Telegram: webhook quitado para el modo consulta');
}

$until = time() + 55;
$handled = 0;
while (time() < $until - 3) {
    $offset = (int) $settings->get('telegram.offset', 0);
    $wait = max(1, min(25, $until - time() - 3));
    $updates = $bot->call('getUpdates', ['offset' => $offset, 'timeout' => $wait, 'allowed_updates' => ['message']], $wait + 10);
    $settings->set('telegram.polled_at', time());
    if ($updates === null) {
        break; // error (ya en el log): el siguiente cron lo vuelve a intentar
    }
    foreach ($updates as $update) {
        if (!is_array($update) || !isset($update['update_id'])) {
            continue;
        }
        // Se marca antes de atenderlo: si algo falla, no se repite en bucle
        $settings->set('telegram.offset', (int) $update['update_id'] + 1);
        $_GET = [];
        (new TelegramHookPage($app, $module))->process($update);
        $handled++;
    }
}
if ($handled > 0) {
    Log::info('Telegram (consulta): {n} mensaje(s) atendidos', ['n' => $handled]);
}
flock($lock, LOCK_UN);
