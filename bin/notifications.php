#!/usr/bin/env php
<?php
/**
 * Envía por correo los avisos pendientes (config 'notifications' => ['email' => 'queue']): un solo
 * correo por usuario con todos sus avisos nuevos. Programarlo en el cron cada pocos minutos:
 *
 *   php bin/notifications.php
 *
 * Necesita 'base_url' en config.local.php para que los enlaces de los correos sean completos.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;
use Quasar\Core\Log;

if (empty($config['base_url'])) {
    fwrite(STDERR, "Aviso: falta 'base_url' en config.local.php; los enlaces de los correos no serán completos.\n");
}

try {
    $app = new App($config);
    $stats = $app->notifier()->sendPending();
    printf("%d correo(s) a %d usuario(s) con %d aviso(s)%s.%s",
        $stats['users'], $stats['users'], $stats['notifications'], $stats['failed'] ? ", {$stats['failed']} fallido(s)" : '', PHP_EOL);
    exit($stats['failed'] ? 2 : 0);
} catch (\Throwable $e) {
    Log::error('bin/notifications.php: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
