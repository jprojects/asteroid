<?php
declare(strict_types=1);

namespace Quasar\Builder;

/**
 * Lee la primera hoja de un .xlsx o un .csv sin librerías: cabeceras (la primera fila con la mayoría de
 * columnas rellenas, para saltar títulos) y filas como texto.
 * Las fechas de Excel salen como "AAAA-MM-DD" (o "AAAA-MM-DD HH:MM:SS"); los números, como en la celda.
 * CSV: separador ; , tabulador o | (el que más aparece en la primera línea) y UTF-8 o Windows-1252.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 50000;
    public const MAX_COLUMNS = 60;

    /** @return array{headers:list<string>,rows:list<list<string>>,truncated:bool} */
    public static function read(string $file, string $extension): array
    {
        $rows = match (strtolower($extension)) {
            'xlsx' => self::xlsx($file),
            'csv', 'txt' => self::csv($file),
            default => throw new \InvalidArgumentException('format'),
        };
        // Quita filas vacías y recorta columnas vacías al final
        $rows = array_values(array_filter($rows, static fn ($r) => implode('', $r) !== ''));
        if ($rows === []) {
            throw new \InvalidArgumentException('empty');
        }
        $width = 0;
        foreach ($rows as $r) {
            for ($i = count($r) - 1; $i >= 0; $i--) {
                if (trim($r[$i]) !== '') {
                    $width = max($width, $i + 1);
                    break;
                }
            }
        }
        $width = min($width, self::MAX_COLUMNS);
        $norm = static fn (array $r) => array_map(static fn ($v) => trim((string) $v), array_slice(array_pad($r, $width, ''), 0, $width));
        // Cabecera: la primera fila con la mayoría de columnas rellenas (se saltan títulos como los de las exportaciones)
        $filled = static fn (array $r) => count(array_filter(array_slice($r, 0, $width), static fn ($v) => trim((string) $v) !== ''));
        while (count($rows) > 1 && $filled($rows[0]) < max(1, (int) ceil($width * 0.6))) {
            array_shift($rows);
        }
        $headers = $norm(array_shift($rows));
        foreach ($headers as $i => $h) {
            $headers[$i] = $h !== '' ? $h : 'Columna ' . ($i + 1);
        }
        $truncated = count($rows) > self::MAX_ROWS;
        return ['headers' => $headers, 'rows' => array_map($norm, array_slice($rows, 0, self::MAX_ROWS)), 'truncated' => $truncated];
    }

    /** @return list<list<string>> */
    private static function csv(string $file): array
    {
        $text = (string) file_get_contents($file);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($text, "\n") ?: '';
        $counts = [];
        foreach ([';', ',', "\t", '|'] as $d) {
            $counts[$d] = substr_count($first, $d);
        }
        arsort($counts);
        $delimiter = (string) array_key_first($counts);
        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $text);
        rewind($fp);
        $rows = [];
        while (($r = fgetcsv($fp, null, $delimiter, '"', '')) !== false) {
            if ($r !== [null]) {
                $rows[] = array_map(static fn ($v) => (string) $v, $r);
            }
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($fp);
        return $rows;
    }

    /** @return list<list<string>> */
    private static function xlsx(string $file): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            throw new \InvalidArgumentException('format');
        }
        try {
            $shared = self::sharedStrings((string) $zip->getFromName('xl/sharedStrings.xml'));
            $dateStyles = self::dateStyles((string) $zip->getFromName('xl/styles.xml'));
            $sheet = $zip->getFromName(self::firstSheetPath($zip));
            if ($sheet === false) {
                throw new \InvalidArgumentException('format');
            }
        } finally {
            $zip->close();
        }

        $xml = new \XMLReader();
        $xml->XML($sheet, null, LIBXML_NONET | LIBXML_COMPACT);
        $rows = [];
        $row = null;
        while ($xml->read()) {
            if ($xml->nodeType === \XMLReader::ELEMENT && $xml->localName === 'row') {
                $row = [];
            } elseif ($xml->nodeType === \XMLReader::END_ELEMENT && $xml->localName === 'row') {
                $rows[] = $row ?? [];
                $row = null;
                if (count($rows) > self::MAX_ROWS + 1) {
                    break;
                }
            } elseif ($xml->nodeType === \XMLReader::ELEMENT && $xml->localName === 'c' && $row !== null) {
                $ref = (string) $xml->getAttribute('r');
                $type = (string) $xml->getAttribute('t');
                $style = (int) $xml->getAttribute('s');
                $cell = new \SimpleXMLElement($xml->readOuterXml());
                $value = match ($type) {
                    's' => $shared[(int) $cell->v] ?? '',
                    'inlineStr' => self::richText($cell->is),
                    'b' => (string) $cell->v === '1' ? '1' : '0',
                    default => (string) $cell->v,
                };
                if ($type === '' && $value !== '' && is_numeric($value) && isset($dateStyles[$style])) {
                    $value = self::excelDate((float) $value);
                }
                $col = preg_match('/^([A-Z]+)/', $ref, $m) ? self::colIndex($m[1]) : count($row);
                $row = array_pad($row, $col, '');
                $row[$col] = $value;
            }
        }
        $xml->close();
        return $rows;
    }

    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $workbook = @simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $rels = @simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        if ($workbook !== false && $rels !== false && isset($workbook->sheets->sheet[0])) {
            $rid = (string) $workbook->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private static function sharedStrings(string $xml): array
    {
        if ($xml === '' || ($doc = @simplexml_load_string($xml)) === false) {
            return [];
        }
        $out = [];
        foreach ($doc->si as $si) {
            $out[] = self::richText($si);
        }
        return $out;
    }

    private static function richText(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }
        return $text;
    }

    /** @return array<int,true> índices de estilo (cellXfs) con formato de fecha */
    private static function dateStyles(string $xml): array
    {
        if ($xml === '' || ($doc = @simplexml_load_string($xml)) === false) {
            return [];
        }
        $custom = [];
        foreach ($doc->numFmts->numFmt ?? [] as $f) {
            // Formato con día, mes o año (sin contar lo que va entre comillas o corchetes)
            $code = preg_replace('/"[^"]*"|\[[^\]]*\]/', '', (string) $f['formatCode']) ?? '';
            if (preg_match('/[dmy]/i', $code) && !preg_match('/^[#0.,\s%]+$/', $code)) {
                $custom[(int) $f['numFmtId']] = true;
            }
        }
        $out = [];
        $i = 0;
        foreach ($doc->cellXfs->xf ?? [] as $xf) {
            $id = (int) $xf['numFmtId'];
            if (($id >= 14 && $id <= 22) || ($id >= 45 && $id <= 47) || isset($custom[$id])) {
                $out[$i] = true;
            }
            $i++;
        }
        return $out;
    }

    private static function excelDate(float $serial): string
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        $d = (new \DateTimeImmutable('1899-12-30'))->modify("+$days days")->modify("+$seconds seconds");
        return $seconds === 0 ? $d->format('Y-m-d') : $d->format('Y-m-d H:i:s');
    }

    private static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }
        return $n - 1;
    }
}
