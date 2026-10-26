<?php
declare(strict_types=1);

namespace Quasar\Export;

/**
 * Generador PDF mínimo sin dependencias: páginas A4, texto con Helvetica /
 * Helvetica-Bold (WinAnsi: cubre español y el resto de idiomas de Europa
 * occidental), rectángulos, líneas e imágenes JPEG o PNG (con transparencia).
 *
 * Las coordenadas son en puntos con el origen ARRIBA a la izquierda; para texto,
 * "y" es la línea base.
 */
final class PdfDocument
{
    private const A4 = [595.28, 841.89];

    private float $w;
    private float $h;
    /** @var list<string> contenido de cada página */
    private array $pages = [];
    /** @var array<string,array> imágenes registradas, por ruta de fichero */
    private array $images = [];
    private string $title = '';
    /** Página en la que se dibuja; null = la última */
    private ?int $current = null;

    public function __construct(string $orientation = 'portrait')
    {
        [$this->w, $this->h] = $orientation === 'landscape' ? [self::A4[1], self::A4[0]] : self::A4;
    }

    public function width(): float
    {
        return $this->w;
    }

    public function height(): float
    {
        return $this->h;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = null;
    }

    /** Selecciona una página existente (0-based) para seguir dibujando en ella (p. ej. pies). */
    public function setPage(int $index): void
    {
        if (!isset($this->pages[$index])) {
            throw new \OutOfRangeException("Página $index inexistente");
        }
        $this->current = $index;
    }

    private function out(string $op): void
    {
        $i = $this->current ?? array_key_last($this->pages);
        if ($i === null) {
            throw new \LogicException('Llama a addPage() antes de dibujar');
        }
        $this->pages[$i] .= $op . "\n";
    }

    // ---------------------------------------------------------------- texto

