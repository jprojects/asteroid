<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Instalación de Quasar: base de datos, tablas y usuario administrador. La usan bin/install.php (consola)
 * y bin/cosmo-setup.php (instalación desde Cosmo, por HTTP, en hostings sin SSH).
 * Se puede repetir: no borra nada, y si el administrador existe le cambia la contraseña.
 */
final class Installer
{
    /** Módulos de ejemplo (y sus textos y hooks): una instalación sin datos de ejemplo no los necesita */
    public const DEMO_FILES = [
        'modules/clients.json', 'modules/countries.json', 'modules/activities.json', 'modules/activities_by_client.json',
        'lang/*/clients.json', 'lang/*/countries.json', 'lang/*/activities.json', 'lang/*/activities_by_client.json',
        'src/Hooks/ClientsHooks.php',
    ];

    /** @var list<string> */
    private array $steps = [];

    public function __construct(private readonly array $config)
    {
    }

    /**
     * Conecta con la base de datos; si no existe, intenta crearla (en hostings compartidos se crea antes en
     * el panel y el usuario no tiene permiso para CREATE DATABASE).
     */
    public function database(): Database
    {
        $c = $this->config['db'];
        try {
            $db = new Database($c);
            $db->pdo();
            $this->steps[] = "base de datos `{$c['name']}`";
            return $db;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1049) { // 1049 = Unknown database
                throw $e;
            }
        }
        $server = new \PDO(
            sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int) $c['port'], $c['charset'] ?? 'utf8mb4'),
            $c['user'],
            $c['pass'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $server->exec(sprintf('CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', Database::ident($c['name'])));
        $this->steps[] = "base de datos `{$c['name']}` creada";
        return new Database($c);
    }

    /** Tablas del núcleo (y las de ejemplo si $demo). */
    public function migrate(Database $db, bool $demo): void
    {
        foreach ((new Migrator($db, (string) $this->config['db']['name']))->run($demo) as $step) {
            $this->steps[] = $step;
        }
    }

    /** Crea el administrador o, si el usuario existe, le pone esta contraseña y lo hace administrador. */
    public function admin(Database $db, string $username, string $password, string $name = 'Administrador', string $email = ''): void
    {
        if (!preg_match('/^[A-Za-z0-9_.]{1,50}$/', $username)) {
            throw new \RuntimeException("Usuario no válido: $username");
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException("Email no válido: $email");
        }
        if (mb_strlen($password) < 8) {
            throw new \RuntimeException('La contraseña necesita 8 caracteres como mínimo');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $existing = $db->fetchValue('SELECT id FROM users WHERE username = ?', [$username]);
        if ($existing) {
            $update = ['password_hash' => $hash, 'is_admin' => 1, 'active' => 1, 'failed_logins' => 0, 'locked_until' => null];
            if ($email !== '') {
                $update['email'] = $email;
            }
            $db->update('users', $update, 'id', $existing);
            $this->steps[] = "usuario '$username' actualizado como administrador";
            return;
        }
        $db->insert('users', [
            'username' => $username,
            'password_hash' => $hash,
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'locale' => $this->config['locale'] ?? 'es',
            'is_admin' => 1,
            'active' => 1,
        ]);
        $this->steps[] = "usuario '$username' creado";
    }

    /** Borra los módulos de ejemplo de una instalación nueva sin datos de ejemplo. */
    public function removeDemo(string $root): void
    {
        $removed = 0;
        foreach (self::DEMO_FILES as $pattern) {
            foreach (glob($root . '/' . $pattern) ?: [] as $file) {
                $removed += @unlink($file) ? 1 : 0;
            }
        }
        if ($removed > 0) {
            $this->steps[] = "módulos de ejemplo quitados ($removed ficheros)";
        }
    }

    /** @return list<string> pasos realizados */
    public function steps(): array
    {
        return $this->steps;
    }
}
