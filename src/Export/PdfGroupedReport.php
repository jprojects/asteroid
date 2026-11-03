<?php
declare(strict_types=1);

namespace Quasar\Export;

/**
 * PDF de un informe (módulos "type": "report"): cabecera con logo, título y subtítulo en cada página;
 * en la primera, un bloque de datos «etiqueta: valor»; tabla con cabecera repetida, grupos con su
 * subtotal, total general y, al final, un resumen «etiqueta: valor». Pie con «Página X de Y».
 *
 * Recibe los textos ya formateados; qué filas, grupos y totales hay lo decide quien lo llama.
 */
final class PdfGroupedReport
{
    private const MARGIN = 28.0;
    private const PAD = 4.0;
    private const PAD_V = 3.0;

    private const TEXT = [0.12, 0.16, 0.2];
    private const MUTED = [0.42, 0.45, 0.5];
    private const GRID = [0.85, 0.87, 0.89];
    private const GROUP_FILL = [0.95, 0.96, 0.97];

    /** @var array{0:float,1:float,2:float} */
    private array $accent;
    private array $headFill;
    private float $size;
    private float $lineH;
    /** @var list<float> ancho de cada columna en puntos */
    private array $widths = [];

    /**
     * @param array{
     *   title:string, subtitle:?string, company:string, footer:string, empty:string, logo:?string, color:?string,
     *   orientation:string, fontSize:float, info:list<array{0:string,1:string}>, summary:list<array{0:string,1:string}>
     * } $opts  footer admite {page} y {pages}
     * @param list<array{label:string,align:string,width:float,wrap:bool}> $columns ancho relativo
     * @param list<array{type:string,label?:string,cells?:list<string>}> $items en orden:
     *   row (cells) · group (label) · subtotal (label, cells) · total (label, cells) · break (salto de página)
     */
    public function __construct(
        private readonly array $opts,
        private readonly array $columns,
        private readonly array $items,
    ) {
        $this->accent = PdfDocument::hex($opts['color'] ?: '#2563eb');
        $this->headFill = array_map(static fn ($c) => 1 - (1 - $c) * 0.14, $this->accent);
        $this->size = $opts['fontSize'];
        $this->lineH = round($this->size * 1.3, 2);
    }

    public function render(): string
    {
        $pdf = new PdfDocument($this->opts['orientation']);
        $pdf->setTitle($this->opts['title']);
        $usable = $pdf->width() - 2 * self::MARGIN;
        $total = array_sum(array_column($this->columns, 'width'));
        $this->widths = array_map(static fn ($c) => $c['width'] * $usable / $total, $this->columns);
        $bottom = $pdf->height() - self::MARGIN - 18;

        $y = $this->pageHeader($pdf);
        $y = $this->pairs($pdf, $y, $this->opts['info']);
        $y = $this->tableHeader($pdf, $y);
        $hasRows = false;
        $zebra = 0;

        foreach ($this->items as $n => $item) {
            if ($item['type'] === 'break') {
                if ($hasRows) {
                    $y = $this->tableHeader($pdf, $this->pageHeader($pdf));
                }
                continue;
            }
            $bold = $item['type'] !== 'row';
            $lines = $item['type'] === 'group' ? [] : $this->cellLines($pdf, $item['cells'], $bold);
            $h = $item['type'] === 'group' ? $this->lineH + 2 * self::PAD_V + 2 : $this->rowHeight($lines);
            // Un grupo no se queda solo al pie de la página: va con su primera fila
            $need = $h + ($item['type'] === 'group' ? $this->rowHeight($this->cellLines($pdf, $this->items[$n + 1]['cells'] ?? [], false)) : 0);
            if ($y + $need > $bottom && $hasRows) {
                $y = $this->tableHeader($pdf, $this->pageHeader($pdf));
            }
            $hasRows = true;
            match ($item['type']) {
                'group' => $this->groupRow($pdf, $y, $h, $item['label'] ?? ''),
                'row' => $this->dataRow($pdf, $y, $h, $lines, $zebra++ % 2 === 1),
                default => $this->totalRow($pdf, $y, $h, $item, $lines),
            };
            if ($item['type'] === 'group') {
                $zebra = 0;
            }
            $y += $h;
        }
        if (!$hasRows) {
            $pdf->textIn(self::MARGIN, $y + 18, $usable, PdfDocument::encode($this->opts['empty']), 9, false, 'center', self::MUTED);
            $y += 30;
        }

        if ($this->opts['summary']) {
            $h = ($this->size + 6) * count($this->opts['summary']) + 14;
            if ($y + $h > $bottom) {
                $y = $this->pageHeader($pdf);
            }
            $this->pairs($pdf, $y + 10, $this->opts['summary'], 1);
        }

        $this->footers($pdf);
        return $pdf->output();
    }