    /**
     * UTF-8 -> WinAnsi (cp1252). Lo que no existe en cp1252 se translitera
     * (Ł -> L, ź -> z) y, si no es posible (alfabetos no latinos), se sustituye por "?".
     */
    public static function encode(string $utf8): string
    {
        $utf8 = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $utf8);
        $utf8 = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $utf8);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $utf8);
        return $out !== false ? $out : (string) mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
    }

    public function textWidth(string $utf8, float $size, bool $bold = false): float
    {
        return PdfFontMetrics::width(self::encode($utf8), $size, $bold);
    }

    /** Recorta el texto con "…" para que quepa en $maxWidth. */
    public function fitText(string $utf8, float $maxWidth, float $size, bool $bold = false): string
    {
        $enc = self::encode($utf8);
        if (PdfFontMetrics::width($enc, $size, $bold) <= $maxWidth) {
            return $enc;
        }
        $ellipsis = "\x85"; // … en cp1252
        $available = $maxWidth - PdfFontMetrics::width($ellipsis, $size, $bold);
        $widths = PdfFontMetrics::widths($bold);
        $total = 0.0;
        $len = strlen($enc);
        for ($i = 0; $i < $len; $i++) {
            $total += $widths[ord($enc[$i])] * $size / 1000;
            if ($total > $available) {
                return rtrim(substr($enc, 0, $i)) . $ellipsis;
            }
        }
        return $enc;
    }

    /**
     * Escribe texto. Si $encoded es true, $text ya viene en WinAnsi (de fitText()).
     * @param array{0:float,1:float,2:float} $rgb valores 0..1
     */
    public function text(float $x, float $y, string $text, float $size, bool $bold = false, array $rgb = [0, 0, 0], bool $encoded = false): void
    {
        $enc = $encoded ? $text : self::encode($text);
        if ($enc === '') {
            return;
        }
        $this->out(sprintf(
            'BT %s rg /%s %s Tf %s %s Td (%s) Tj ET',
            self::rgb($rgb),
            $bold ? 'F2' : 'F1',
            self::n($size),
            self::n($x),
            self::n($this->h - $y),
            strtr($enc, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r'])
        ));
    }

    /** Texto alineado dentro de una caja de ancho $w (left|center|right). */
    public function textIn(float $x, float $y, float $w, string $encoded, float $size, bool $bold, string $align, array $rgb = [0, 0, 0]): void
    {
        $tw = PdfFontMetrics::width($encoded, $size, $bold);
        $tx = match ($align) {
            'right' => $x + $w - $tw,
            'center' => $x + ($w - $tw) / 2,
            default => $x,
        };
        $this->text($tx, $y, $encoded, $size, $bold, $rgb, true);
    }

    // ---------------------------------------------------------------- formas

    public function rect(float $x, float $y, float $w, float $h, ?array $fill = null, ?array $stroke = null, float $lineWidth = 0.5): void
    {
        if ($fill === null && $stroke === null) {
            return;
        }
        $ops = [];
        if ($fill !== null) {
            $ops[] = self::rgb($fill) . ' rg';
        }
        if ($stroke !== null) {
            $ops[] = self::rgb($stroke) . ' RG ' . self::n($lineWidth) . ' w';
        }
        $ops[] = sprintf('%s %s %s %s re %s', self::n($x), self::n($this->h - $y - $h), self::n($w), self::n($h), $fill && $stroke ? 'B' : ($fill ? 'f' : 'S'));
        $this->out(implode(' ', $ops));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, array $rgb = [0, 0, 0], float $width = 0.5): void
    {
        $this->out(sprintf(
            '%s RG %s w %s %s m %s %s l S',
            self::rgb($rgb),
            self::n($width),
            self::n($x1),
            self::n($this->h - $y1),
            self::n($x2),
            self::n($this->h - $y2)
        ));
    }

    // ---------------------------------------------------------------- imágenes

    /** Tamaño en píxeles de una imagen soportada, o null si no se puede usar. */
    public function imageInfo(string $file): ?array
    {
        try {
            return $this->registerImage($file);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Dibuja la imagen ajustada a la caja ($w x $h) manteniendo proporciones. Devuelve el ancho usado. */
    public function image(string $file, float $x, float $y, float $maxW, float $maxH): float
    {
        $img = $this->registerImage($file);
        $scale = min($maxW / $img['w'], $maxH / $img['h']);
        $w = $img['w'] * $scale;
        $h = $img['h'] * $scale;
        $this->out(sprintf(
            'q %s 0 0 %s %s %s cm /%s Do Q',
            self::n($w),
            self::n($h),
            self::n($x),
            self::n($this->h - $y - $h),
            $img['name']
        ));
        return $w;
    }

    private function registerImage(string $file): array
    {
        if (isset($this->images[$file])) {
            return $this->images[$file];
        }
        $data = @file_get_contents($file);
        if ($data === false) {
            throw new \RuntimeException("No se puede leer la imagen $file");
        }
        $img = str_starts_with($data, "\x89PNG\r\n\x1a\n") ? $this->parsePng($data) : $this->parseJpeg($file, $data);
        $img['name'] = 'Im' . (count($this->images) + 1);
        return $this->images[$file] = $img;
    }

    private function parseJpeg(string $file, string $data): array
    {
        $info = @getimagesize($file);
        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            throw new \RuntimeException('Formato de imagen no soportado (usa PNG o JPG)');
        }
        $channels = (int) ($info['channels'] ?? 3);
        return [
            'w' => $info[0],
            'h' => $info[1],
            'cs' => match ($channels) { 1 => '/DeviceGray', 4 => '/DeviceCMYK', default => '/DeviceRGB' },
            'bpc' => (int) ($info['bits'] ?? 8),
            'filter' => 'DCTDecode',
            'parms' => $channels === 4 ? '/Decode [1 0 1 0 1 0 1 0]' : '',
            'data' => $data,
            'smask' => null,
        ];
    }

    /**
     * PNG de 8 bits o menos, no entrelazado. Los datos IDAT se pasan tal cual
     * (PDF entiende los filtros PNG con /Predictor 15); con canal alfa se separa
     * en una SMask. Separar canales conserva la validez de los filtros PNG
     * porque cada filtro opera byte a byte dentro de un mismo canal.
     */
    private function parsePng(string $data): array
    {
        $pos = 8;
        $read = static function (int $n) use ($data, &$pos): string {
            $chunk = substr($data, $pos, $n);
            if (strlen($chunk) !== $n) {
                throw new \RuntimeException('PNG truncado');
            }
            $pos += $n;
            return $chunk;
        };
        $int = static fn (string $s): int => unpack('N', $s)[1];

        $read(4);
        if ($read(4) !== 'IHDR') {
            throw new \RuntimeException('PNG no válido');
        }
        $w = $int($read(4));
        $h = $int($read(4));
        $bpc = ord($read(1));
        $ct = ord($read(1));
        $read(2);
        $interlace = ord($read(1));
        $read(4); // CRC
        if ($bpc > 8 || $interlace !== 0 || !in_array($ct, [0, 2, 3, 4, 6], true)) {
            throw new \RuntimeException('PNG no soportado (16 bits o entrelazado)');
        }

        $pal = '';
        $trns = null;
        $idat = '';
        while ($pos < strlen($data)) {
            $len = $int($read(4));
            $type = $read(4);
            $body = $len > 0 ? $read($len) : '';
            $read(4);
            if ($type === 'PLTE') {
                $pal = $body;
            } elseif ($type === 'tRNS') {
                $trns = $body;
            } elseif ($type === 'IDAT') {
                $idat .= $body;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if ($ct === 3 && $pal === '') {
            throw new \RuntimeException('PNG indexado sin paleta');
        }

        $colors = in_array($ct, [2, 6], true) ? 3 : 1;
        $cs = match ($ct) {
            0, 4 => '/DeviceGray',
            2, 6 => '/DeviceRGB',
            3 => sprintf('[/Indexed /DeviceRGB %d <%s>]', strlen($pal) / 3 - 1, bin2hex($pal)),
        };
        $parms = sprintf('/DecodeParms <</Predictor 15 /Colors %d /BitsPerComponent %d /Columns %d>>', $colors, $bpc, $w);
        $mask = '';
        if ($trns !== null && $ct === 0) {
            $v = ord($trns[1] ?? "\0");
            $mask = " /Mask [$v $v]";
        } elseif ($trns !== null && $ct === 2 && strlen($trns) >= 6) {
            $r = ord($trns[1]);
            $g = ord($trns[3]);
            $b = ord($trns[5]);
            $mask = " /Mask [$r $r $g $g $b $b]";
        } elseif ($trns !== null && $ct === 3 && ($i = strpos($trns, "\0")) !== false) {
            $mask = " /Mask [$i $i]";
        }

        $smask = null;
        if ($ct >= 4) {
            $raw = @gzuncompress($idat);
            if ($raw === false) {
                throw new \RuntimeException('PNG corrupto');
            }
            $bytesPerPixel = $ct === 4 ? 2 : 4;
            $rowLen = $w * $bytesPerPixel;
            $color = '';
            $alpha = '';
            $colorRe = $ct === 4 ? '/(.)./s' : '/(.{3})./s';
            $alphaRe = $ct === 4 ? '/.(.)/s' : '/.{3}(.)/s';
            for ($y = 0; $y < $h; $y++) {
                $offset = $y * ($rowLen + 1);
                $filter = $raw[$offset];
                $line = substr($raw, $offset + 1, $rowLen);
                $color .= $filter . preg_replace($colorRe, '$1', $line);
                $alpha .= $filter . preg_replace($alphaRe, '$1', $line);
            }
            $idat = (string) gzcompress($color);
            $smask = (string) gzcompress($alpha);
        }

        return [
            'w' => $w,
            'h' => $h,
            'cs' => $cs,
            'bpc' => $bpc,
            'filter' => 'FlateDecode',
            'parms' => $parms . $mask,
            'data' => $idat,
            'smask' => $smask,
        ];
    }

    // ---------------------------------------------------------------- salida

    public function output(): string
    {
        if (!$this->pages) {
            $this->addPage();
        }
        $objects = [];   // número => cuerpo
        $n = 0;
        $catalog = ++$n;
        $pagesObj = ++$n;
        $info = ++$n;
        $f1 = ++$n;
        $f2 = ++$n;
        $objects[$f1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$f2] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $xobjects = '';
        foreach ($this->images as $img) {
            $smaskRef = '';
            if ($img['smask'] !== null) {
                $sm = ++$n;
                $objects[$sm] = self::stream(sprintf(
                    '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /DecodeParms <</Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns %d>>',
                    $img['w'],
                    $img['h'],
                    $img['w']
                ), $img['smask']);
                $smaskRef = " /SMask $sm 0 R";
            }
            $id = ++$n;
            $objects[$id] = self::stream(sprintf(
                '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent %d /Filter /%s %s%s',
                $img['w'],
                $img['h'],
                $img['cs'],
                $img['bpc'],
                $img['filter'],
                $img['parms'],
                $smaskRef
            ), $img['data']);
            $xobjects .= "/{$img['name']} $id 0 R ";
        }
        $resources = "<< /Font << /F1 $f1 0 R /F2 $f2 0 R >>" . ($xobjects ? " /XObject << $xobjects>>" : '') . ' >>';

        $kids = [];
        foreach ($this->pages as $content) {
            $c = ++$n;
            $objects[$c] = self::stream('<< /Filter /FlateDecode', (string) gzcompress($content));
            $p = ++$n;
            $objects[$p] = sprintf(
                '<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %s %s] /Resources %s /Contents %d 0 R >>',
                $pagesObj,
                self::n($this->w),
                self::n($this->h),
                $resources,
                $c
            );
            $kids[] = "$p 0 R";
        }
        $objects[$pagesObj] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        $objects[$catalog] = "<< /Type /Catalog /Pages $pagesObj 0 R >>";
        $objects[$info] = sprintf(
            '<< /Title %s /Producer (Quasar) /CreationDate (D:%s) >>',
            self::unicodeString($this->title),
            date('YmdHis')
        );

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= "$num 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", $n + 1);
        for ($i = 1; $i <= $n; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= sprintf("trailer\n<< /Size %d /Root %d 0 R /Info %d 0 R >>\nstartxref\n%d\n%%%%EOF\n", $n + 1, $catalog, $info, $xref);
        return $pdf;
    }

    private static function stream(string $dictStart, string $data): string
    {
        return $dictStart . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    }

    private static function unicodeString(string $utf8): string
    {
        return '<FEFF' . strtoupper(bin2hex((string) mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'))) . '>';
    }

    private static function rgb(array $c): string
    {
        return self::n($c[0]) . ' ' . self::n($c[1]) . ' ' . self::n($c[2]);
    }

    private static function n(float|int $v): string
    {
        $s = rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /** "#2563eb" -> [r, g, b] en 0..1 */
    public static function hex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $v = ctype_xdigit($hex) && strlen($hex) >= 6 ? hexdec(substr($hex, 0, 6)) : 0;
        return [(($v >> 16) & 255) / 255, (($v >> 8) & 255) / 255, ($v & 255) / 255];
    }
}
