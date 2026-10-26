<?php
declare(strict_types=1);

namespace Quasar\Mail;

/**
 * Mensaje de correo con API encadenable:
 *
 *   $msg = (new Message())
 *       ->to('ana@ejemplo.es', 'Ana Pérez')
 *       ->cc('jefe@ejemplo.es')
 *       ->subject('Pedido nº 125 confirmado')
 *       ->html('<p>Hola Ana…</p>')          // la versión de texto se genera sola si no se indica
 *       ->attach('/ruta/factura.pdf');
 */
final class Message
{
    private ?array $from = null;
    private ?array $replyTo = null;
    /** @var array<string,list<array{0:string,1:string}>> */
    private array $recipients = ['to' => [], 'cc' => [], 'bcc' => []];
    private string $subject = '';
    private ?string $text = null;
    private ?string $html = null;
    /** @var list<array{data:string,name:string,mime:string,cid:?string}> */
    private array $attachments = [];
    /** @var array<string,string> cabeceras adicionales */
    private array $headers = [];

    // ---------------------------------------------------------------- construcción

    public function from(string $address, string $name = ''): self
    {
        $this->from = self::address($address, $name);
        return $this;
    }

    public function replyTo(string $address, string $name = ''): self
    {
        $this->replyTo = self::address($address, $name);
        return $this;
    }

    public function to(string $address, string $name = ''): self
    {
        $this->recipients['to'][] = self::address($address, $name);
        return $this;
    }

    public function cc(string $address, string $name = ''): self
    {
        $this->recipients['cc'][] = self::address($address, $name);
        return $this;
    }

