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

use Quasar\Core\Installer;

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
    $installer = new Installer($config);
    $db = $installer->database();
    $installer->migrate($db, !isset($opts['no-demo']));
    foreach ($installer->steps() as $step) {
        echo "  ✓ $step" . PHP_EOL;
    }
    $shown = count($installer->steps());

    $username = (string) ($opts['user'] ?? '') ?: (ask('Usuario administrador [admin]: ') ?: 'admin');
    $name = (string) ($opts['name'] ?? '') ?: 'Administrador';
    $email = (string) ($opts['email'] ?? '');
    if (!isset($opts['email'])) {
        $email = ask('Email del administrador (necesario si activas la verificación en dos pasos): ');
    }
    $password = (string) ($opts['password'] ?? '');
    while (mb_strlen($password) < 8) {
        $password = ask('Contraseña (mín. 8 caracteres): ', true);
    }
    $installer->admin($db, $username, $password, $name, $email);
    foreach (array_slice($installer->steps(), $shown) as $step) {
        echo "  ✓ $step" . PHP_EOL;
    }

    echo PHP_EOL . 'Listo. Arranca con:  php -S localhost:8000 -t public' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
