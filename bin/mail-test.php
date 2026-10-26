#!/usr/bin/env php
<?php
/**
 * Comprueba la configuración de correo enviando un mensaje de prueba.
 *
 *   php bin/mail-test.php destinatario@ejemplo.es
 *
 * Muestra la conversación SMTP completa (sin credenciales) si algo falla.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;
use Quasar\Core\Log;
use Quasar\Mail\Mail;

$to = $argv[1] ?? '';
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php bin/mail-test.php destinatario@ejemplo.es\n");
    exit(1);
}

// Para esta prueba el canal de correo registra la conversación completa
$config['log']['level'] = 'debug';
Log::configure($config['log']);

$app = new App($config);
$mail = $config['mail'] ?? [];
$settings = [
    'Transporte' => (string) ($mail['transport'] ?? 'smtp'),
    'Servidor' => ($mail['host'] ?? '?') . ':' . ($mail['port'] ?? '?'),
    'Cifrado' => (string) ($mail['encryption'] ?? 'tls'),
    'Usuario' => (string) (($mail['username'] ?? '') ?: '(sin autenticación)'),
    'Remitente' => (string) ($mail['from']['address'] ?? '?'),
    'Fecha' => date('d/m/Y H:i:s'),
];
foreach ($settings as $k => $v) {
    printf("  %-11s %s\n", $k, $v);
}

$logFile = sprintf('%s/mail-%s.log', rtrim($config['log']['dir'], '/'), date('Y-m-d'));
$offset = is_file($logFile) ? filesize($logFile) : 0;

try {
    $message = Mail::compose('emails/test', ['settings' => $settings, 'preheader' => 'Prueba de correo de Quasar'])
        ->to($to)
        ->subject($app->lang->get('mail.test.subject', ['app' => $config['app_name'] ?? 'Quasar']));
    Mail::send($message);
    echo Mail::mailer()->isFake()
        ? "\n• Transporte 'log': el correo NO se ha enviado; está en " . rtrim($config['log']['dir'], '/') . "/mail/\n"
        : "\n✓ Enviado a $to\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\n✗ Error: {$e->getMessage()}\n");
    if (is_file($logFile)) {
        fwrite(STDERR, "\nConversación SMTP ($logFile):\n" . substr((string) file_get_contents($logFile), $offset));
    }
    exit(1);
}
