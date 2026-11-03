<?php
/**
 * Hook de Claude Code (PreToolUse: Edit|Write|NotebookEdit): Asteroid es una instalación de Quasar, así que no se
 * editan aquí los ficheros del núcleo (los de core.json). Esos cambios se hacen en ~/Projects/quasar, se publica una
 * versión y Asteroid se actualiza desde ella; si no, la siguiente actualización los pisa.
 */
declare(strict_types=1);

$input = json_decode((string) stream_get_contents(STDIN), true);
$file = (string) ($input['tool_input']['file_path'] ?? $input['tool_input']['notebook_path'] ?? '');
$root = rtrim((string) (getenv('CLAUDE_PROJECT_DIR') ?: dirname(__DIR__, 2)), '/');
if ($file === '' || !str_starts_with($file, $root . '/')) {
    exit(0);
}
$rel = substr($file, strlen($root) + 1);

// Las plantillas de las páginas propias (p. ej. pages/pissarra.php) tienen que vivir en templates/default/pages/:
// son de Asteroid mientras Quasar no tenga una con ese nombre
$quasar = (getenv('HOME') ?: '') . '/Projects/quasar';
if (preg_match('#^templates/default/pages/[^/]+\.php$#', $rel) && is_dir($quasar) && !is_file($quasar . '/' . $rel)) {
    exit(0);
}

$core = json_decode((string) @file_get_contents($root . '/core.json'), true)['core'] ?? [];
foreach ($core as $pattern) {
    // Misma sintaxis que src/Remote/CoreFiles: ** = cualquier subcarpeta, * = dentro de una carpeta
    $re = '#^' . str_replace(['\*\*', '\*'], ['.*', '[^/]*'], preg_quote((string) $pattern, '#')) . '$#';
    if (preg_match($re, $rel)) {
        echo json_encode(['hookSpecificOutput' => [
            'hookEventName' => 'PreToolUse',
            'permissionDecision' => 'deny',
            'permissionDecisionReason' => "«{$rel}» es del núcleo de Quasar (core.json): en Asteroid no se toca. "
                . 'Hazlo en ~/Projects/quasar, publica una versión y actualiza Asteroid copiando su núcleo; '
                . 'o resuélvelo con un módulo, hook, página o texto propio de Asteroid.',
        ]], JSON_UNESCAPED_UNICODE);
        exit(0);
    }
}
exit(0);
