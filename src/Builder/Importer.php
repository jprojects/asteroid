<?php
declare(strict_types=1);

namespace Quasar\Builder;

use Quasar\Core\Database;

/**
 * Importa las filas de la hoja en la tabla recién creada: cada campo con "from" toma la columna con esa
 * cabecera y convierte el valor según su tipo. Un valor que no se reconoce (fecha mal escrita, opción que
 * no existe…) se deja vacío y se cuenta; no detiene la importación.
 */
final class Importer
{
    private const BATCH = 500;

    /** @param bool $excel filas de un .xlsx (números con punto decimal) */
    public function __construct(private readonly Database $db, private readonly bool $excel = false)
    {
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     * @return array{rows:int,unrecognized:array<string,int>,truncated:int} filas insertadas, valores no reconocidos por campo y textos recortados
     */
    public function import(array $spec, array $headers, array $rows): array
    {
        $map = [];
        foreach ($spec['fields'] as $f) {
            if ($f['from'] !== null && ($i = array_search($f['from'], $headers, true)) !== false) {
                $map[] = [$f, (int) $i, $this->lookup($f)];
            }
        }
        $result = ['rows' => 0, 'unrecognized' => [], 'truncated' => 0];
        if ($map === [] || $rows === []) {
            return $result;
        }
        $columns = implode(', ', array_map(static fn ($m) => Database::ident($m[0]['name']), $map));
        $placeholders = '(' . implode(', ', array_fill(0, count($map), '?')) . ')';
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach (array_chunk($rows, self::BATCH) as $chunk) {
                $values = [];
                foreach ($chunk as $row) {
                    foreach ($map as [$f, $i, $lookup]) {
                        $raw = trim((string) ($row[$i] ?? ''));
                        $v = $raw === '' ? null : $this->convert($f, $raw, $lookup, $result);
                        if ($raw !== '' && $v === null) {
                            $result['unrecognized'][$f['name']] = ($result['unrecognized'][$f['name']] ?? 0) + 1;
                        }
                        $values[] = $f['type'] === 'checkbox' ? (int) ($v ?? 0) : $v;
                    }
                }
                $sql = 'INSERT INTO ' . Database::ident($spec['name']) . " ($columns) VALUES " . implode(', ', array_fill(0, count($chunk), $placeholders));
                $pdo->prepare($sql)->execute($values);
                $result['rows'] += count($chunk);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $result;
    }

    /** @return array<string,string|int> etiqueta sin acentos => valor, para selects */
    private function lookup(array $f): array
    {
        if ($f['type'] !== 'select') {
            return [];
        }
        $out = [];
        if ($f['source'] !== null) {
            $rows = $this->db->fetchAll(sprintf(
                'SELECT %s v, %s l FROM %s',
                Database::ident($f['source']['value']),
                Database::ident($f['source']['label']),
                Database::ident($f['source']['table'])
            ));
            foreach ($rows as $r) {
                $out[ValueParser::fold((string) $r['l'])] ??= $r['v'];
                $out[ValueParser::fold((string) $r['v'])] ??= $r['v'];
            }
            return $out;
        }
        foreach ($f['options'] as $o) {
            $out[ValueParser::fold($o['value'])] = $o['value'];
            foreach ($o['label'] as $label) {
                $out[ValueParser::fold($label)] ??= $o['value'];
            }
        }
        return $out;
    }

    /** @param array{truncated:int} $result */
    private function convert(array $f, string $raw, array $lookup, array &$result): mixed
    {
        switch ($f['type']) {
            case 'number':
                $n = ValueParser::number($raw, $this->excel);
                return $n === null ? null : ($f['decimals'] > 0 ? round($n, $f['decimals']) : (int) round($n));
            case 'date':
                return ValueParser::date($raw);
            case 'datetime':
                return ValueParser::date($raw, true);
            case 'checkbox':
                return ValueParser::bool($raw);
            case 'email':
                return filter_var($raw, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($raw, 0, 150) : null;
            case 'tags':
                $tags = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;]/', $raw) ?: []))));
                return $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null;
            case 'select':
                if ($f['multiple']) {
                    $values = [];
                    foreach (preg_split('/[,;]/', $raw) ?: [] as $part) {
                        if (($v = $lookup[ValueParser::fold($part)] ?? null) !== null) {
                            $values[] = $v;
                        }
                    }
                    return $values ? json_encode(array_values(array_unique($values))) : null;
                }
                return $lookup[ValueParser::fold($raw)] ?? null;
            case 'richtext':
                return '<p>' . nl2br(htmlspecialchars($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>';
            case 'text':
                if (mb_strlen($raw) > $f['maxlength']) {
                    $result['truncated']++;
                    return mb_substr($raw, 0, $f['maxlength']);
                }
                return $raw;
            default:
                return $raw;
        }
    }
}
