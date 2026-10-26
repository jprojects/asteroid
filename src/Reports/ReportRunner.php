<?php
declare(strict_types=1);

namespace Quasar\Reports;

use PDO;
use Quasar\Core\Database;

/**
 * Ejecuta la consulta de un informe en una transacción de solo lectura y con tiempo máximo.
 * La consulta generada va siempre envuelta: SELECT * FROM (consulta) AS q ORDER BY … LIMIT …,
 * así la paginación, el orden por columnas y el máximo de filas los pone Quasar, no la IA.
 */
final class ReportRunner
{
    private ?bool $mariadb = null;

    public function __construct(
        private readonly Database $db,
        private readonly int $timeout = 15,
        private readonly int $maxRows = 10000,
    ) {
    }

    /**
     * Columnas del resultado (sin leer filas).
     * @return list<array{name:string,native:string}>
     */
    public function columns(string $sql): array
    {
        return $this->run("SELECT * FROM ($sql) AS q LIMIT 0", static function (\PDOStatement $st): array {
            $out = [];
            for ($i = 0; $i < $st->columnCount(); $i++) {
                $meta = $st->getColumnMeta($i) ?: [];
                $out[] = ['name' => (string) ($meta['name'] ?? "col$i"), 'native' => strtoupper((string) ($meta['native_type'] ?? ''))];
            }
            return $out;
        });
    }

    /** Número de filas (como mucho el límite del informe o 'max_rows'). */
    public function count(string $sql, ?int $limit): int
    {
        $cap = $this->cap($limit);
        return (int) $this->run(
            "SELECT COUNT(*) FROM (SELECT 1 FROM ($sql) AS q LIMIT $cap) AS c",
            static fn (\PDOStatement $st) => $st->fetchColumn()
        );
    }

    /**
     * Filas de una página.
     * @param list<array{0:int,1:string}> $order [posición de columna (1…), asc|desc]: primero la del usuario, luego la del informe
     * @param list<array{0:int,1:string}> $base  orden propio del informe (define también qué filas entran con "limit")
     * @return list<list<mixed>>
     */
    public function rows(string $sql, array $base, ?int $limit, array $order, int $size, int $offset): array
    {
        $cap = $this->cap($limit);
        // Primero se recortan las filas del informe (p. ej. "los 10 que más…") con su orden; luego se pagina
        $inner = "SELECT * FROM ($sql) AS q" . self::orderBy($base) . " LIMIT $cap";
        $outer = "SELECT * FROM ($inner) AS r" . self::orderBy($order ?: $base) . sprintf(' LIMIT %d OFFSET %d', max(1, $size), max(0, $offset));
        return $this->run($outer, static fn (\PDOStatement $st) => $st->fetchAll(PDO::FETCH_NUM));
    }

    /** @param list<array{0:int,1:string}> $order */
    private static function orderBy(array $order): string
    {
        $parts = [];
        foreach ($order as [$pos, $dir]) {
            $parts[] = (int) $pos . ($dir === 'desc' ? ' DESC' : ' ASC');
        }
        return $parts ? ' ORDER BY ' . implode(', ', $parts) : '';
    }

    private function cap(?int $limit): int
    {
        return $limit !== null && $limit > 0 ? min($limit, $this->maxRows) : $this->maxRows;
    }

    /**
     * Ejecuta en una transacción de solo lectura con tiempo máximo y la deshace siempre.
     * @template T
     * @param \Closure(\PDOStatement):T $read
     * @return T
     */
    private function run(string $sql, \Closure $read): mixed
    {
        $pdo = $this->db->pdo();
        $pdo->exec('START TRANSACTION READ ONLY');
        try {
            // MariaDB: SET STATEMENT max_statement_time (segundos); MySQL: pista MAX_EXECUTION_TIME (ms)
            $sql = $this->isMariaDb()
                ? sprintf('SET STATEMENT max_statement_time=%d FOR %s', $this->timeout, $sql)
                : preg_replace('/^SELECT/', sprintf('SELECT /*+ MAX_EXECUTION_TIME(%d) */', $this->timeout * 1000), $sql, 1);
            $st = $pdo->query((string) $sql);
            return $read($st);
        } finally {
            $pdo->exec('ROLLBACK');
        }
    }

    private function isMariaDb(): bool
    {
        return $this->mariadb ??= str_contains(strtolower((string) $this->db->fetchValue('SELECT VERSION()')), 'mariadb');
    }
}
