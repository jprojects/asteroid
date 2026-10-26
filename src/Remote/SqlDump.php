<?php
declare(strict_types=1);

namespace Quasar\Remote;

use PDO;
use Quasar\Core\Database;

/**
 * Volcado SQL de la base de datos sin depender de mysqldump (muchos hostings no lo tienen).
 * Tablas (estructura + datos) y vistas; las filas se leen sin búfer para no cargar tablas enteras en memoria.
 */
final class SqlDump
{
    private const ROWS_PER_INSERT = 200;
    private const BYTES_PER_INSERT = 1_000_000;

    public function __construct(private readonly array $dbConfig)
    {
    }

    /** @return array{tables:int,views:int,rows:int} */
    public function toFile(string $file): array
    {
        $db = new Database($this->dbConfig);
        // Segunda conexión para leer filas sin búfer mientras la primera hace SHOW CREATE…
        $stream = (new Database($this->dbConfig))->pdo();
        $stream->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

        $out = fopen($file, 'wb') ?: throw new \RuntimeException("No se puede escribir $file");
        $stats = ['tables' => 0, 'views' => 0, 'rows' => 0];
        try {
            fwrite($out, sprintf(
                "-- Quasar · copia de la base de datos `%s`\n-- %s · servidor %s\n\n"
                . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n",
                $this->dbConfig['name'],
                date(DATE_ATOM),
                (string) $db->fetchValue('SELECT VERSION()')
            ));
            // Las fechas TIMESTAMP se vuelcan en UTC, igual que hace mysqldump
            $db->pdo()->exec("SET time_zone = '+00:00'");
            $stream->exec("SET time_zone = '+00:00'");

            $tables = $views = [];
            foreach ($db->pdo()->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$name, $type]) {
                if ($type === 'VIEW') {
                    $views[] = $name;
                } else {
                    $tables[] = $name;
                }
            }

            foreach ($tables as $table) {
                $q = Database::ident($table);
                $create = $db->pdo()->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
                fwrite($out, "-- Tabla $table\nDROP TABLE IF EXISTS $q;\n$create;\n\n");
                $stats['rows'] += $this->dumpRows($db, $stream, $table, $out);
                $stats['tables']++;
            }
            foreach ($views as $view) {
                $q = Database::ident($view);
                $create = $db->pdo()->query("SHOW CREATE VIEW $q")->fetch(PDO::FETCH_NUM)[1];
                // Sin DEFINER: el usuario del hosting donde se restaure será otro
                $create = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', $create) ?? $create;
                fwrite($out, "-- Vista $view\nDROP VIEW IF EXISTS $q;\n$create;\n\n");
                $stats['views']++;
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            fclose($out);
        }
        return $stats;
    }

    /** @param resource $out */
    private function dumpRows(Database $db, PDO $stream, string $table, $out): int
    {
        $q = Database::ident($table);
        $binary = [];
        foreach ($db->fetchAll("SHOW COLUMNS FROM $q") as $col) {
            $binary[$col['Field']] = (bool) preg_match('/blob|binary|^bit/i', (string) $col['Type']);
        }
        $cols = implode(', ', array_map([Database::class, 'ident'], array_keys($binary)));

        $rows = 0;
        $batch = [];
        $bytes = 0;
        $flush = static function () use (&$batch, &$bytes, $out, $q, $cols): void {
            if ($batch) {
                fwrite($out, "INSERT INTO $q ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
                $bytes = 0;
            }
        };

        $result = $stream->query("SELECT * FROM $q", PDO::FETCH_ASSOC);
        foreach ($result as $row) {
            $values = [];
            foreach ($row as $field => $value) {
                $values[] = match (true) {
                    $value === null => 'NULL',
                    $binary[$field] ?? false => $value === '' ? "''" : '0x' . bin2hex((string) $value),
                    default => $db->pdo()->quote((string) $value),
                };
            }
            $tuple = '(' . implode(', ', $values) . ')';
            $batch[] = $tuple;
            $bytes += strlen($tuple);
            $rows++;
            if (count($batch) >= self::ROWS_PER_INSERT || $bytes >= self::BYTES_PER_INSERT) {
                $flush();
            }
        }
        $result->closeCursor();
        $flush();
        if ($rows > 0) {
            fwrite($out, "\n");
        }
        return $rows;
    }
}
