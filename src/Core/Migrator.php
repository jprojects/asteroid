<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Crea o pone al día las tablas del núcleo. Lo usan bin/install.php y la actualización remota
 * (?r=remote&a=migrate), así que todo lo que hace es idempotente: no borra nada.
 */
final class Migrator
{
    /** @var list<string> */
    private array $log = [];

    public function __construct(private readonly Database $db, private readonly string $schema)
    {
    }

    /**
     * @param bool $demo también las tablas y datos de ejemplo (solo en la instalación)
     * @return list<string> pasos realizados
     */
    public function run(bool $demo = false): array
    {
        $this->log = [];
        // Columnas añadidas después (antes del schema, que crea tablas que dependen de ellas)
        $this->ensureColumn('users', 'two_factor', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_admin');
        $this->ensureColumn('users', 'groups', 'JSON NULL AFTER is_admin');
        $this->ensureColumn('users', 'notify_email', "VARCHAR(10) NOT NULL DEFAULT 'instant' AFTER two_factor");
        $this->runSqlFile(QS_ROOT . '/database/schema.sql');

        // Tablas de ejemplo: se ponen al día si existen, y solo se crean si se piden
        $this->ensureColumn('clients', 'contacts', 'JSON NULL');
        $this->ensureColumn('clients', 'logo', 'INT UNSIGNED NULL');
        $this->ensureColumn('clients', 'documents', 'JSON NULL');
        $this->ensureColumn('clients', 'tags', 'JSON NULL');
        $this->ensureColumn('clients', 'sectors', 'JSON NULL');
        if ($demo) {
            $this->runSqlFile(QS_ROOT . '/database/demo.sql');
        }
        return $this->log;
    }

    /** Añade una columna si la tabla existe y la columna no. */
    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $tableExists = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$this->schema, $table]
        );
        if (!$tableExists) {
            return;
        }
        $exists = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->schema, $table, $column]
        );
        if (!$exists) {
            $this->db->pdo()->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', Database::ident($table), Database::ident($column), $definition));
            $this->log[] = "columna $table.$column añadida";
        }
    }

    private function runSqlFile(string $file): void
    {
        $sql = (string) file_get_contents($file);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
            if (trim($statement) !== '') {
                $this->db->pdo()->exec($statement);
            }
        }
        $this->log[] = basename($file);
    }
}
