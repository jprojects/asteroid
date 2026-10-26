#!/usr/bin/env php
<?php
/**
 * Asteroid · importa los datos del Asteroid antiguo (Franky CMS, tablas franky_*) a esta instalación.
 *
 *   php tools/import-asteroid.php --host=127.0.0.1 --port=3306 --name=asteroid_antiguo --user=… --pass=… \
 *       --files=/ruta/al/asteroid/admin/assets [--prefix=franky_] [--admins=kim] [--dry-run]
 *
 * - Se puede repetir hasta el día del cambio: conserva los ids, actualiza lo que ya existe y no vuelve a
 *   copiar los archivos ya importados (tabla asteroid_import_media).
 * - Usuarios: level 1 → grupo "equip" (los de --admins, además, administradores); level 2 → grupo "client"
 *   con su cliente. Las contraseñas (bcrypt) se conservan: todos entran con la de siempre.
 * - Descripciones: el HTML se limpia y las imágenes incrustadas (base64) pasan a ser archivos.
 * - Comentarios → conversación de cada incidencia (con sus imágenes). Se marcan como leídos.
 * - Adjuntos de tipos que Quasar no admite (html, mp4, sql…) no se importan: se listan al final.
 *
 * Antes: php bin/install.php --no-demo  y  php tools/asteroid-migrate.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;
use Quasar\Core\Database;
use Quasar\Core\HtmlSanitizer;
use Quasar\Core\MediaException;

$opts = getopt('', ['host:', 'port:', 'name:', 'user:', 'pass:', 'prefix:', 'files:', 'admins:', 'dry-run']);
foreach (['name', 'user', 'files'] as $required) {
    if (empty($opts[$required])) {
        fwrite(STDERR, "Falta --$required. Uso: php tools/import-asteroid.php --name=bd --user=usuario --pass=… --files=/ruta/admin/assets\n");
        exit(1);
    }
}
$prefix = (string) ($opts['prefix'] ?? 'franky_');
if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
    fwrite(STDERR, "Prefijo no válido\n");
    exit(1);
}
$filesDir = rtrim((string) $opts['files'], '/');
$admins = array_filter(array_map('trim', explode(',', (string) ($opts['admins'] ?? 'kim'))));
$dryRun = isset($opts['dry-run']);

$legacy = new Database([
    'host' => (string) ($opts['host'] ?? '127.0.0.1'),
    'port' => (int) ($opts['port'] ?? 3306),
    'name' => (string) $opts['name'],
    'user' => (string) $opts['user'],
    'pass' => (string) ($opts['pass'] ?? ''),
    'charset' => 'utf8mb4',
]);
$t = static fn (string $table) => Database::ident($prefix . $table);

$app = new App($config);
$db = $app->db;
$media = $app->media();
$skipped = [];
$counts = [];

/** INSERT … ON DUPLICATE KEY UPDATE: crea o actualiza conservando el id. */
$upsert = static function (string $table, array $row) use ($db): void {
    $cols = array_keys($row);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
        Database::ident($table),
        implode(', ', array_map([Database::class, 'ident'], $cols)),
        implode(', ', array_fill(0, count($cols), '?')),
        implode(', ', array_map(static fn ($c) => Database::ident($c) . ' = VALUES(' . Database::ident($c) . ')', array_diff($cols, ['id'])))
    );
    $db->query($sql, array_values($row));
};

/** Copia un archivo a media una sola vez (por su origen); null si no existe o el tipo no se admite. */
$importFile = static function (string $source, string $name, string $module, string $field, ?int $userId, ?string $createdAt) use ($db, $media, &$skipped): ?int {
    $known = $db->fetchValue('SELECT media_id FROM asteroid_import_media WHERE source = ?', [$source]);
    if ($known !== null && $db->fetchValue('SELECT 1 FROM media WHERE id = ?', [(int) $known])) {
        return (int) $known;
    }
    if (!is_file($source)) {
        $skipped[] = "no existe: $source";
        return null;
    }
    // Capturas con la extensión cambiada (un .jpeg que en realidad es PNG): se corrige la extensión
    $images = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $real = $images[(string) (new finfo(FILEINFO_MIME_TYPE))->file($source)] ?? null;
    if ($real !== null && !in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $real === 'jpg' ? ['jpg', 'jpeg'] : [$real], true)) {
        $name = pathinfo($name, PATHINFO_FILENAME) . '.' . $real;
    }
    try {
        $info = $media->importFile($source, $name, $module, $field, $userId, $createdAt);
    } catch (MediaException $e) {
        $skipped[] = "tipo no admitido ({$e->langKey}): $source";
        return null;
    }
    $db->query('INSERT INTO asteroid_import_media (source, media_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE media_id = VALUES(media_id)', [$source, $info['id']]);
    return (int) $info['id'];
};

