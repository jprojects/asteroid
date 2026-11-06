<?php
declare(strict_types=1);

namespace Quasar\MailImport;

/**
 * Asteroid · lee un correo (.eml, RFC 822 / MIME) sin librerías: remitente, asunto, fecha, el texto (preferiblemente
 * el HTML, sin el historial citado ni la firma) y los adjuntos. También acepta el texto de un correo pegado.
 * Lo usa «Des d'un correu» (IssueFromMailPage) para crear una incidencia.
 */
final class EmlParser
{
    /** Marcas del inicio del historial citado en el HTML de los clientes de correo habituales */
    private const HTML_QUOTES = [
        '/<div[^>]+class="[^"]*\b(yahoo_quoted|gmail_quote|gmail_attr)\b/i',
        '/<blockquote[^>]*(type="cite"|class="[^"]*gmail_quote)/i',
        '/<div[^>]+id="(divRplyFwdMsg|appendonsend|mail-editor-reference-message-container)"/i',
        '/<div[^>]+class="[^"]*\b(moz-cite-prefix|OutlookMessageHeader|protonmail_quote)\b/i',
        '/<hr[^>]*id="stopSpelling"/i',
    ];

    /** Líneas que empiezan el historial citado en texto plano */
    private const TEXT_QUOTES = [
        '/^\s*(El|On|Le|Am|Il|Em)\b.{4,200}\b(escribió|wrote|va escriure|a écrit|schrieb|ha scritto|escreveu)\s*:\s*$/iu',
        '/^\s*-{2,}\s*(Original Message|Mensaje original|Missatge original)\s*-{2,}/i',
        '/^\s*(De|From|Remitente)\s*:\s*.+$/iu',
        '/^\s*_{10,}\s*$/',
    ];