    /** Logo, empresa, título y subtítulo. Devuelve la "y" donde sigue la página. */
    private function pageHeader(PdfDocument $pdf): float
    {
        $pdf->addPage();
        $m = self::MARGIN;
        $x = $m;
        $logo = $this->opts['logo'] ?? null;
        if ($logo && $pdf->imageInfo($logo) !== null) {
            $x += $pdf->image($logo, $m, $m, 150, 38) + 14;
        }
        $company = PdfDocument::encode($this->opts['company']);
        $companyW = PdfFontMetrics::width($company, 8);
        $pdf->text($pdf->width() - $m - $companyW, $m + 8, $company, 8, false, self::MUTED, true);
        $pdf->text($x, $m + 14, $pdf->fitText($this->opts['title'], $pdf->width() - $m - $x - $companyW - 12, 15, true), 15, true, self::TEXT, true);
        $ty = $m + 27;
        foreach ($this->opts['subtitle'] !== null && $this->opts['subtitle'] !== '' ? explode("\n", $this->opts['subtitle']) : [] as $line) {
            $pdf->text($x, $ty, $pdf->fitText($line, $pdf->width() - $m - $x, 8), 8, false, self::MUTED, true);
            $ty += 10;
        }
        $y = max($m + 44, $ty + 2);
        $pdf->rect($m, $y, $pdf->width() - 2 * $m, 1.6, $this->accent);
        return $y + 10;
    }

    /** Bloque «etiqueta: valor» en una o dos columnas (datos de la cabecera y resumen final). */
    private function pairs(PdfDocument $pdf, float $y, array $pairs, int $cols = 2): float
    {
        if (!$pairs) {
            return $y;
        }
        $m = self::MARGIN;
        $colW = ($pdf->width() - 2 * $m) / 2;
        $rowH = $this->size + 6;
        foreach (range(0, $cols - 1) as $col) {
            $labelW = 0.0;
            foreach ($pairs as $i => [$label]) {
                if ($i % $cols === $col && $label !== '') {
                    $labelW = max($labelW, PdfFontMetrics::width(PdfDocument::encode($label), $this->size, true));
                }
            }
            $labelW = min($labelW, $colW * 0.45);
            foreach ($pairs as $i => [$label, $value]) {
                if ($i % $cols !== $col) {
                    continue;
                }
                $x = $m + $col * $colW;
                $ly = $y + intdiv($i, $cols) * $rowH + $this->size;
                if ($label !== '') {
                    $pdf->text($x, $ly, $pdf->fitText($label, $labelW, $this->size, true), $this->size, true, self::MUTED, true);
                }
                $vx = $x + ($labelW > 0 ? $labelW + 8 : 0);
                $pdf->text($vx, $ly, $pdf->fitText($value, $m + ($col + 1) * $colW - $vx - 12, $this->size), $this->size, false, self::TEXT, true);
            }
        }
        return $y + (int) ceil(count($pairs) / $cols) * $rowH + 8;
    }

    private function tableHeader(PdfDocument $pdf, float $y): float
    {
        $m = self::MARGIN;
        $usable = $pdf->width() - 2 * $m;
        $h = $this->lineH + 2 * self::PAD_V + 2;
        $pdf->rect($m, $y, $usable, $h, $this->headFill);
        $x = $m;
        foreach ($this->columns as $c => $col) {
            $w = $this->widths[$c] - 2 * self::PAD;
            $pdf->textIn($x + self::PAD, $y + self::PAD_V + 1 + $this->size, $w, $pdf->fitText($col['label'], $w, $this->size, true), $this->size, true, $col['align'], self::TEXT);
            $x += $this->widths[$c];
        }
        $y += $h;
        $pdf->line($m, $y, $m + $usable, $y, $this->accent, 0.8);
        return $y;
    }

    /** @return list<list<string>> líneas (WinAnsi) de cada celda */
    private function cellLines(PdfDocument $pdf, array $cells, bool $bold): array
    {
        $out = [];
        foreach ($this->columns as $c => $col) {
            $text = (string) ($cells[$c] ?? '');
            $w = $this->widths[$c] - 2 * self::PAD;
            $out[] = $col['wrap'] ? $this->wrap($text, $w, $bold) : [$pdf->fitText(preg_replace('/\s+/u', ' ', $text) ?? $text, $w, $this->size, $bold)];
        }
        return $out;
    }

    private function rowHeight(array $lines): float
    {
        $n = max(1, ...array_map('count', $lines ?: [[]]));
        return $n * $this->lineH + 2 * self::PAD_V;
    }

