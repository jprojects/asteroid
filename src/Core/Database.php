<?php
declare(strict_types=1);

namespace Quasar\Core;

use PDO;
use PDOStatement;

/**
 * Envoltorio mínimo sobre PDO. Conecta de forma perezosa en la primera consulta.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'],
                (int) ($c['port'] ?? 3306),
                $c['name'],
                $c['charset'] ?? 'utf8mb4'
            );
            $this->pdo = new PDO($dsn, $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return $this->pdo;
    }

    /**
     * Valida y entrecomilla un identificador (tabla o columna).
     * Los identificadores vienen de los JSON, nunca de la petición, pero se validan igualmente.
     */
    public static function ident(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException("Identificador SQL no válido: '$name'");
        }
        return '`' . $name . '`';
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function insert(string $table, array $data): string
    {
        $cols = array_map([self::class, 'ident'], array_keys($data));
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', $cols),
            implode(', ', array_fill(0, count($data), '?'))
        );
        $this->query($sql, $data);
        return $this->pdo()->lastInsertId();
    }

    public function update(string $table, array $data, string $pk, string|int $id): int
    {
        if (!$data) {
            return 0;
        }
        $sets = array_map(static fn (string $c) => self::ident($c) . ' = ?', array_keys($data));
        $sql = sprintf('UPDATE %s SET %s WHERE %s = ?', self::ident($table), implode(', ', $sets), self::ident($pk));
        return $this->query($sql, [...array_values($data), $id])->rowCount();
    }

    public function delete(string $table, string $pk, string|int $id): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s = ?', self::ident($table), self::ident($pk));
        return $this->query($sql, [$id])->rowCount();
    }

    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
