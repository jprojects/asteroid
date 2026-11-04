<?php
declare(strict_types=1);

namespace Quasar\Mail;

/**
 * Envío con la función mail() de PHP: el correo sale por el sendmail del propio servidor (lo normal en un hosting
 * compartido), sin datos SMTP. mail.transport = 'mail', o 'auto' (por defecto) cuando no hay servidor SMTP configurado.
 *
 * El mensaje es el mismo que por SMTP (Message::toMime()): mail() recibe aparte el destinatario y el asunto, y el resto
 * de cabeceras tal cual. El remitente del sobre (-f) es el de "from", para que el SPF del dominio cuadre.
 */
final class PhpMailTransport implements Transport
{
    public function send(Message $message): void
    {
        if (!function_exists('mail')) {
            throw new MailException('La función mail() de PHP no está disponible en este servidor: configura un servidor SMTP');
        }
        $mime = $message->toMime((string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost'));
        [$head, $body] = explode("\r\n\r\n", $mime, 2) + [1 => ''];

        $to = '';
        $subject = '';
        $headers = [];
        // Una cabecera por línea lógica (las plegadas continúan con espacio o tabulador)
        foreach (preg_split("/\r\n(?![ \t])/", $head) ?: [] as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $key = strtolower(trim($name));
            if ($key === 'to') {
                $to = trim((string) preg_replace("/\r\n[ \t]+/", ' ', $value));
            } elseif ($key === 'subject') {
                $subject = trim((string) preg_replace("/\r\n[ \t]+/", ' ', $value));
            } else {
                $headers[] = $line;
            }
        }
        // Las copias ocultas no van en el mensaje: el sendmail las lee de la cabecera Bcc y la quita
        if ($bcc = $message->recipientsOf('bcc')) {
            $headers[] = 'Bcc: ' . implode(', ', $bcc);
        }

        $from = $message->getFrom()[0] ?? '';
        $params = filter_var($from, FILTER_VALIDATE_EMAIL) ? '-f' . $from : '';
        if (!mail($to, $subject, $body, implode("\r\n", $headers), $params)) {
            $error = error_get_last()['message'] ?? 'el servidor no lo ha aceptado';
            throw new MailException("mail() no ha podido enviar el correo ($error)");
        }
    }
}
