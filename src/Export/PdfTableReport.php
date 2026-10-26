<?php
declare(strict_types=1);

namespace Quasar\Export;

/**
 * Informe PDF de una tabla: cabecera con logo, título y filtros; tabla con
 * cabecera repetida en cada página, filas alternas y pie con numeración.
 */
final class PdfTableReport
{
    private const MARGIN = 28.0;
    private const FONT = 7.5;
    private const ROW_H = 14.0;
    private const PAD = 4.0;
    private const MIN_COL = 28.0;
    private const MAX_COL = 240.0;
    private const SAMPLE_ROWS = 400;

    private const TEXT = [0.12, 0.16, 0.2];
    private const MUTED = [0.42, 0.45, 0.5];
    private const GRID = [0.85, 0.87, 0.89];
    private const ZEBRA = [0.965, 0.97, 0.98];

    /** @var array{0:float,1:float,2:float} */
    private array $accent;
    private array $headFill;

    /**
     * @param array{
     *   title:string, subtitle:list<string>, company:string, footer:string, empty:string,
     *   logo:?string, color:?string, orientation:string
     * } $opts  footer admite {page} y {pages}
     * @param list<array{label:string,align:string}> $columns
     * @param list<list<string>> $rows textos ya formateados
     */
    public function __construct(
        private readonly array $opts,
        private readonly array $columns,
        private array $rows,
    ) {
        // Una fila por registro: los saltos de línea (textos largos) se convierten en espacios
        foreach ($this->rows as &$row) {
            $row = array_map(static fn ($v) => is_string($v) ? (preg_replace('/\s+/u', ' ', $v) ?? $v) : $v, $row);
        }
        unset($row);
        $this->accent = PdfDocument::hex($opts['color'] ?: '#2563eb');
        // Cabecera de tabla: el color de marca muy aclarado
        $this->headFill = array_map(static fn ($c) => 1 - (1 - $c) * 0.14, $this->accent);
    }

    public function render(): string
    {
        $natural = $this->naturalWidths();
        $orientation = $this->opts['orientation'];
        if ($orientation === 'auto') {
            $portraitWidth = 595.28 - 2 * self::MARGIN;
            $orientation = array_sum($natural) > $portraitWidth * 1.05 || count($this->columns) > 6 ? 'landscape' : 'portrait';
        }

        $pdf = new PdfDocument($orientation);
        $pdf->setTitle($this->opts['title']);
        $usable = $pdf->width() - 2 * self::MARGIN;
        $widths = $this->fitWidths($natural, $usable);
        $bottom = $pdf->height() - self::MARGIN - 18;

        $y = $this->newPage($pdf, $widths);
        if (!$this->rows) {
            $pdf->textIn(self::MARGIN, $y + 18, $usable, PdfDocument::encode($this->opts['empty']), 9, false, 'center', self::MUTED);
        }
        foreach ($this->rows as $i => $row) {
            if ($y + self::ROW_H > $bottom) {
                $y = $this->newPage($pdf, $widths);
            }
            if ($i % 2 === 1) {
                $pdf->rect(self::MARGIN, $y, $usable, self::ROW_H, self::ZEBRA);
            }
            $x = self::MARGIN;
            foreach ($this->columns as $c => $col) {
                $text = $pdf->fitText((string) ($row[$c] ?? ''), $widths[$c] - 2 * self::PAD, self::FONT);
                $pdf->textIn($x + self::PAD, $y + 9.6, $widths[$c] - 2 * self::PAD, $text, self::FONT, false, $col['align'], self::TEXT);
                $x += $widths[$c];
            }
            $y += self::ROW_H;
            $pdf->line(self::MARGIN, $y, self::MARGIN + $usable, $y, self::GRID, 0.4);
        }

        $this->footers($pdf);
        return $pdf->output();
    }

    /** Cabecera del documento + cabecera de la tabla. Devuelve la "y" donde empiezan las filas. */
    private function newPage(PdfDocument $pdf, array $widths): float
    {
        $pdf->addPage();
        $m = self::MARGIN;
        $usable = $pdf->width() - 2 * $m;
        $x = $m;

        $logo = $this->opts['logo'] ?? null;
        if ($logo && $pdf->imageInfo($logo) !== null) {
            $x += $pdf->image($logo, $m, $m, 150, 38) + 14;
        }
        $company = PdfDocument::encode($this->opts['company']);
        $companyW = PdfFontMetrics::width($company, 8);
        $pdf->text($pdf->width() - $m - $companyW, $m + 8, $company, 8, false, self::MUTED, true);

        $titleW = $pdf->width() - $m - $x - $companyW - 12;
        $pdf->text($x, $m + 14, $pdf->fitText($this->opts['title'], $titleW, 15, true), 15, true, self::TEXT, true);
        $ty = $m + 27;
        foreach (array_slice($this->opts['subtitle'], 0, 3) as $line) {
            $pdf->text($x, $ty, $pdf->fitText($line, $pdf->width() - $m - $x, 7.5), 7.5, false, self::MUTED, true);
            $ty += 9.5;
        }

        $y = max($m + 44, $ty + 2);
        $pdf->rect($m, $y, $usable, 1.6, $this->accent);
        $y += 8;

        // Cabecera de la tabla
        $pdf->rect($m, $y, $usable, self::ROW_H + 2, $this->headFill);
        $cx = $m;
        foreach ($this->columns as $c => $col) {
            $label = $pdf->fitText($col['label'], $widths[$c] - 2 * self::PAD, self::FONT, true);
            $pdf->textIn($cx + self::PAD, $y + 10.6, $widths[$c] - 2 * self::PAD, $label, self::FONT, true, $col['align'], self::TEXT);
            $cx += $widths[$c];
        }
        $y += self::ROW_H + 2;
        $pdf->line($m, $y, $m + $usable, $y, $this->accent, 0.8);
        return $y;
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

    /** Ancho "ideal" de cada columna según cabecera y una muestra de filas. */
    private function naturalWidths(): array
    {
        $widths = [];
        foreach ($this->columns as $c => $col) {
            $w = PdfFontMetrics::width(PdfDocument::encode($col['label']), self::FONT, true);
            foreach (array_slice($this->rows, 0, self::SAMPLE_ROWS) as $row) {
                $w = max($w, PdfFontMetrics::width(PdfDocument::encode((string) ($row[$c] ?? '')), self::FONT));
                if ($w >= self::MAX_COL) {
                    break;
                }
            }
            $widths[$c] = min(self::MAX_COL, max(self::MIN_COL, $w + 2 * self::PAD + 1));
        }
        return $widths;
    }

    /** Ajusta los anchos al espacio disponible: encoge primero las columnas anchas. */
    private function fitWidths(array $widths, float $usable): array
    {
        $sum = array_sum($widths);
        if ($sum <= $usable) {
            // Sobra espacio: se reparte proporcionalmente
            return array_map(static fn ($w) => $w * $usable / $sum, $widths);
        }
        for ($pass = 0; $pass < 6 && array_sum($widths) > $usable + 0.1; $pass++) {
            $fair = $usable / count($widths);
            $small = array_sum(array_filter($widths, static fn ($w) => $w <= $fair));
            $large = array_sum(array_filter($widths, static fn ($w) => $w > $fair));
            if ($large <= 0) {
                break;
            }
            $factor = max(0.1, ($usable - $small) / $large);
            $widths = array_map(static fn ($w) => $w > $fair ? max($fair, $w * $factor) : $w, $widths);
        }
        $sum = array_sum($widths);
        return $sum > $usable ? array_map(static fn ($w) => $w * $usable / $sum, $widths) : $widths;
    }
}
