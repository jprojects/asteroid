<?php
/**
 * Instalación desde Cosmo (Servidores → Nueva instalación): el instalador de un solo uso que sube Cosmo
 * descomprime el paquete y hace require de este fichero, que devuelve la función que termina la instalación.
 *
 *   $params = [
 *     'db' => ['host', 'port', 'name', 'user', 'pass'],
 *     'base_url' => 'https://gestion.cliente.com',
 *     'token' => '…',                      // remote.token para Cosmo
 *     'config' => [...],                   // plantilla de Cosmo: mail, two_factor, reports, ai…
 *     'admin' => ['username', 'password', 'name', 'email'],
 *     'demo' => false,
 *   ]
 *
 * Escribe config/config.local.php, crea las tablas y el administrador y, sin datos de ejemplo, quita los
 * módulos de ejemplo. Devuelve los pasos realizados. No hace nada si ya hay un config.local.php.
 */
declare(strict_types=1);

return static function (array $params): array {
    $root = dirname(__DIR__);
    $localFile = $root . '/config/config.local.php';
    if (is_file($localFile)) {
        throw new RuntimeException('Ya existe config/config.local.php: esta carpeta ya tiene una instalación');
    }
    $db = (array) ($params['db'] ?? []);
    foreach (['host', 'name', 'user'] as $key) {
        if (!is_string($db[$key] ?? null) || $db[$key] === '') {
            throw new RuntimeException("Falta db.$key");
        }
    }
    $token = (string) ($params['token'] ?? '');
    if (strlen($token) < 32) {
        throw new RuntimeException('Falta el token de Cosmo (32 caracteres como mínimo)');
    }

    // config.local.php: la plantilla de Cosmo y, por encima, lo propio de esta instalación
    $local = array_replace_recursive((array) ($params['config'] ?? []), [
        'db' => [
            'host' => $db['host'],
            'port' => (int) ($db['port'] ?? 3306),
            'name' => $db['name'],
            'user' => $db['user'],
            'pass' => (string) ($db['pass'] ?? ''),
        ],
        'base_url' => (string) ($params['base_url'] ?? ''),
        'remote' => ['token' => $token],
    ]);
    $php = "<?php\n// Generado por Cosmo el " . date('Y-m-d H:i') . ". Solo las claves que cambian respecto a config.php.\nreturn "
        . var_export($local, true) . ";\n";
    if (@file_put_contents($localFile, $php, LOCK_EX) === false) {
        throw new RuntimeException('No se puede escribir config/config.local.php');
    }
    @chmod($localFile, 0640);
    $steps = ['config/config.local.php escrito'];

    try {
        $config = require $root . '/src/bootstrap.php';
        $installer = new Quasar\Core\Installer($config);
        $database = $installer->database();
        $demo = !empty($params['demo']);
        $installer->migrate($database, $demo);
        $admin = (array) ($params['admin'] ?? []);
        $installer->admin(
            $database,
            (string) ($admin['username'] ?? 'admin'),
            (string) ($admin['password'] ?? ''),
            (string) ($admin['name'] ?? 'Administrador'),
            (string) ($admin['email'] ?? ''),
        );
        if (!$demo) {
            $installer->removeDemo($root);
        }
    } catch (Throwable $e) {
        // Sin config.local.php se puede volver a intentar desde Cosmo
        @unlink($localFile);
        throw $e;
    }
    return [...$steps, ...$installer->steps()];
};
