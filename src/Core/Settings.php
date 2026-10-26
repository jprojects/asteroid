<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Valores que guarda la propia aplicación (tabla settings), p. ej. la conexión con Google.
 * Para la configuración del desarrollador usa config.local.php.
 */
final class Settings
{
    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $value = $this->db->fetchValue('SELECT value FROM settings WHERE name = ?', [$name]);
        if ($value === null) {
            return $default;
        }
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    public function set(string $name, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->query(
            'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$name, $json]
        );
    }

    public function delete(string $name): void
    {
        $this->db->query('DELETE FROM settings WHERE name = ?', [$name]);
    }
}