/** "01:30", "1:30:00", "1:30h" → minutos */
$minutes = static function (mixed $time): ?int {
    if (!is_string($time) || !preg_match('/^\s*(\d{1,3}):(\d{1,2})/', $time, $m)) {
        return null;
    }
    $total = (int) $m[1] * 60 + (int) $m[2];
    return $total > 0 ? $total : null;
};
$date = static fn (mixed $v): ?string => is_string($v) && $v !== '' && !str_starts_with($v, '0000') ? substr($v, 0, 10) : null;
$datetime = static fn (mixed $v): ?string => is_string($v) && $v !== '' && !str_starts_with($v, '0000') ? $v : null;
$decimal = static function (mixed $v): ?string {
    $v = str_replace([',', '€', ' '], ['.', '', ''], (string) $v);
    return is_numeric($v) && (float) $v != 0.0 ? number_format((float) $v, 2, '.', '') : null;
};

try {
    $db->pdo()->exec('CREATE TABLE IF NOT EXISTS asteroid_import_media (
        source VARCHAR(500) NOT NULL, media_id INT UNSIGNED NOT NULL, PRIMARY KEY (source(255))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    if ($dryRun) {
        $db->pdo()->beginTransaction();
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');

    // ---------------------------------------------------------------- clientes y proyectos
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('clients')) as $r) {
        $upsert('clients', [
            'id' => (int) $r['id'],
            'name' => trim((string) $r['nom']) ?: '#' . $r['id'],
            'nif' => trim((string) $r['nif']) ?: null,
            'factusol_code' => trim((string) $r['codiFactusol']) ?: null,
        ]);
        $counts['clients'] = ($counts['clients'] ?? 0) + 1;
    }
    $clientIds = array_flip(array_map('intval', array_column($db->fetchAll('SELECT id FROM clients'), 'id')));

    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('projects')) as $r) {
        $upsert('projects', [
            'id' => (int) $r['id'],
            'client_id' => isset($clientIds[(int) $r['project_clientId']]) ? (int) $r['project_clientId'] : null,
            'title' => trim((string) $r['project_title']) ?: '#' . $r['id'],
            'code' => trim((string) $r['project_abreujatura']) ?: null,
            'notes' => trim((string) $r['project_observacions']) ?: null,
            'api_key' => trim((string) $r['apikey']) ?: null,
            'active' => (int) $r['project_status'] === 1 ? 1 : 0,
            'budget' => $decimal($r['project_budget']),
        ]);
        $counts['projects'] = ($counts['projects'] ?? 0) + 1;
    }
    $projects = [];
    foreach ($db->fetchAll('SELECT id, client_id FROM projects') as $p) {
        $projects[(int) $p['id']] = $p['client_id'] !== null ? (int) $p['client_id'] : null;
    }

    // ---------------------------------------------------------------- usuarios
    $groupId = static fn (string $code) => (int) ($db->fetchValue('SELECT id FROM user_groups WHERE code = ?', [$code])
        ?? throw new RuntimeException("Falta el grupo '$code': ejecuta antes tools/asteroid-migrate.php"));
    $equip = $groupId('equip');
    $client = $groupId('client');
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('users')) as $r) {
        $isStaff = (int) $r['level'] === 1;
        $username = trim((string) $r['username']);
        $taken = $db->fetchValue('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, (int) $r['id']]);
        if ($taken !== null) {
            $skipped[] = "usuario '$username' (#{$r['id']}): ya existe otro usuario con ese nombre (#$taken)";
            continue;
        }
        $clientId = !$isStaff && isset($clientIds[(int) $r['clientId']]) ? (int) $r['clientId'] : null;
        $locale = substr((string) $r['language'], 0, 2);
        $upsert('users', [
            'id' => (int) $r['id'],
            'username' => $username,
            'password_hash' => (string) $r['password'],
            'name' => $isStaff ? mb_convert_case($username, MB_CASE_TITLE) : $username,
            'email' => trim((string) $r['email']) ?: null,
            'locale' => in_array($locale, ['ca', 'es', 'en'], true) ? $locale : 'ca',
            'is_admin' => $isStaff && in_array($username, $admins, true) ? 1 : 0,
            'groups' => json_encode([$isStaff ? $equip : $client]),
            'active' => (int) $r['block'] === 0 ? 1 : 0,
            'client_id' => $clientId,
            'created_at' => $datetime($r['registerDate']) ?? date('Y-m-d H:i:s'),
            'last_login' => $datetime($r['lastvisitDate']),
        ]);
        if (!$isStaff && $clientId === null) {
            $skipped[] = "usuario de cliente '$username' (#{$r['id']}): su cliente #{$r['clientId']} no existe; queda sin cliente";
        }
        $counts['users'] = ($counts['users'] ?? 0) + 1;
    }
    $userIds = array_flip(array_map('intval', array_column($db->fetchAll('SELECT id FROM users'), 'id')));
    $userOrNull = static fn (mixed $id) => isset($userIds[(int) $id]) ? (int) $id : null;

    // ---------------------------------------------------------------- incidencias, adjuntos y conversación
    $attachments = [];
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('issues_attachments') . ' ORDER BY id') as $a) {
        $attachments[(int) $a['issueId']][] = $a;
    }
    $stmt = $legacy->query('SELECT * FROM ' . $t('issues') . ' ORDER BY id');
    while ($r = $stmt->fetch()) {
        $id = (int) $r['id'];
        $projectId = array_key_exists((int) $r['projectId'], $projects) ? (int) $r['projectId'] : null;
        $created = $datetime($r['issue_date']) ?? date('Y-m-d H:i:s');

        // Imágenes en base64 del editor antiguo → archivos de Quasar
        $html = (string) $r['issue_desc'];
        $n = 0;
        $html = (string) preg_replace_callback('~src=(["\'])data:image/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=\s]+)\1~i', static function ($m) use ($id, &$n, $importFile, $created) {
            $n++;
            $ext = strtolower($m[2]) === 'jpeg' ? 'jpg' : strtolower($m[2]);
            $tmp = sys_get_temp_dir() . '/asteroid-img-' . $id . '-' . $n . '.' . $ext;
            file_put_contents($tmp, base64_decode(preg_replace('/\s+/', '', $m[3]) ?? '', true) ?: '');
            $mediaId = $importFile($tmp, "imatge-$id-$n.$ext", 'issues', 'description', null, $created);
            @unlink($tmp);
            return $mediaId !== null ? 'src="index.php?r=media&amp;id=' . $mediaId . '"' : 'src=""';
        }, $html);
        $html = trim($html) === "''" ? '' : $html;
        $description = str_contains($html, '<') ? HtmlSanitizer::clean($html) : nl2br(htmlspecialchars(trim($html), ENT_QUOTES, 'UTF-8'), false);

        $files = [];
        foreach ($attachments[$id] ?? [] as $a) {
            $mediaId = $importFile($filesDir . '/files/' . $a['attachment'], (string) $a['attachment'], 'issues', 'attachments', null, $created);
            if ($mediaId !== null) {
                $files[] = $mediaId;
            }
        }
        $status = (int) $r['issue_status'];
        $upsert('issues', [
            'id' => $id,
            'client_id' => $projectId !== null ? $projects[$projectId] : null,
            'project_id' => $projectId,
            'title' => mb_substr(trim((string) $r['issue_title']), 0, 150) ?: '#' . $id,
            'description' => $description !== '' ? $description : null,
            'priority' => min(2, max(0, (int) $r['issue_priority'])),
            'status' => $status >= 1 && $status <= 4 ? $status : 1,
            'worker_id' => $userOrNull($r['issue_worker']),
            'estimated_minutes' => $minutes($r['issue_time']),
            'starts_on' => $date($r['issue_date_in']),
            'due_on' => $status <= 2 ? $date($r['issue_date_out']) : null,
            'client_visible' => (int) $r['created_from_client'] === 1 ? 1 : 0,
            'attachments' => $files ? json_encode($files) : null,
            'created_at' => $created,
        ]);
        if ($projectId === null) {
            $skipped[] = "incidencia #$id: el proyecto #{$r['projectId']} no existe; queda sin proyecto";
        }
        $counts['issues'] = ($counts['issues'] ?? 0) + 1;
    }

    // La conversación se vuelve a crear entera en cada importación (los mensajes no tienen id propio)
    $db->query("DELETE FROM messages WHERE module = 'issues' AND JSON_EXTRACT(meta, '$.legacy') IS NOT NULL");
    $issueIds = array_flip(array_map('intval', array_column($db->fetchAll('SELECT id FROM issues'), 'id')));
    $stmt = $legacy->query('SELECT * FROM ' . $t('comments') . ' ORDER BY comment_id');
    while ($c = $stmt->fetch()) {
        if (!isset($issueIds[(int) $c['comment_issue']])) {
            continue; // comentario de una incidencia que ya no existe
        }
        $text = (string) $c['comment_txt'];
        $text = str_contains($text, '<') ? HtmlSanitizer::toText($text) : html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $files = [];
        if (!empty($c['comment_img'])) {
            $mediaId = $importFile($filesDir . '/files/comments/' . $c['comment_img'], (string) $c['comment_img'], 'issues', '_chat', $userOrNull($c['comment_user']), $c['comment_data']);
            if ($mediaId !== null) {
                $files[] = $mediaId;
            }
        }
        if (trim($text) === '' && !$files) {
            continue;
        }
        $db->insert('messages', [
            'module' => 'issues',
            'record_id' => (string) (int) $c['comment_issue'],
            'user_id' => $userOrNull($c['comment_user']),
            'kind' => 'text',
            'body' => trim($text) !== '' ? mb_substr(trim($text), 0, 5000) : null,
            'media' => $files ? json_encode($files) : null,
            'meta' => json_encode(['legacy' => (int) $c['comment_id']]),
            'created_at' => $datetime($c['comment_data']) ?? date('Y-m-d H:i:s'),
        ]);
        $counts['messages'] = ($counts['messages'] ?? 0) + 1;
    }
    // Todo lo importado, leído (nadie empieza con miles de mensajes pendientes), pero solo por quien puede ver
    // cada incidencia: el equipo y, si es visible para el cliente, los usuarios de ese cliente
    $entitled = "(u.is_admin = 1 OR JSON_CONTAINS(u.`groups`, (SELECT CONCAT(g.id) FROM user_groups g WHERE g.code = 'equip'))
                  OR (u.client_id = i.client_id AND i.client_visible = 1))";
    $db->query("DELETE r FROM message_reads r JOIN users u ON u.id = r.user_id
                  LEFT JOIN issues i ON i.id = r.record_id
                 WHERE r.module = 'issues' AND (i.id IS NULL OR NOT $entitled)");
    $db->query("INSERT INTO message_reads (user_id, module, record_id, last_read_id, seen_at)
                SELECT u.id, 'issues', m.record_id, MAX(m.id), '2000-01-01' FROM messages m
                  JOIN issues i ON i.id = m.record_id
                  JOIN users u ON u.active = 1 AND $entitled
                 WHERE m.module = 'issues'
                 GROUP BY u.id, m.record_id
                ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))");

    // ---------------------------------------------------------------- horas, contratos y horas/mes
    $stmt = $legacy->query('SELECT * FROM ' . $t('hores') . ' ORDER BY id');
    while ($r = $stmt->fetch()) {
        $issue = isset($issueIds[(int) $r['incidenciaId']]) ? (int) $r['incidenciaId'] : null;
        $upsert('hours', [
            'id' => (int) $r['id'],
            'user_id' => $userOrNull($r['userid']),
            'project_id' => array_key_exists((int) $r['projecteId'], $projects) ? (int) $r['projecteId'] : null,
            'issue_id' => $issue,
            'worked_on' => $date($r['data']) ?? date('Y-m-d'),
            'minutes' => $minutes($r['hores']) ?? 0,
            'comment' => trim((string) $r['comentari']) ?: null,
            'billing' => in_array((int) $r['facturat'], [0, 1, 2], true) ? (int) $r['facturat'] : 0,
        ]);
        $counts['hours'] = ($counts['hours'] ?? 0) + 1;
    }
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('workers_contracts')) as $r) {
        $upsert('contracts', [
            'id' => (int) $r['id'],
            'user_id' => (int) $r['userId'],
            'starts_on' => $date($r['dataInicial']) ?? '2000-01-01',
            'hours_per_day' => number_format((float) $r['horesDia'], 2, '.', ''),
            'cost_per_hour' => number_format((float) $r['costHora'], 2, '.', ''),
        ]);
        $counts['contracts'] = ($counts['contracts'] ?? 0) + 1;
    }
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('workers_monthly')) as $r) {
        $upsert('monthly_hours', [
            'id' => (int) $r['id'],
            'user_id' => (int) $r['userId'],
            'year' => (int) $r['exercici'],
            'month' => (int) $r['mes'],
            'planned_hours' => number_format((float) $r['horesPrevistes'], 2, '.', ''),
            'holiday_hours' => number_format((float) $r['horesVacances'], 2, '.', ''),
        ]);
        $counts['monthly_hours'] = ($counts['monthly_hours'] ?? 0) + 1;
    }

    // ---------------------------------------------------------------- facturación
    $details = [];
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('billing_details') . ' ORDER BY id') as $d) {
        $details[(int) $d['billingId']][] = $d;
    }
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('billing')) as $r) {
        if (!isset($clientIds[(int) $r['clientId']])) {
            $skipped[] = "facturación #{$r['id']}: el cliente #{$r['clientId']} no existe";
            continue;
        }
        $lines = array_map(static fn ($d) => [
            'description' => (string) $d['descripcio'],
            'quantity' => (float) $d['quantitat'],
            'price' => (float) $d['preu'],
            'amount' => (float) $d['import'],
        ], $details[(int) $r['id']] ?? []);
        $upsert('billing', [
            'id' => (int) $r['id'],
            'billed_on' => $date($r['data']) ?? date('Y-m-d'),
            'client_id' => (int) $r['clientId'],
            'project_id' => array_key_exists((int) $r['projectId'], $projects) ? (int) $r['projectId'] : null,
            'invoice_number' => trim((string) $r['facturaFS']) ?: null,
            'status' => trim((string) $r['facturaFS']) !== '' ? 1 : 0,
            'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE),
            'total' => number_format(array_sum(array_column($lines, 'amount')), 2, '.', ''),
        ]);
        foreach ($details[(int) $r['id']] ?? [] as $d) {
            $db->query('UPDATE hours SET billing_id = ? WHERE id = ?', [(int) $r['id'], (int) $d['horesId']]);
        }
        $counts['billing'] = ($counts['billing'] ?? 0) + 1;
    }

    // ---------------------------------------------------------------- carpetas y archivos
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('folders')) as $r) {
        if (!isset($clientIds[(int) $r['clientId']])) {
            $skipped[] = "carpeta #{$r['id']}: el cliente #{$r['clientId']} no existe";
            continue;
        }
        $upsert('folders', ['id' => (int) $r['id'], 'client_id' => (int) $r['clientId'], 'name' => trim((string) $r['nom']) ?: '#' . $r['id']]);
        $counts['folders'] = ($counts['folders'] ?? 0) + 1;
    }
    $folders = [];
    foreach ($db->fetchAll('SELECT id, client_id FROM folders') as $f) {
        $folders[(int) $f['id']] = (int) $f['client_id'];
    }
    foreach ($legacy->fetchAll('SELECT * FROM ' . $t('files')) as $r) {
        if (!isset($folders[(int) $r['folderId']])) {
            $skipped[] = "archivo #{$r['id']}: la carpeta #{$r['folderId']} no existe";
            continue;
        }
        $mediaId = $importFile($filesDir . '/arxius/' . $r['fileName'], (string) $r['fileName'], 'files', 'file', null, null);
        $upsert('files', [
            'id' => (int) $r['id'],
            'client_id' => $folders[(int) $r['folderId']],
            'folder_id' => (int) $r['folderId'],
            'title' => pathinfo((string) $r['fileName'], PATHINFO_FILENAME) ?: '#' . $r['id'],
            'file' => $mediaId,
        ]);
        $counts['files'] = ($counts['files'] ?? 0) + 1;
    }

    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    if ($dryRun) {
        $db->pdo()->rollBack();
    }
} catch (\Throwable $e) {
    if ($dryRun && $db->pdo()->inTransaction()) {
        $db->pdo()->rollBack();
    }
    fwrite(STDERR, 'Error: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
}

echo ($dryRun ? "[simulación, no se ha guardado nada]\n" : '');
foreach ($counts as $what => $n) {
    printf("  ✓ %-14s %d\n", $what, $n);
}
if ($skipped) {
    echo "\nAvisos (" . count($skipped) . "):\n  - " . implode("\n  - ", $skipped) . "\n";
}
