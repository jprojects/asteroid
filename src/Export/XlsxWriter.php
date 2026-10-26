<?php
declare(strict_types=1);

namespace Quasar\Export;

use DateTimeInterface;

/**
 * Genera un .xlsx (Office Open XML) de una sola hoja sin dependencias externas
 * (solo la extensión zip). Las celdas de texto son "inlineStr", por lo que nunca
 * se interpretan como fórmulas (sin riesgo de inyección CSV/fórmulas).
 */
final class XlsxWriter
{
    private const MAX_WIDTH = 60;

    /** @var list<string> formatos numéricos personalizados (id 164+) */
    private array $numFmts = [];
    /** @var array<string,int> clave de estilo => índice en cellXfs */
    private array $xfIndex = [];
    /** @var list<string> */
    private array $xfs = [];

    public function __construct()
    {
        // 0 normal · 1 título · 2 subtítulo · 3 cabecera de tabla
        $this->xfs = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            '<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>',
        ];
    }

    /**
     * @param list<string> $headerLines  líneas superiores (la primera es el título)
     * @param list<array{label:string,format:?string,align:string}> $columns
     * @param list<list<mixed>> $rows     valores: string|int|float|DateTimeInterface|null
     * @return string ruta de un fichero temporal con el .xlsx (el llamador lo borra)
     */
    public function write(string $sheetName, array $headerLines, array $columns, array $rows): string
    {
        $colStyles = [];
        foreach ($columns as $i => $c) {
            $colStyles[$i] = $this->styleFor($c['format'] ?? null, $c['align'] ?? 'left');
        }

        $sheetRows = [];
        $r = 1;
        foreach ($headerLines as $i => $line) {
            $sheetRows[] = sprintf('<row r="%d">%s</row>', $r, $this->cell(0, $r, $line, $i === 0 ? 1 : 2));
            $r++;
        }
        $r++; // fila en blanco
        $headerRow = $r;
        $cells = '';
        foreach ($columns as $i => $c) {
            $cells .= $this->cell($i, $r, $c['label'], 3);
        }
        $sheetRows[] = sprintf('<row r="%d">%s</row>', $r, $cells);

        $widths = array_map(static fn ($c) => mb_strlen($c['label']) + 2, $columns);
        foreach ($rows as $row) {
            $r++;
            $cells = '';
            foreach ($columns as $i => $c) {
                $value = $row[$i] ?? null;
                $cells .= $this->cell($i, $r, $value, $colStyles[$i]);
                $len = match (true) {
                    $value instanceof DateTimeInterface => 16,
                    is_float($value) || is_int($value) => strlen(number_format((float) $value, 2)) + 1,
                    default => mb_strlen((string) $value),
                };
                $widths[$i] = min(self::MAX_WIDTH, max($widths[$i], $len + 2));
            }
            $sheetRows[] = sprintf('<row r="%d">%s</row>', $r, $cells);
        }

        $lastCol = self::colName(max(0, count($columns) - 1));
        $cols = '';
        foreach ($widths as $i => $w) {
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $i + 1, $w);
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . sprintf('<sheetViews><sheetView workbookViewId="0"><pane ySplit="%d" topLeftCell="A%d" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>', $headerRow, $headerRow + 1)
            . '<cols>' . $cols . '</cols>'
            . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
            . sprintf('<autoFilter ref="A%d:%s%d"/>', $headerRow, $lastCol, max($headerRow, $r))
            . '<pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
            . '</worksheet>';

        $file = tempnam(sys_get_temp_dir(), 'qsx');
        $zip = new \ZipArchive();
        if ($file === false || $zip->open($file, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se puede crear el fichero XLSX temporal');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('docProps/core.xml', $this->coreProps($headerLines[0] ?? $sheetName));
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Quasar</Application></Properties>');
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName, $headerRow, $lastCol, $r));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();
        return $file;
    }

    // ---------------------------------------------------------------- celdas

    private function cell(int $col, int $row, mixed $value, int $style): string
    {
        $ref = self::colName($col) . $row;
        if ($value === null || $value === '') {
            return $style > 0 ? sprintf('<c r="%s" s="%d"/>', $ref, $style) : '';
        }
        if ($value instanceof DateTimeInterface) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, $style, self::excelDate($value));
        }
        if (is_int($value) || is_float($value)) {
            return sprintf('<c r="%s" s="%d"><v>%s</v></c>', $ref, $style, is_float($value) ? self::floatString($value) : $value);
        }
        return sprintf(
            '<c r="%s" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>',
            $ref,
            $style,
            self::xml((string) $value)
        );
    }

    private function styleFor(?string $format, string $align): int
    {
        $key = ($format ?? '') . '|' . $align;
        if (isset($this->xfIndex[$key])) {
            return $this->xfIndex[$key];
        }
        $numFmtId = 0;
        if ($format !== null && $format !== '') {
            $pos = array_search($format, $this->numFmts, true);
            if ($pos === false) {
                $this->numFmts[] = $format;
                $pos = count($this->numFmts) - 1;
            }
            $numFmtId = 164 + $pos;
        }
        $alignXml = $align !== 'left' ? sprintf('<alignment horizontal="%s"/>', $align === 'right' ? 'right' : 'center') : '';
        $this->xfs[] = sprintf(
            '<xf numFmtId="%d" fontId="0" fillId="0" borderId="0" xfId="0"%s%s>%s</xf>',
            $numFmtId,
            $numFmtId ? ' applyNumberFormat="1"' : '',
            $alignXml ? ' applyAlignment="1"' : '',
            $alignXml
        );
        return $this->xfIndex[$key] = count($this->xfs) - 1;
    }

    public static function colName(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }
        return $name;
    }

    /** Número de serie de Excel (días desde 1899-12-30, con fracción de día). */
    public static function excelDate(DateTimeInterface $d): string
    {
        $days = (int) (new \DateTimeImmutable('1899-12-30'))->diff(new \DateTimeImmutable($d->format('Y-m-d')))->format('%r%a');
        $seconds = (int) $d->format('H') * 3600 + (int) $d->format('i') * 60 + (int) $d->format('s');
        return $seconds ? rtrim(rtrim(sprintf('%.10F', $days + $seconds / 86400), '0'), '.') : (string) $days;
    }

    private static function floatString(float $v): string
    {
        $s = rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }

    /** Escapa XML y elimina caracteres de control no válidos en XML 1.0. */
    private static function xml(string $s): string
    {
        $s = (string) preg_replace('/[^\x09\x0A\x0D\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ---------------------------------------------------------------- partes del paquete

    private function styles(): string
    {
        $numFmts = '';
        foreach ($this->numFmts as $i => $f) {
            $numFmts .= sprintf('<numFmt numFmtId="%d" formatCode="%s"/>', 164 + $i, self::xml($f));
        }
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($numFmts ? sprintf('<numFmts count="%d">%s</numFmts>', count($this->numFmts), $numFmts) : '')
            . '<fonts count="4">'
            . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><sz val="9"/><color rgb="FF6B7480"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EFFF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF9AA5B1"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . sprintf('<cellXfs count="%d">%s</cellXfs>', count($this->xfs), implode('', $this->xfs))
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function workbook(string $sheetName, int $headerRow, string $lastCol, int $lastRow): string
    {
        $name = mb_substr((string) preg_replace('~[\\\\/?*\[\]:]~', ' ', $sheetName), 0, 31) ?: 'Datos';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($name) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
            . sprintf("'%s'!\$A\$%d:\$%s\$%d", self::xml(str_replace("'", "''", $name)), $headerRow, $lastCol, max($headerRow, $lastRow))
            . '</definedName></definedNames>'
            . '</workbook>';
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function coreProps(string $title): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::xml($title) . '</dc:title><dc:creator>Quasar</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }
}
