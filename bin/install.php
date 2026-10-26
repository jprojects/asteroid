#!/usr/bin/env php
<?php
/**
 * Instalador: crea la base de datos, las tablas y el usuario administrador.
 *
 *   php bin/install.php                       (pregunta usuario y contraseña)
 *   php bin/install.php --user=admin --password=secreto --name="Administrador" --email=admin@empresa.com
 *   php bin/install.php --no-demo             (sin tablas ni datos de ejemplo)
 *
 * Se puede ejecutar varias veces: no borra nada, y si el usuario existe
 * le cambia la contraseña y lo convierte en administrador.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\Database;
use Quasar\Core\Migrator;

function ask(string $question, bool $hidden = false): string
{
    echo $question;
    $canHide = $hidden && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && posix_isatty_safe();
    if ($canHide) {
        shell_exec('stty -echo');
    }
    $value = fgets(STDIN);
    if ($canHide) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }
    return trim((string) $value);
}

function posix_isatty_safe(): bool
{
    return function_exists('posix_isatty') ? posix_isatty(STDIN) : stream_isatty(STDIN);
}

$opts = getopt('', ['user:', 'password:', 'name:', 'email:', 'no-demo']);
$c = $config['db'];

try {
    echo "Conectando con MySQL en {$c['host']}:{$c['port']}…" . PHP_EOL;
    $server = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int) $c['port'], $c['charset'] ?? 'utf8mb4'),
        $c['user'],
        $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $server->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        Database::ident($c['name'])
    ));
    echo "  ✓ base de datos `{$c['name']}`" . PHP_EOL;

    $db = new Database($c);
    foreach ((new Migrator($db, $c['name']))->run(!isset($opts['no-demo'])) as $step) {
        echo "  ✓ $step" . PHP_EOL;
    }

    $username = (string) ($opts['user'] ?? '') ?: (ask('Usuario administrador [admin]: ') ?: 'admin');
    $name = (string) ($opts['name'] ?? '') ?: 'Administrador';
    $email = (string) ($opts['email'] ?? '');
    if (!isset($opts['email'])) {
        $email = ask('Email del administrador (necesario si activas la verificación en dos pasos): ');
    }
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException("Email no válido: $email");
    }
    $password = (string) ($opts['password'] ?? '');
    while (mb_strlen($password) < 8) {
        $password = ask('Contraseña (mín. 8 caracteres): ', true);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $existing = $db->fetchValue('SELECT id FROM users WHERE username = ?', [$username]);
    if ($existing) {
        $update = ['password_hash' => $hash, 'is_admin' => 1, 'active' => 1, 'failed_logins' => 0, 'locked_until' => null];
        if ($email !== '') {
            $update['email'] = $email;
        }
        $db->update('users', $update, 'id', $existing);
        echo "  ✓ usuario '$username' actualizado como administrador" . PHP_EOL;
    } else {
        $db->insert('users', [
            'username' => $username,
            'password_hash' => $hash,
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'locale' => $config['locale'] ?? 'es',
            'is_admin' => 1,
            'active' => 1,
        ]);
        echo "  ✓ usuario '$username' creado" . PHP_EOL;
    }

    echo PHP_EOL . 'Listo. Arranca con:  php -S localhost:8000 -t public' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