    private function dataRow(PdfDocument $pdf, float $y, float $h, array $lines, bool $zebra): void
    {
        $m = self::MARGIN;
        $usable = $pdf->width() - 2 * $m;
        if ($zebra) {
            $pdf->rect($m, $y, $usable, $h, [0.975, 0.98, 0.985]);
        }
        $this->cells($pdf, $y, $lines, false, 0);
        $pdf->line($m, $y + $h, $m + $usable, $y + $h, self::GRID, 0.4);
    }

    private function groupRow(PdfDocument $pdf, float $y, float $h, string $label): void
    {
        $m = self::MARGIN;
        $usable = $pdf->width() - 2 * $m;
        $pdf->rect($m, $y + 2, $usable, $h - 2, self::GROUP_FILL);
        $pdf->rect($m, $y + 2, 2, $h - 2, $this->accent);
        $pdf->text($m + self::PAD + 2, $y + 2 + self::PAD_V + $this->size, $pdf->fitText($label, $usable - 2 * self::PAD, $this->size, true), $this->size, true, self::TEXT, true);
    }

    /** Subtotal o total: la etiqueta ocupa las columnas anteriores a la primera con valor. */
    private function totalRow(PdfDocument $pdf, float $y, float $h, array $item, array $lines): void
    {
        $m = self::MARGIN;
        $usable = $pdf->width() - 2 * $m;
        $isTotal = $item['type'] === 'total';
        $pdf->rect($m, $y, $usable, $h, $isTotal ? $this->headFill : self::GROUP_FILL);
        $pdf->line($m, $y, $m + $usable, $y, $isTotal ? $this->accent : self::GRID, $isTotal ? 0.8 : 0.5);
        $first = 0;
        while ($first < count($this->columns) && ($item['cells'][$first] ?? '') === '') {
            $first++;
        }
        $labelW = array_sum(array_slice($this->widths, 0, $first)) - 2 * self::PAD;
        if ($labelW > 0) {
            $pdf->text($m + self::PAD, $y + self::PAD_V + $this->size, $pdf->fitText($item['label'] ?? '', $labelW, $this->size, true), $this->size, true, self::TEXT, true);
        }
        $this->cells($pdf, $y, $lines, true, $first);
    }

    private function cells(PdfDocument $pdf, float $y, array $lines, bool $bold, int $from): void
    {
        $x = self::MARGIN + array_sum(array_slice($this->widths, 0, $from));
        foreach ($this->columns as $c => $col) {
            if ($c < $from) {
                continue;
            }
            $w = $this->widths[$c] - 2 * self::PAD;
            foreach ($lines[$c] as $i => $line) {
                $pdf->textIn($x + self::PAD, $y + self::PAD_V + $this->size + $i * $this->lineH, $w, $line, $this->size, $bold, $col['align'], self::TEXT);
            }
            $x += $this->widths[$c];
        }
    }

    /**
     * Parte el texto en líneas que caben en $width (por palabras; una palabra más larga que la línea se corta).
     * @return list<string> líneas en WinAnsi
     */
    private function wrap(string $utf8, float $width, bool $bold): array
    {
        $widths = PdfFontMetrics::widths($bold);
        $measure = fn (string $s): float => array_sum(array_map(static fn ($ch) => $widths[ord($ch)], str_split($s))) * $this->size / 1000;
        $out = [];
        foreach (preg_split('/\R/u', $utf8) ?: [''] as $paragraph) {
            $line = '';
            foreach (explode(' ', PdfDocument::encode(trim($paragraph))) as $word) {
                $candidate = $line === '' ? $word : "$line $word";
                if ($measure($candidate) <= $width) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                }
                while ($word !== '' && $measure($word) > $width) {
                    $cut = 1;
                    while ($cut < strlen($word) && $measure(substr($word, 0, $cut + 1)) <= $width) {
                        $cut++;
                    }
                    $out[] = substr($word, 0, $cut);
                    $word = substr($word, $cut);
                }
                $line = $word;
            }
            $out[] = $line;
        }
        return $out ?: [''];
    }

    private function footers(PdfDocument $pdf): void
    {
        $total = $pdf->pageCount();
        $m = self::MARGIN;
        $y = $pdf->height() - $m;
        for ($i = 0; $i < $total; $i++) {
            $pdf->setPage($i);
            $pdf->line($m, $y - 9, $pdf->width() - $m, $y - 9, self::GRID, 0.4);
            $pdf->text($m, $y, PdfDocument::encode($this->opts['company']), 7, false, self::MUTED, true);
            $label = PdfDocument::encode(strtr($this->opts['footer'], ['{page}' => $i + 1, '{pages}' => $total]));
            $pdf->text($pdf->width() - $m - PdfFontMetrics::width($label, 7), $y, $label, 7, false, self::MUTED, true);
        }
    }
}