    /**
     * @return array{from_name:string,from_email:string,subject:string,date:?string,html:string,text:string,
     *               attachments:list<array{name:string,mime:string,data:string}>,forwarded:bool}
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        [$headers, $body] = self::split($raw);
        $out = ['html' => '', 'text' => '', 'attachments' => []];
        self::walk($headers, $body, $out);

        $from = self::address(self::header($headers, 'from'));
        $subject = self::decodeHeader(self::header($headers, 'subject'));
        $date = self::header($headers, 'date');
        $ts = $date !== '' ? strtotime($date) : false;
        $result = [
            'from_name' => $from['name'],
            'from_email' => $from['email'],
            'subject' => $subject,
            'date' => $ts !== false ? date('Y-m-d H:i', $ts) : null,
            'html' => $out['html'],
            'text' => $out['text'],
            'attachments' => $out['attachments'],
            'forwarded' => (bool) preg_match('/^\s*(fwd?|rv|reenv)\s*:/i', $subject),
        ];
        // Reenviado (p. ej. por alguien del equipo): el remitente de verdad es el del mensaje reenviado
        if ($result['forwarded'] && preg_match('/(?:From|De|Remitente)\s*:\s*([^\n<]*)<([^>\s]+@[^>\s]+)>/iu', $out['text'] !== '' ? $out['text'] : strip_tags($out['html']), $m)) {
            $result['from_name'] = trim($m[1], " \t\"'*");
            $result['from_email'] = strtolower($m[2]);
        }
        return $result;
    }

    /**
     * Texto pegado: intenta leer «De:», «Asunto:» y «Fecha:» de las primeras líneas; el resto es el cuerpo.
     * @return array<string,mixed> como parse(), sin adjuntos
     */
    public static function fromText(string $text): array
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        $out = ['from_name' => '', 'from_email' => '', 'subject' => '', 'date' => null, 'html' => '', 'text' => '', 'attachments' => [], 'forwarded' => false];
        $lines = explode("\n", $text);
        $bodyStart = 0;
        foreach (array_slice($lines, 0, 12) as $i => $line) {
            if (preg_match('/^\s*(De|From|Remitent|Remitente)\s*:\s*(.+)$/iu', $line, $m)) {
                $a = self::address($m[2]);
                [$out['from_name'], $out['from_email']] = [$a['name'], $a['email']];
                $bodyStart = $i + 1;
            } elseif (preg_match('/^\s*(Asunto|Subject|Assumpte)\s*:\s*(.+)$/iu', $line, $m)) {
                $out['subject'] = trim($m[2]);
                $bodyStart = $i + 1;
            } elseif (preg_match('/^\s*(Fecha|Date|Data|Enviado|Sent)\s*:\s*(.+)$/iu', $line, $m) && ($ts = strtotime($m[2])) !== false) {
                $out['date'] = date('Y-m-d H:i', $ts);
                $bodyStart = $i + 1;
            } elseif (preg_match('/^\s*(Para|To|Per a|Cc)\s*:/iu', $line)) {
                $bodyStart = $i + 1;
            }
        }
        if ($out['from_email'] === '' && preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
            $out['from_email'] = strtolower($m[0]); // al menos un correo del texto
        }
        $out['text'] = implode("\n", array_slice($lines, $bodyStart));
        return $out;
    }

    /** HTML para la descripción: el del correo sin el historial, o el texto convertido en párrafos. */
    public static function bodyHtml(array $mail): string
    {
        if (trim($mail['html']) !== '') {
            $html = $mail['html'];
            if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $m)) {
                $html = $m[1];
            }
            if (!$mail['forwarded']) {
                $cut = strlen($html);
                foreach (self::HTML_QUOTES as $re) {
                    if (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 0) {
                        $cut = min($cut, $m[0][1]);
                    }
                }
                $html = substr($html, 0, $cut);
            }
            $html = (string) preg_replace(['#<(style|script|head|title)[^>]*>.*?</\1>#is', '#<!--.*?-->#s', '#<img[^>]+src="cid:[^"]*"[^>]*>#i'], '', $html);
            if (trim(strip_tags($html)) !== '') {
                return $html;
            }
        }
        $text = self::cleanText($mail['text'], $mail['forwarded']);
        $paragraphs = preg_split("/\n{2,}/", $text) ?: [];
        return implode('', array_map(static fn ($p) => '<p>' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES)) . '</p>', array_filter($paragraphs, static fn ($p) => trim($p) !== '')));
    }

    /** Texto sin el historial citado (líneas con «>» y lo que sigue a «El … va escriure:») ni la firma («-- »). */
    public static function cleanText(string $text, bool $forwarded = false): string
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            if (rtrim($line) === '--') {
                break; // firma
            }
            if (!$forwarded) {
                foreach (self::TEXT_QUOTES as $re) {
                    if (preg_match($re, $line) && $out) {
                        break 2;
                    }
                }
                if (str_starts_with(ltrim($line), '>')) {
                    continue;
                }
            }
            $out[] = rtrim($line);
        }
        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
    }

    // ---------------------------------------------------------------- MIME

    /** @return array{0:array<string,string>,1:string} cabeceras (en minúsculas, sin plegar) y cuerpo */
    private static function split(string $raw): array
    {
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $headers = [];
        foreach (preg_split("/\n(?![ \t])/", $head) ?: [] as $line) {
            if (($c = strpos($line, ':')) === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $c)));
            $value = trim((string) preg_replace("/\n[ \t]+/", ' ', substr($line, $c + 1)));
            $headers[$name] ??= $value; // la primera (Received… se repiten)
        }
        return [$headers, $body];
    }

    private static function walk(array $headers, string $body, array &$out, int $depth = 0): void
    {
        if ($depth > 8) {
            return;
        }
        [$type, $params] = self::contentType(self::header($headers, 'content-type') ?: 'text/plain');
        $disposition = strtolower(self::header($headers, 'content-disposition'));
        if (str_starts_with($type, 'multipart/') && isset($params['boundary'])) {
            $parts = explode("\n--" . $params['boundary'], "\n" . $body);
            array_shift($parts); // preámbulo
            foreach ($parts as $part) {
                if (str_starts_with($part, '--')) {
                    break; // cierre
                }
                [$h, $b] = self::split(ltrim($part, "\n"));
                self::walk($h, $b, $out, $depth + 1);
            }
            return;
        }
        if ($type === 'message/rfc822') {
            [$h, $b] = self::split(ltrim($body, "\n"));
            self::walk($h, $b, $out, $depth + 1);
            return;
        }
        $data = self::decodeBody($body, strtolower(self::header($headers, 'content-transfer-encoding')));
        $name = self::filename($headers, $params);
        $isAttachment = str_starts_with($disposition, 'attachment') || ($name !== '' && !in_array($type, ['text/plain', 'text/html'], true));
        if ($isAttachment) {
            // Las imágenes incrustadas en el HTML (cid:) no son adjuntos de verdad
            if (!(str_starts_with($disposition, 'inline') && self::header($headers, 'content-id') !== '' && str_starts_with($type, 'image/'))) {
                $out['attachments'][] = ['name' => $name !== '' ? $name : 'adjunt', 'mime' => $type, 'data' => $data];
            }
            return;
        }
        $charset = $params['charset'] ?? 'utf-8';
        if (strtolower($charset) !== 'utf-8') {
            $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
            $data = is_string($converted) ? $converted : $data;
        }
        if ($type === 'text/html' && $out['html'] === '') {
            $out['html'] = $data;
        } elseif ($type === 'text/plain' && $out['text'] === '') {
            $out['text'] = $data;
        }
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /** @return array{0:string,1:array<string,string>} */
    private static function contentType(string $value): array
    {
        $parts = explode(';', $value);
        $type = strtolower(trim((string) array_shift($parts)));
        $params = [];
        foreach ($parts as $p) {
            if (($e = strpos($p, '=')) !== false) {
                $params[strtolower(trim(substr($p, 0, $e)))] = trim(substr($p, $e + 1), " \t\"");
            }
        }
        return [$type, $params];
    }

    private static function filename(array $headers, array $typeParams): string
    {
        $disp = self::header($headers, 'content-disposition');
        if (preg_match("/filename\*\s*=\s*([^']*)'[^']*'([^;]+)/i", $disp, $m)) {
            $name = rawurldecode(trim($m[2], " \t\""));
            return $m[1] !== '' && strtolower($m[1]) !== 'utf-8' ? (string) @mb_convert_encoding($name, 'UTF-8', $m[1]) : $name;
        }
        if (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disp, $m)) {
            return self::decodeHeader(trim($m[1]));
        }
        return isset($typeParams['name']) ? self::decodeHeader($typeParams['name']) : '';
    }

    private static function header(array $headers, string $name): string
    {
        return (string) ($headers[$name] ?? '');
    }

    /** «=?UTF-8?Q?…?=» → texto */
    private static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return trim($value);
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return trim(is_string($decoded) ? $decoded : mb_decode_mimeheader($value));
    }

    /** @return array{name:string,email:string} */
    private static function address(string $value): array
    {
        $value = self::decodeHeader($value);
        if (preg_match('/^(.*)<([^>]+)>/', $value, $m)) {
            return ['name' => trim($m[1], " \t\"'"), 'email' => strtolower(trim($m[2]))];
        }
        return ['name' => '', 'email' => strtolower(trim($value, " \t<>"))];
    }
}
