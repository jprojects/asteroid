#!/usr/bin/env php
<?php
/**
 * Asteroid · modo consulta del bot de Telegram ('telegram' => ['mode' => 'poll', …]): cuando el hosting no deja entrar
 * las peticiones de Telegram al webhook, el cron pregunta a Telegram por los mensajes nuevos (getUpdates) y los atiende
 * igual que el webhook (Quasar\Telegram\Poller). Cron cada minuto, con el mismo PHP que la web:
 *
 *   * * * * * /opt/alt/php82/usr/bin/php /ruta/a/asteroid/tools/telegram-poll.php > /dev/null 2>&1
 *
 * Cada ejecución espera mensajes hasta ~55 s, así que las respuestas llegan casi al momento.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (PHP_VERSION_ID < 80100) {
    fwrite(STDERR, 'Asteroid necesita PHP 8.1 o superior y este cron usa ' . PHP_VERSION . ": pon la ruta del PHP de la web\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;
use Quasar\Telegram\Poller;
use Quasar\Telegram\Telegram;

if (!Telegram::enabled() || (($config['telegram']['mode'] ?? 'webhook') !== 'poll')) {
    fwrite(STDERR, "Telegram no está en modo consulta ('mode' => 'poll' en la configuración de telegram)\n");
    exit(0);
}
$n = Poller::run(new App($config), 55);
echo $n === null ? "Otra consulta en marcha\n" : "Mensajes atendidos: $n\n";