    public function bcc(string $address, string $name = ''): self
    {
        $this->recipients['bcc'][] = self::address($address, $name);
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = self::singleLine($subject);
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    public function header(string $name, string $value): self
    {
        if (!preg_match('/^X-[A-Za-z0-9-]+$/', $name)) {
            throw new \InvalidArgumentException('Solo se permiten cabeceras X-…');
        }
        $this->headers[$name] = self::singleLine($value);
        return $this;
    }

    /** Adjunta un fichero del disco. */
    public function attach(string $path, ?string $name = null, ?string $mime = null): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new MailException("No se puede leer el adjunto: $path");
        }
        return $this->attachData($data, $name ?? basename($path), $mime ?? self::mimeOf($path));
    }

    /** Adjunta contenido generado en memoria (p. ej. un PDF o un XLSX). */
    public function attachData(string $data, string $name, string $mime = 'application/octet-stream'): self
    {
        $this->attachments[] = ['data' => $data, 'name' => self::singleLine($name), 'mime' => $mime, 'cid' => null];
        return $this;
    }

    /**
     * Incrusta una imagen para usarla en el HTML: <img src="cid:…">.
     * @return string el valor para el atributo src
     */
    public function embed(string $path, ?string $name = null): string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new MailException("No se puede leer la imagen: $path");
        }
        $cid = bin2hex(random_bytes(8)) . '@quasar';
        $this->attachments[] = ['data' => $data, 'name' => $name ?? basename($path), 'mime' => self::mimeOf($path), 'cid' => $cid];
        return 'cid:' . $cid;
    }

    // ---------------------------------------------------------------- lectura

    public function getFrom(): ?array
    {
        return $this->from;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    /** @return list<string> todas las direcciones de destino (to, cc y bcc) */
    public function envelopeRecipients(): array
    {
        $all = array_merge($this->recipients['to'], $this->recipients['cc'], $this->recipients['bcc']);
        return array_values(array_unique(array_map(static fn ($a) => $a[0], $all)));
    }

    /** @return array<string,list<string>> para el log */
    public function summary(): array
    {
        $fmt = static fn (array $list) => array_map(static fn ($a) => $a[0], $list);
        return [
            'to' => $fmt($this->recipients['to']),
            'cc' => $fmt($this->recipients['cc']),
            'bcc' => $fmt($this->recipients['bcc']),
            'subject' => $this->subject,
            'attachments' => array_values(array_map(static fn ($a) => $a['name'], array_filter($this->attachments, static fn ($a) => $a['cid'] === null))),
        ];
    }

    // ---------------------------------------------------------------- MIME

    /** Mensaje completo RFC 5322 con líneas CRLF (sin Bcc). */
    public function toMime(string $defaultDomain = 'localhost'): string
    {
        if ($this->from === null) {
            throw new MailException('El mensaje no tiene remitente (from)');
        }
        if (!$this->envelopeRecipients()) {
            throw new MailException('El mensaje no tiene destinatarios');
        }
        if ($this->text === null && $this->html === null) {
            throw new MailException('El mensaje no tiene cuerpo');
        }

        $domain = substr((string) strrchr($this->from[0], '@'), 1) ?: $defaultDomain;
        $headers = [
            'Date' => date('r'),
            'From' => self::formatAddress($this->from),
        ];
        if ($this->recipients['to']) {
            $headers['To'] = implode(', ', array_map([self::class, 'formatAddress'], $this->recipients['to']));
        } else {
            $headers['To'] = 'undisclosed-recipients:;';
        }
        if ($this->recipients['cc']) {
            $headers['Cc'] = implode(', ', array_map([self::class, 'formatAddress'], $this->recipients['cc']));
        }
        if ($this->replyTo) {
            $headers['Reply-To'] = self::formatAddress($this->replyTo);
        }
        $headers['Subject'] = self::encodeHeader($this->subject);
        $headers['Message-ID'] = '<' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
        $headers['MIME-Version'] = '1.0';
        $headers['X-Mailer'] = 'Quasar';
        $headers += $this->headers;

        [$partHeaders, $body] = $this->buildBody();
        $out = '';
        foreach ($headers + $partHeaders as $name => $value) {
            $out .= "$name: $value\r\n";
        }
        return $out . "\r\n" . $body;
    }

    /** @return array{0:array<string,string>,1:string} cabeceras de contenido y cuerpo */
    private function buildBody(): array
    {
        $text = $this->text ?? self::htmlToText((string) $this->html);
        $part = $this->html === null
            ? self::textPart($text, 'plain')
            : self::multipart('alternative', [self::textPart($text, 'plain'), self::textPart($this->html, 'html')]);

        $inline = array_filter($this->attachments, static fn ($a) => $a['cid'] !== null);
        $files = array_filter($this->attachments, static fn ($a) => $a['cid'] === null);
        if ($inline) {
            $part = self::multipart('related', [$part, ...array_map([self::class, 'filePart'], $inline)]);
        }
        if ($files) {
            $part = self::multipart('mixed', [$part, ...array_map([self::class, 'filePart'], $files)]);
        }
        return $part;
    }

    private static function textPart(string $content, string $subtype): array
    {
        $content = (string) preg_replace('/\r\n?|\n/', "\r\n", $content);
        return [
            ['Content-Type' => "text/$subtype; charset=UTF-8", 'Content-Transfer-Encoding' => 'quoted-printable'],
            quoted_printable_encode($content),
        ];
    }

    private static function filePart(array $file): array
    {
        $name = self::encodeHeader($file['name']);
        $headers = [
            'Content-Type' => $file['mime'] . '; name="' . $name . '"',
            'Content-Transfer-Encoding' => 'base64',
            'Content-Disposition' => ($file['cid'] ? 'inline' : 'attachment') . '; filename="' . $name . '"',
        ];
        if ($file['cid']) {
            $headers['Content-ID'] = '<' . $file['cid'] . '>';
        }
        return [$headers, rtrim(chunk_split(base64_encode($file['data']), 76, "\r\n"))];
    }

    /** @param list<array{0:array,1:string}> $parts */
    private static function multipart(string $subtype, array $parts): array
    {
        $boundary = '=_qs_' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($parts as [$headers, $content]) {
            $body .= "--$boundary\r\n";
            foreach ($headers as $name => $value) {
                $body .= "$name: $value\r\n";
            }
            $body .= "\r\n" . $content . "\r\n";
        }
        $body .= "--$boundary--";
        return [['Content-Type' => "multipart/$subtype; boundary=\"$boundary\""], $body];
    }

    // ---------------------------------------------------------------- utilidades

    /** @return array{0:string,1:string} */
    private static function address(string $address, string $name): array
    {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new MailException("Dirección de correo no válida: $address");
        }
        return [$address, self::singleLine($name)];
    }

    private static function formatAddress(array $a): string
    {
        if ($a[1] === '') {
            return $a[0];
        }
        $name = preg_match('/^[A-Za-z0-9 !#$%&\'*+\/=?^_`{|}~.-]+$/', $a[1])
            ? '"' . $a[1] . '"'
            : self::encodeHeader($a[1]);
        return $name . ' <' . $a[0] . '>';
    }

    /** RFC 2047 si hay caracteres no ASCII. */
    public static function encodeHeader(string $value): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }
        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    /** Impide la inyección de cabeceras (saltos de línea). */
    private static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\t]+/', ' ', $value));
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('~<(style|script|head)\b.*?</\1>~is', '', $html);
        // Texto oculto de vista previa (preheader): no aporta nada en la versión de texto
        $html = (string) preg_replace('~<div[^>]*display:\s*none[^>]*>.*?</div>~is', '', $html);
        // En HTML los espacios y saltos de línea del código fuente no significan nada
        $html = (string) preg_replace('/\s+/u', ' ', $html);
        $html = (string) preg_replace_callback(
            '~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is',
            static fn ($m) => trim(strip_tags($m[2])) . (str_starts_with($m[1], 'http') ? " ({$m[1]})" : ''),
            $html
        );
        $html = (string) preg_replace('~<(br|/p|/div|/h[1-6]|/tr|/li|/table)\b[^>]*>~i', "\n", $html);
        $html = (string) preg_replace('~<(p|h[1-6])\b[^>]*>~i', "\n", $html);
        $html = (string) preg_replace('~</t[dh]>~i', '   ', $html);
        $html = (string) preg_replace('~<li\b[^>]*>~i', '- ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $lines = array_map(static fn ($l) => trim((string) preg_replace('/ {4,}/', '   ', $l)), explode("\n", $text));
        return trim((string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
    }

    private static function mimeOf(string $path): string
    {
        $mime = function_exists('mime_content_type') ? @mime_content_type($path) : false;
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }
}
