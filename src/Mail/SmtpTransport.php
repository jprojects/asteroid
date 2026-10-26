<?php
declare(strict_types=1);

namespace Quasar\Mail;

use Quasar\Core\Logger;

/**
 * Cliente SMTP sin dependencias.
 *
 * encryption: 'ssl' (TLS implícito, normalmente puerto 465), 'tls' (STARTTLS,
 * normalmente 587) o 'none' (sin cifrar, solo para servidores locales).
 * Autenticación AUTH PLAIN o LOGIN si se indica usuario.
 * La conversación se registra en el canal de log "mail" a nivel debug
 * (las credenciales nunca se escriben).
 */
final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $socket = null;
    /** @var list<string> */
    private array $capabilities = [];

    public function __construct(
        private readonly array $config,
        private readonly Logger $log,
    ) {
    }

    public function send(Message $message): void
    {
        $from = $message->getFrom() ?? throw new MailException('El mensaje no tiene remitente');
        $data = $message->toMime($this->helo());
        try {
            $this->connect();
            $this->command('MAIL FROM:<' . $from[0] . '>', [250]);
            foreach ($message->envelopeRecipients() as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', [250, 251]);
            }
            $this->command('DATA', [354]);
            // Normaliza a CRLF y aplica "dot-stuffing" (RFC 5321 §4.5.2)
            $data = (string) preg_replace('/\r\n?|\n/', "\r\n", $data);
            $data = (string) preg_replace('/^\./m', '..', $data);
            $this->write($data . "\r\n.\r\n", '[contenido del mensaje: ' . strlen($data) . ' bytes]');
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $host = (string) ($this->config['host'] ?? '');
        if ($host === '') {
            throw new MailException('Falta mail.host en la configuración');
        }
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'tls'));
        $port = (int) ($this->config['port'] ?? ($encryption === 'ssl' ? 465 : 587));
        $timeout = (float) ($this->config['timeout'] ?? 15);
        $verify = ($this->config['verify_peer'] ?? true) !== false;

        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'peer_name' => $host,
        ]]);
        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $this->log->debug('Conectando con {remote}', ['remote' => $remote]);

        $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException("No se puede conectar con $remote: $errstr ($errno)");
        }
        $this->socket = $socket;
        stream_set_timeout($socket, (int) ceil($timeout));

        $this->expect([220]);
        $this->ehlo();

        if ($encryption === 'tls') {
            if (!$this->supports('STARTTLS')) {
                throw new MailException('El servidor no admite STARTTLS (prueba encryption "ssl" o revisa el puerto)');
            }
            $this->command('STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (@stream_socket_enable_crypto($socket, true, $crypto) !== true) {
                throw new MailException('No se pudo negociar TLS con el servidor (¿certificado no válido? ver mail.verify_peer)');
            }
            $this->ehlo();
        }

        $user = (string) ($this->config['username'] ?? '');
        if ($user !== '') {
            $this->authenticate($user, (string) ($this->config['password'] ?? ''));
        }
    }

    private function ehlo(): void
    {
        $lines = $this->command('EHLO ' . $this->helo(), [250]);
        $this->capabilities = array_map(static fn ($l) => strtoupper(substr($l, 4)), $lines);
    }

    private function supports(string $capability): bool
    {
        foreach ($this->capabilities as $c) {
            if ($c === $capability || str_starts_with($c, $capability . ' ')) {
                return true;
            }
        }
        return false;
    }

    private function authenticate(string $user, string $pass): void
    {
        $auth = '';
        foreach ($this->capabilities as $c) {
            if (str_starts_with($c, 'AUTH ') || str_starts_with($c, 'AUTH=')) {
                $auth .= ' ' . substr($c, 5);
            }
        }
        $methods = preg_split('/\s+/', trim($auth)) ?: [];
        if (in_array('PLAIN', $methods, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235], 'AUTH PLAIN ***');
        } elseif (in_array('LOGIN', $methods, true)) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($user), [334], '*** (usuario)');
            $this->command(base64_encode($pass), [235], '*** (contraseña)');
        } else {
            throw new MailException('El servidor no ofrece AUTH PLAIN ni LOGIN' . ($auth === '' ? ' (¿falta cifrado?)' : ": $auth"));
        }
    }

    /** @return list<string> líneas de respuesta */
    private function command(string $line, array $expected, ?string $logAs = null): array
    {
        $this->write($line . "\r\n", $logAs ?? $line);
        return $this->expect($expected);
    }

    private function write(string $data, string $logAs): void
    {
        $this->log->debug('> ' . $logAs);
        $len = strlen($data);
        for ($written = 0; $written < $len; $written += $n) {
            $n = @fwrite($this->socket, substr($data, $written, 65536));
            if ($n === false || $n === 0) {
                throw new MailException('Conexión SMTP interrumpida al escribir');
            }
        }
    }

    /** @return list<string> */
    private function expect(array $codes): array
    {
        $lines = [];
        while (true) {
            $line = fgets($this->socket, 4096);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new MailException($meta['timed_out'] ? 'Tiempo de espera agotado con el servidor SMTP' : 'El servidor SMTP cerró la conexión');
            }
            $line = rtrim($line, "\r\n");
            $this->log->debug('< ' . $line);
            $lines[] = $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        $code = (int) substr($lines[array_key_last($lines)], 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new MailException('Respuesta SMTP inesperada: ' . implode(' | ', $lines), $code);
        }
        return $lines;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function helo(): string
    {
        $helo = (string) ($this->config['helo'] ?? '');
        if ($helo === '') {
            $helo = (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost');
        }
        return (string) preg_replace('/[^A-Za-z0-9.-]/', '', $helo) ?: 'localhost';
    }
}
