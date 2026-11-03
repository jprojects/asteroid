#!/usr/bin/env php
<?php
/**
 * Asteroid · comprueba que el núcleo no se ha tocado: cada fichero de core.json tiene que ser idéntico al de la
 * etiqueta de Quasar de VERSION (v0.16.0…). Si no, la siguiente actualización desde Cosmo lo pisaría.
 *
 *   php tools/check-core.php [--quasar=~/Projects/quasar]
 *
 * Sale con 1 si hay diferencias. Las plantillas de páginas propias (templates/default/pages/*.php que Quasar no tiene)
 * son de Asteroid y no cuentan.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$opts = getopt('', ['quasar:']);
$quasar = rtrim((string) ($opts['quasar'] ?? (getenv('HOME') . '/Projects/quasar')), '/');
$version = trim((string) file_get_contents("$root/VERSION"));
$tag = "v$version";

$git = static fn (string $args): ?string => ($out = shell_exec('git -C ' . escapeshellarg($quasar) . " $args 2>/dev/null")) === null ? null : $out;
if ($git('rev-parse --verify --quiet ' . escapeshellarg("$tag^{commit}")) === null) {
    fwrite(STDERR, "No encuentro la etiqueta $tag en $quasar (¿falta git fetch --tags o publicar esa versión?)\n");
    exit(2);
}
$inQuasar = array_flip(array_filter(explode("\n", (string) $git('ls-tree -r --name-only ' . escapeshellarg($tag)))));

$core = json_decode((string) file_get_contents("$root/core.json"), true)['core'] ?? [];
$match = static function (string $f) use ($core): bool {
    foreach ($core as $p) {
        if (preg_match('#^' . str_replace(['\*\*', '\*'], ['.*', '[^/]*'], preg_quote((string) $p, '#')) . '$#', $f)) {
            return true;
        }
    }
    return false;
};

$files = array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-files --cached --others --exclude-standard')));
$changed = $extra = [];
foreach ($files as $f) {
    if (!$match($f) || !is_file("$root/$f")) {
        continue;
    }
    if (!isset($inQuasar[$f])) {
        if (!preg_match('#^templates/default/pages/[^/]+\.php$#', $f)) {
            $extra[] = $f;
        }
        continue;
    }
    if ((string) $git('show ' . escapeshellarg("$tag:$f")) !== (string) file_get_contents("$root/$f")) {
        $changed[] = $f;
    }
}

if (!$changed && !$extra) {
    echo "Núcleo intacto: igual que Quasar $tag.\n";
    exit(0);
}
echo "El núcleo no es el de Quasar $tag:\n";
foreach ($changed as $f) {
    echo "  modificado  $f\n";
}
foreach ($extra as $f) {
    echo "  nuevo       $f   (no está en Quasar: muévelo fuera del núcleo o llévalo a Quasar)\n";
}
echo "Los cambios del núcleo se hacen en $quasar y se publican como una versión nueva.\n";
exit(1);
