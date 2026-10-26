<?php
declare(strict_types=1);

define('QS_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Quasar\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = QS_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = require QS_ROOT . '/config/config.php';
if (is_file(QS_ROOT . '/config/config.local.php')) {
    $config = array_replace_recursive($config, require QS_ROOT . '/config/config.local.php');
}

date_default_timezone_set($config['timezone'] ?? 'UTC');

$config['log']['level'] ??= !empty($config['debug']) ? 'debug' : 'info';
$config['log']['dir'] ??= QS_ROOT . '/storage/logs';
Quasar\Core\Log::configure($config['log']);

foreach ($config['field_types'] ?? [] as $type => $class) {
    Quasar\Fields\FieldFactory::register($type, $class);
}

return $config;
