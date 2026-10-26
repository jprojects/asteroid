<?php
declare(strict_types=1);

namespace Quasar\Mail;

use Quasar\Core\Logger;
use Quasar\Core\View;

/**
 * Servicio de correo. Se obtiene con $app->mailer() o con la fachada Mail.
 *
 *   $msg = Mail::compose('emails/aviso', ['nombre' => 'Ana', 'pedido' => 125])
 *       ->to('ana@ejemplo.es', 'Ana')
 *       ->subject('Tu pedido 125');
 *   Mail::send($msg);
 */
final class Mailer
{
    private ?Transport $transport = null;

    /**
     * @param array{logo:?string,logo_pdf:?string,color:?string,company:string} $branding
     */
    public function __construct(
        private readonly array $config,
        private readonly Logger $log,
        private readonly ?View $view,
        private readonly array $branding,
        private readonly string $logDir,
    ) {
    }

    /** Mensaje vacío con el remitente por defecto de la configuración. */
    public function message(): Message
    {
        $msg = new Message();
        $from = (array) ($this->config['from'] ?? []);
        if (!empty($from['address'])) {
            $msg->from((string) $from['address'], (string) ($from['name'] ?? ''));
        }
        $reply = (array) ($this->config['reply_to'] ?? []);
        if (!empty($reply['address'])) {
            $msg->replyTo((string) $reply['address'], (string) ($reply['name'] ?? ''));
        }
        return $msg;
    }

    /**
     * Mensaje con HTML generado desde una plantilla (templates/{plantilla}/{template}.php)
     * envuelta en el layout de emails (emails/layout.php), con el logo del cliente incrustado.
     */
    public function compose(string $template, array $vars = []): Message
    {
        if ($this->view === null) {
            throw new MailException('No hay motor de plantillas disponible');
        }
        $msg = $this->message();
        $logo = $this->branding['logo_pdf'] ?? null; // PNG/JPG: los clientes de correo no muestran SVG
        $vars += [
            'logoSrc' => $logo ? $msg->embed($logo, 'logo.' . pathinfo($logo, PATHINFO_EXTENSION)) : null,
            'color' => $this->branding['color'] ?? '#2563eb',
            'company' => $this->branding['company'] ?? '',
        ];
        return $msg->html($this->view->render($template, $vars, 'emails/layout'));
    }

    /** Envía el mensaje. Lanza MailException si falla (y lo deja en el log). */
    public function send(Message $message): void
    {
        if ($message->getFrom() === null) {
            $defaults = $this->message();
            if ($defaults->getFrom() === null) {
                throw new MailException('Falta el remitente: indica mail.from.address en la configuración');
            }
            $message->from(...$defaults->getFrom());
        }
        $start = microtime(true);
        try {
            $transport = $this->transport();
            $transport->send($message);
            if (!$transport instanceof LogTransport) { // LogTransport ya deja su propia línea
                $this->log->info('Correo enviado: {subject}', $message->summary() + [
                    'ms' => (int) round((microtime(true) - $start) * 1000),
                ]);
            }
        } catch (\Throwable $e) {
            $this->log->error('Error enviando correo: {subject}', $message->summary() + ['exception' => $e]);
            throw $e instanceof MailException ? $e : new MailException($e->getMessage(), 0, $e);
        }
    }

    /** Envía sin lanzar excepciones; devuelve false si falla (el error queda en el log). */
    public function trySend(Message $message): bool
    {
        try {
            $this->send($message);
            return true;
        } catch (MailException) {
            return false;
        }
    }

    public function isFake(): bool
    {
        return $this->transport() instanceof LogTransport;
    }

    private function transport(): Transport
    {
        return $this->transport ??= match ($this->config['transport'] ?? 'smtp') {
            'smtp' => new SmtpTransport($this->config, $this->log),
            'log' => new LogTransport($this->log, $this->logDir . '/mail'),
            default => throw new MailException('mail.transport debe ser "smtp" o "log"'),
        };
    }
}
