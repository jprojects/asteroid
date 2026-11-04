#!/usr/bin/env php
<?php
/**
 * Asteroid · informes SEO/GEO (Pulsar) de las webs de los proyectos con «Informe SEO/GEO» marcado. Cron diario, con el
 * mismo PHP que la web (cada proyecto se vuelve a analizar cuando han pasado los días de 'pulsar' => ['days' => 7]):
 *
 *   30 3 * * * /opt/alt/php82/usr/bin/php /ruta/a/asteroid/tools/web-audit.php > /dev/null 2>&1
 *
 * Opciones: --all (todos los marcados, aunque no toque) · --project=N (solo ese proyecto, aunque no esté marcado).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;
use Quasar\Core\Log;
use Quasar\WebAudit\WebAudit;

if (!WebAudit::enabled($config)) {
    fwrite(STDERR, "Falta la configuración de Pulsar ('pulsar' => ['url' => …, 'token' => …] en config.local.php)\n");
    exit(1);
}

$opts = getopt('', ['all', 'project:']);
$app = new App($config);
$db = $app->db;

if (isset($opts['project'])) {
    $projects = $db->fetchAll("SELECT * FROM projects WHERE id = ? AND COALESCE(website, '') <> ''", [(int) $opts['project']]);
} else {
    $projects = WebAudit::due($db, $config, isset($opts['all']));
}

$done = 0;
foreach ($projects as $i => $project) {
    if ($i > 0) {
        sleep(3); // sin prisas: el hosting frena las peticiones seguidas
    }
    foreach (WebAudit::run($db, $config, $project) as $row) {
        echo sprintf("%-5s #%d %s: %s\n", strtoupper($row['kind']), $project['id'], $row['url'], $row['score'] ?? ('error: ' . $row['error']));
    }
    $done++;
}
if ($done > 0) {
    Log::info('Informes SEO/GEO: {n} proyecto(s) analizados', ['n' => $done]);
}
echo "Proyectos analizados: $done\n";
