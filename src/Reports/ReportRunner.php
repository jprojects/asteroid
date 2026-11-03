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

    /**
     * Suma, mínimo, máximo y media de unas columnas numéricas sobre todas las filas del informe (hasta el límite).
     * @param list<string> $names alias de columnas del resultado
     * @return array<string,array{sum:mixed,min:mixed,max:mixed,avg:mixed}>
     */
    public function stats(string $sql, array $base, ?int $limit, array $names): array
    {
        $names = array_values(array_filter($names, static fn ($n) => preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $n)));
        if ($names === []) {
            return [];
        }
        $parts = [];
        foreach ($names as $i => $n) {
            $c = Database::ident($n);
            $parts[] = "SUM($c) AS s$i, MIN($c) AS mn$i, MAX($c) AS mx$i, AVG($c) AS a$i";
        }
        $inner = "SELECT * FROM ($sql) AS q" . self::orderBy($base) . ' LIMIT ' . $this->cap($limit);
        $row = $this->run('SELECT ' . implode(', ', $parts) . " FROM ($inner) AS r", static fn (\PDOStatement $st) => $st->fetch(PDO::FETCH_ASSOC) ?: []);
        $out = [];
        foreach ($names as $i => $n) {
            $out[$n] = ['sum' => $row["s$i"] ?? null, 'min' => $row["mn$i"] ?? null, 'max' => $row["mx$i"] ?? null, 'avg' => $row["a$i"] ?? null];
        }
        return $out;
    }

    /**
     * Filas de una consulta con parámetros (? posicionales), en su orden, hasta $max (informes PDF).
     * @param list<mixed> $params
     * @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool}
     */
    public function select(string $sql, array $params, int $max): array
    {
        return $this->run($sql, static function (\PDOStatement $st) use ($max): array {
            $columns = [];
            for ($i = 0; $i < $st->columnCount(); $i++) {
                $columns[] = (string) (($st->getColumnMeta($i) ?: [])['name'] ?? "col$i");
            }
            $rows = [];
            while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (count($rows) === $max) {
                    return ['columns' => $columns, 'rows' => $rows, 'truncated' => true];
                }
                $rows[] = $row;
            }
            return ['columns' => $columns, 'rows' => $rows, 'truncated' => false];
        }, $params);
    }

    /** Primer valor de una consulta con parámetros (null si no hay filas). */
    public function value(string $sql, array $params): mixed
    {
        return $this->run($sql, static fn (\PDOStatement $st) => ($v = $st->fetchColumn()) === false ? null : $v, $params);
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
     * @param list<mixed>|null $params null = consulta sin parámetros
     * @return T
     */
    private function run(string $sql, \Closure $read, ?array $params = null): mixed
    {
        $pdo = $this->db->pdo();
        $mariadb = $this->isMariaDb();
        $pdo->exec('START TRANSACTION READ ONLY');
        try {
            // MariaDB: max_statement_time (segundos), con SET STATEMENT o, en una consulta preparada, en la sesión
            // mientras dura; MySQL: pista MAX_EXECUTION_TIME (ms)
            if ($mariadb && $params !== null) {
                $pdo->exec(sprintf('SET SESSION max_statement_time = %d', $this->timeout));
            } else {
                $sql = $mariadb
                    ? sprintf('SET STATEMENT max_statement_time=%d FOR %s', $this->timeout, $sql)
                    : preg_replace('/^\s*SELECT\b/i', sprintf('SELECT /*+ MAX_EXECUTION_TIME(%d) */', $this->timeout * 1000), $sql, 1);
            }
            if ($params === null) {
                $st = $pdo->query((string) $sql);
            } else {
                $st = $pdo->prepare((string) $sql);
                $st->execute($params);
            }
            return $read($st);
        } finally {
            $pdo->exec('ROLLBACK');
            if ($mariadb && $params !== null) {
                $pdo->exec('SET SESSION max_statement_time = DEFAULT');
            }
        }
    }

    private function isMariaDb(): bool
    {
        return $this->mariadb ??= str_contains(strtolower((string) $this->db->fetchValue('SELECT VERSION()')), 'mariadb');
    }
}
