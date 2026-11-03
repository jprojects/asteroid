#!/usr/bin/env php
<?php
/**
 * Asteroid · trae el núcleo de una versión publicada de Quasar (lo mismo que hace la actualización desde Cosmo):
 * copia de la etiqueta los ficheros que lista su core.json y no toca nada más (módulos, hooks, páginas, textos y
 * plantillas propias de Asteroid).
 *
 *   php tools/update-core.php 0.17.0 [--quasar=~/Projects/quasar]
 *
 * Después: php tools/asteroid-migrate.php, probar, y commit. Los ficheros del núcleo que esa versión ya no trae
 * se listan para borrarlos a mano (la actualización desde Cosmo también los quita).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$opts = getopt('', ['quasar:'], $rest);
$version = ltrim((string) ($argv[$rest] ?? ''), 'v');
if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    fwrite(STDERR, "Uso: php tools/update-core.php X.Y.Z [--quasar=…]\n");
    exit(2);
}
$quasar = rtrim((string) ($opts['quasar'] ?? (getenv('HOME') . '/Projects/quasar')), '/');
$tag = "v$version";
$git = static fn (string $args): ?string => shell_exec('git -C ' . escapeshellarg($quasar) . " $args 2>/dev/null");
if ($git('rev-parse --verify --quiet ' . escapeshellarg("$tag^{commit}")) === null) {
    fwrite(STDERR, "No encuentro la etiqueta $tag en $quasar\n");
    exit(2);
}

$core = json_decode((string) $git('show ' . escapeshellarg("$tag:core.json")), true)['core'] ?? [];
$match = static function (string $f) use ($core): bool {
    foreach ($core as $p) {
        if (preg_match('#^' . str_replace(['\*\*', '\*'], ['.*', '[^/]*'], preg_quote((string) $p, '#')) . '$#', $f)) {
            return true;
        }
    }
    return false;
};

$copied = 0;
$new = [];
foreach (array_filter(explode("\n", (string) $git('ls-tree -r --name-only ' . escapeshellarg($tag)))) as $f) {
    if (!$match($f)) {
        continue;
    }
    $content = (string) $git('show ' . escapeshellarg("$tag:$f"));
    $target = "$root/$f";
    if (is_file($target) && file_get_contents($target) === $content) {
        continue;
    }
    if (!is_file($target)) {
        $new[] = $f;
    }
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0775, true);
    }
    file_put_contents($target, $content);
    $copied++;
}
$inTag = array_flip(array_filter(explode("\n", (string) $git('ls-tree -r --name-only ' . escapeshellarg($tag)))));
$gone = [];
foreach (array_filter(explode("\n", (string) shell_exec('git -C ' . escapeshellarg($root) . ' ls-files'))) as $f) {
    if ($match($f) && !isset($inTag[$f]) && !preg_match('#^templates/default/pages/[^/]+\.php$#', $f)) {
        $gone[] = $f;
    }
}

echo "Núcleo de Quasar $tag: $copied fichero(s) copiados" . ($new ? ', ' . count($new) . ' nuevos' : '') . ".\n";
foreach ($gone as $f) {
    echo "  ya no está en Quasar (bórralo si no es de Asteroid): $f\n";
}
echo "Ahora: php tools/asteroid-migrate.php, prueba y commit.\n";
