#!/usr/bin/env php
<?php
/**
 * Borra archivos de la tabla media que ningún registro referencia
 * (subidas abandonadas, registros eliminados, archivos quitados de un formulario).
 *
 *   php bin/media-clean.php              (huérfanos con más de 24 h)
 *   php bin/media-clean.php --hours=72
 *   php bin/media-clean.php --dry-run    (solo lista lo que borraría)
 *
 * Pensado para ejecutarse desde cron, p. ej. una vez al día.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;
use Quasar\Core\Database;
use Quasar\Fields\Field;
use Quasar\Fields\MediaField;
use Quasar\Fields\RepeaterField;
use Quasar\Fields\RichTextField;

$opts = getopt('', ['hours:', 'dry-run']);
$hours = max(1, (int) ($opts['hours'] ?? 24));
$dryRun = isset($opts['dry-run']);

/** ¿El campo (o alguno de sus subcampos) puede contener archivos? */
function holdsMedia(Field $field): bool
{
    if ($field instanceof MediaField || $field instanceof RichTextField) {
        return true;
    }
    if ($field instanceof RepeaterField) {
        foreach ($field->subFields() as $sub) {
            if (holdsMedia($sub)) {
                return true;
            }
        }
    }
    return false;
}

try {
    $app = new App($config);
    $db = $app->db;

    // 1. Ids referenciados por algún registro de algún módulo
    $referenced = [];
    foreach ($app->modules()->all() as $module) {
        $fields = array_filter($module->fields(), static fn (Field $f) => $f->isStored() && holdsMedia($f));
        if (!$fields) {
            continue;
        }
        $cols = implode(', ', array_map(static fn (Field $f) => Database::ident($f->column()), $fields));
        $stmt = $db->query(sprintf('SELECT %s FROM %s', $cols, Database::ident($module->table())));
        while ($row = $stmt->fetch()) {
            foreach ($fields as $field) {
                foreach ($field->mediaIds($row[$field->column()] ?? null) as $id) {
                    $referenced[$id] = true;
                }
            }
        }
    }

    // Adjuntos de los mensajes de las conversaciones (chat de los registros)
    $stmt = $db->query('SELECT media FROM messages WHERE media IS NOT NULL');
    while ($row = $stmt->fetch()) {
        foreach ((array) json_decode((string) $row['media'], true) as $id) {
            $referenced[(int) $id] = true;
        }
    }

    // 2. Archivos antiguos no referenciados
    $limit = date('Y-m-d H:i:s', time() - $hours * 3600);
    $deleted = 0;
    $bytes = 0;
    foreach ($db->fetchAll('SELECT * FROM media WHERE created_at < ?', [$limit]) as $row) {
        if (isset($referenced[(int) $row['id']])) {
            continue;
        }
        echo ($dryRun ? '[simulación] ' : '') . "#{$row['id']} {$row['module']}.{$row['field']} {$row['original_name']}" . PHP_EOL;
        if (!$dryRun) {
            $app->media()->delete($row);
        }
        $deleted++;
        $bytes += (int) $row['size'];
    }

    printf(
        "%s %d archivo(s), %s.%s",
        $dryRun ? 'Se borrarían' : 'Borrados',
        $deleted,
        Quasar\Core\MediaStorage::humanSize($bytes),
        PHP_EOL
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
