#!/usr/bin/env php
<?php
/**
 * Asteroid · crea o pone al día las tablas propias del proyecto, la columna users.client_id y los grupos
 * "equip" y "client" con sus permisos. Idempotente: se puede ejecutar después de cada actualización.
 *
 *   php bin/install.php --no-demo        (núcleo de Quasar; la primera vez)
 *   php tools/asteroid-migrate.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Quasar\Core\App;
use Quasar\Core\Migrator;

/** Permisos de cada grupo: módulo => acciones */
const GROUP_PERMISSIONS = [
    'equip' => [
        'issues' => ['view', 'create', 'edit', 'delete', 'export'],
        'agenda' => ['view'],
        'pissarra' => ['view'],
        'surveys' => ['view', 'delete', 'export'],
        'pending_billing' => ['view'],
        'telegram' => ['view'],
        'notes' => ['view', 'create', 'edit', 'delete'],
        'wiki' => ['view'],
        'wiki_pages' => ['view', 'create', 'edit', 'delete'],
        'web_audit' => ['view'],
        'clients' => ['view', 'create', 'edit', 'delete', 'export'],
        'projects' => ['view', 'create', 'edit', 'delete', 'export'],
        'client_users' => ['view', 'create', 'edit', 'delete', 'export'],
        'hours' => ['view', 'create', 'edit', 'delete', 'export', 'bill'],
        'contracts' => ['view', 'create', 'edit', 'delete', 'export'],
        'monthly_hours' => ['view', 'create', 'edit', 'delete', 'export'],
        'billing' => ['view', 'create', 'edit', 'delete', 'export'],
        'folders' => ['view', 'create', 'edit', 'delete', 'export'],
        'files' => ['view', 'create', 'edit', 'delete', 'export'],
    ],
    'client' => [
        'client_home' => ['view'],
        'webchat' => ['view', 'edit', 'delete', 'export'],
        'webchat_settings' => ['view'],
        'web_audit' => ['view'],
        'wiki' => ['view'],
        'wiki_pages' => ['view'],                 // solo las de audiencia «Client» de su cliente (scope)
        'issues' => ['view', 'create', 'edit'],
        'folders' => ['view'],
        'files' => ['view'],
    ],
];

try {
    $app = new App($config);
    $db = $app->db;
    $schema = (string) $config['db']['name'];

    // Núcleo primero (por si se ejecuta tras una actualización)
    foreach ((new Migrator($db, $schema))->run() as $step) {
        echo "  ✓ núcleo: $step\n";
    }

    $sql = (string) file_get_contents(dirname(__DIR__) . '/database/asteroid.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    foreach (preg_split('/;\s*$/m', $sql) ?: [] as $statement) {
        if (trim($statement) !== '') {
            $db->pdo()->exec($statement);
        }
    }
    echo "  ✓ database/asteroid.sql\n";

    $hasColumn = (int) $db->fetchValue(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'client_id'",
        [$schema]
    );
    if (!$hasColumn) {
        $db->pdo()->exec('ALTER TABLE users ADD COLUMN client_id INT UNSIGNED NULL AFTER email, ADD KEY ix_users_client (client_id)');
        echo "  ✓ columna users.client_id\n";
    }

    // Chat web: AFI lo activa por cliente (Clients → ficha)
    $hasWebchat = (int) $db->fetchValue(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'webchat'",
        [$schema]
    );
    if (!$hasWebchat) {
        $db->pdo()->exec("ALTER TABLE clients ADD COLUMN webchat TINYINT(1) NOT NULL DEFAULT 0 AFTER active");
        echo "  ✓ columna clients.webchat\n";
    }

    // Informes SEO/GEO (Pulsar): la web del proyecto y si se analiza
    foreach ([
        'website' => "ALTER TABLE projects ADD COLUMN website VARCHAR(255) NULL AFTER api_key",
        'web_audit' => "ALTER TABLE projects ADD COLUMN web_audit TINYINT(1) NOT NULL DEFAULT 0 AFTER website",
    ] as $column => $ddl) {
        $exists = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'projects' AND COLUMN_NAME = ?",
            [$schema, $column]
        );
        if (!$exists) {
            $db->pdo()->exec($ddl);
            echo "  ✓ columna projects.$column\n";
        }
    }

    $groups = [
        'equip' => ['Equip AFI', 'Treballadors: gestionen incidències, hores, clients i facturació'],
        'client' => ['Clients', 'Usuaris dels clients: veuen les seves incidències i carpetes'],
    ];
    foreach ($groups as $code => [$name, $description]) {
        $id = $db->fetchValue('SELECT id FROM user_groups WHERE code = ?', [$code]);
        if ($id === null) {
            $id = $db->insert('user_groups', ['code' => $code, 'name' => $name, 'description' => $description]);
            echo "  ✓ grupo $code\n";
        }
        foreach (GROUP_PERMISSIONS[$code] as $module => $actions) {
            foreach ($actions as $action) {
                $db->query('INSERT IGNORE INTO group_permissions (group_id, module, action) VALUES (?, ?, ?)', [(int) $id, $module, $action]);
            }
        }
    }
    echo "  ✓ permisos de los grupos\n\nListo.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
