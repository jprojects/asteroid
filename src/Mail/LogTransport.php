<?php
declare(strict_types=1);

namespace Quasar\Mail;

use Quasar\Core\Logger;

/**
 * No envía nada: escribe el mensaje en el log del canal "mail" y guarda el
 * .eml completo en storage/logs/mail/ para abrirlo con un cliente de correo.
 * Pensado para desarrollo (mail.transport = 'log').
 */
final class LogTransport implements Transport
{
    public function __construct(
        private readonly Logger $log,
        private readonly string $dir,
    ) {
    }

    public function send(Message $message): void
    {
        $mime = $message->toMime();
        $file = null;
        if (is_dir($this->dir) || @mkdir($this->dir, 0775, true)) {
            $file = sprintf('%s/%s-%s.eml', $this->dir, date('Ymd-His'), bin2hex(random_bytes(3)));
            file_put_contents($file, $mime);
        }
        $this->log->info('Correo NO enviado (transporte log): {subject}', $message->summary() + ['eml' => $file]);
    }
}
