<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

(new Quasar\Core\App($config))->run();
