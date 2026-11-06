#!/usr/bin/env php
<?php
/**
 * Asteroid · vuelca los datos fiscales de los clientes desde una exportación de FactuSOL (tabla de clientes, F_CLI).
 *
 *   php tools/import-factusol.php clientes.csv            → SQL para phpMyAdmin (por la salida estándar)
 *   php tools/import-factusol.php clientes.csv --apply    → lo aplica a la base de datos de config.local.php
 *   Opciones: --overwrite (sustituye también lo que ya tenga valor), --dry-run (con --apply: solo cuenta)
 *
 * - CSV con cabecera; separador ; , o tabulador; UTF-8 o Windows-1252 (lo que suele dar FactuSOL).
 * - Columnas: las de FactuSOL (CODCLI, NIFCLI, NOFCLI, NOCCLI, DOMCLI, CPOCLI, POBCLI, PROCLI, TELCLI, EMACLI) o los
 *   nombres de Asteroid (factusol_code, nif, legal_name, address, postal_code, city, province, phone, email).
 * - Cada fila se busca por el código de FactuSOL y, si no, por el NIF (sin espacios ni guiones). Si se encuentra por
 *   el NIF, también se apunta el código. Por defecto solo rellena los campos vacíos: no pisa lo escrito a mano.
 * - Clientes que no están en Asteroid: no se crean; se listan al final (en stderr).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$args = array_slice($argv, 1);
$file = null;
$flags = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        $flags[substr($arg, 2)] = true;
    } else {
        $file = $arg;
    }
}
if ($file === null || !is_readable($file)) {
    fwrite(STDERR, "Uso: php tools/import-factusol.php clientes.csv [--apply] [--overwrite] [--dry-run]\n");
    exit(1);
}
$apply = isset($flags['apply']);
$overwrite = isset($flags['overwrite']);
$dryRun = isset($flags['dry-run']);

// Columnas de FactuSOL → campos de Asteroid
const COLUMNS = [
    'codcli' => 'factusol_code', 'nifcli' => 'nif', 'nofcli' => 'legal_name', 'noccli' => 'trade_name',
    'domcli' => 'address', 'cpocli' => 'postal_code', 'pobcli' => 'city', 'procli' => 'province',
    'telcli' => 'phone', 'emacli' => 'email',
];
const FIELDS = ['legal_name', 'address', 'postal_code', 'city', 'province', 'phone', 'email', 'nif'];

$raw = (string) file_get_contents($file);
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
if (!mb_check_encoding($raw, 'UTF-8')) {
    $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
}
$firstLine = strtok($raw, "\r\n") ?: '';
$counts = [';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")];
arsort($counts);
$sep = (string) array_key_first($counts);

$stream = fopen('php://memory', 'w+');
fwrite($stream, $raw);
rewind($stream);
$header = fgetcsv($stream, null, $sep, '"', '');
if (!$header) {
    fwrite(STDERR, "El CSV está vacío.\n");
    exit(1);
}
$map = [];
foreach ($header as $i => $name) {
    $key = strtolower(trim((string) $name));
    $field = COLUMNS[$key] ?? (in_array($key, [...FIELDS, 'factusol_code', 'trade_name'], true) ? $key : null);
    if ($field !== null) {
        $map[$i] = $field;
    }
}
if (!in_array('factusol_code', $map, true) && !in_array('nif', $map, true)) {
    fwrite(STDERR, "El CSV necesita la columna del código (CODCLI) o la del NIF (NIFCLI). Cabecera: " . implode(', ', $header) . "\n");
    exit(1);
}

$rows = [];
while (($line = fgetcsv($stream, null, $sep, '"', '')) !== false) {
    $row = [];
    foreach ($map as $i => $field) {
        $value = trim(preg_replace('/\s+/u', ' ', (string) ($line[$i] ?? '')));
        if ($value !== '') {
            $row[$field] = $value;
        }
    }
    if (isset($row['nif'])) {
        $row['nif'] = strtoupper(str_replace([' ', '-', '.'], '', $row['nif']));
    }
    if (isset($row['email'])) {
        $row['email'] = strtolower($row['email']);
    }
    // FactuSOL deja el nombre fiscal vacío en algunos clientes: entonces vale el comercial
    if (!isset($row['legal_name']) && isset($row['trade_name'])) {
        $row['legal_name'] = $row['trade_name'];
    }
    unset($row['trade_name']);
    if (isset($row['factusol_code']) || isset($row['nif'])) {
        $rows[] = $row;
    }
}

/** SET de un UPDATE: con --overwrite sustituye; si no, solo rellena lo vacío. */
function setClause(array $row, bool $overwrite, callable $quote): string
{
    $sets = [];
    foreach (FIELDS as $field) {
        if (!isset($row[$field])) {
            continue;
        }
        $value = $quote($row[$field]);
        $sets[] = $overwrite ? "$field = $value" : "$field = COALESCE(NULLIF($field, ''), $value)";
    }
    if (isset($row['factusol_code'])) {
        $sets[] = "factusol_code = COALESCE(NULLIF(factusol_code, ''), " . $quote($row['factusol_code']) . ')';
    }
    return implode(', ', $sets);
}

$byNif = "REPLACE(REPLACE(REPLACE(UPPER(nif), ' ', ''), '-', ''), '.', '')";

if (!$apply) {
    $quote = static fn(string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "''"], $v) . "'";
    echo "-- Asteroid: datos fiscales desde FactuSOL (" . basename($file) . ', ' . count($rows) . " clientes)\n";
    echo "-- Antes: columnas de php tools/asteroid-migrate.php (o el ALTER TABLE de ASTEROID.md)\n";
    foreach ($rows as $row) {
        $set = setClause($row, $overwrite, $quote);
        if ($set === '') {
            continue;
        }
        if (isset($row['factusol_code'])) {
            echo "UPDATE clients SET $set WHERE factusol_code = " . $quote($row['factusol_code']) . ";\n";
        }
        if (isset($row['nif'])) {
            $cond = isset($row['factusol_code']) ? " AND (factusol_code IS NULL OR factusol_code = '')" : '';
            echo "UPDATE clients SET $set WHERE $byNif = " . $quote($row['nif']) . "$cond;\n";
        }
    }
    exit(0);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;

$db = (new App($config))->db;
$quote = static fn(string $v): string => $db->pdo()->quote($v);
$updated = 0;
$missing = [];
foreach ($rows as $row) {
    $id = null;
    if (isset($row['factusol_code'])) {
        $id = $db->fetchValue('SELECT id FROM clients WHERE factusol_code = ?', [$row['factusol_code']]);
    }
    if (!$id && isset($row['nif'])) {
        $id = $db->fetchValue("SELECT id FROM clients WHERE $byNif = ?", [$row['nif']]);
    }
    if (!$id) {
        $missing[] = trim(($row['factusol_code'] ?? '') . ' ' . ($row['nif'] ?? '') . ' ' . ($row['legal_name'] ?? ''));
        continue;
    }
    $set = setClause($row, $overwrite, $quote);
    if ($set !== '' && !$dryRun) {
        $db->query("UPDATE clients SET $set WHERE id = ?", [(int) $id]);
    }
    $updated++;
}
echo ($dryRun ? 'Se actualizarían' : 'Actualizados') . " $updated clientes.\n";
if ($missing) {
    fwrite(STDERR, count($missing) . " filas sin cliente en Asteroid (no se crean):\n  " . implode("\n  ", $missing) . "\n");
}
