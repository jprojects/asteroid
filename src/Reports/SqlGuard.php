<?php
declare(strict_types=1);

namespace Quasar\Reports;

/**
 * Comprueba que una consulta generada por la IA es una sola SELECT que solo usa las tablas
 * permitidas. Es conservador: ante la duda, rechaza (la IA puede reformular).
 *
 * No es la única barrera: la consulta se ejecuta además en una transacción de solo lectura,
 * con límite de tiempo y, si se configura 'reports.db', con un usuario de MySQL de solo lectura.
 */
final class SqlGuard
{
    /** Palabras que una consulta de lectura nunca necesita. */
    private const FORBIDDEN = [
        'insert', 'update', 'delete', 'replace', 'drop', 'alter', 'create', 'truncate', 'rename', 'grant', 'revoke',
        'into', 'outfile', 'dumpfile', 'load', 'load_file', 'handler', 'call', 'do', 'set', 'lock', 'unlock',
        'sleep', 'benchmark', 'get_lock', 'release_lock', 'release_all_locks', 'is_free_lock', 'is_used_lock',
        'procedure', 'execute', 'prepare', 'deallocate', 'shutdown', 'kill', 'flush', 'reset', 'purge', 'show',
        'describe', 'explain', 'analyze', 'optimize', 'repair', 'install', 'uninstall', 'use', 'begin', 'commit',
        'rollback', 'savepoint', 'start', 'xa', 'signal', 'resignal', 'declare', 'cache', 'index', 'trigger',
        'event', 'user', 'password', 'sys_exec', 'sys_eval', 'master_pos_wait', 'source_pos_wait',
    ];
    /** Esquemas del servidor que nunca se consultan. */
    private const SYSTEM_SCHEMAS = ['information_schema', 'mysql', 'performance_schema', 'sys'];
    /** Tablas del núcleo que nunca se exponen aunque se pongan en la configuración (secretos). */
    public const NEVER = ['users', 'user_trusted_devices', 'ai_cache'];

    /**
     * @param list<string> $allowed tablas permitidas
     * @param list<string> $allTables todas las tablas y vistas de la BD
     * @param list<string> $schemas bases de datos visibles para el usuario de MySQL
     */
    public function __construct(
        private readonly array $allowed,
        private readonly array $allTables,
        private readonly array $schemas,
    ) {
    }

    /** @return string|null motivo del rechazo, o null si la consulta es aceptable */
    public function check(string $sql): ?string
    {
        $sql = trim($sql);
        if ($sql === '' || strlen($sql) > 20000) {
            return 'consulta vacía o demasiado larga';
        }
        if (preg_match('~/\*|\*/|--|#~', self::stripStrings($sql))) {
            return 'no se admiten comentarios';
        }
        $code = self::stripStrings($sql);
        if (str_contains($code, ';')) {
            return 'solo se admite una sentencia';
        }
        if (str_contains($code, '@')) {
            return 'no se admiten variables';
        }
        if (!preg_match('/^\s*\(?\s*(select|with)\b/i', $code)) {
            return 'solo se admiten consultas SELECT';
        }

        $allowed = array_map('strtolower', array_diff($this->allowed, self::NEVER));
        $tables = array_map('strtolower', $this->allTables);
        $schemas = array_map('strtolower', array_merge(self::SYSTEM_SCHEMAS, $this->schemas));
        foreach (self::identifiers($sql) as $word) {
            $w = strtolower($word);
            if (in_array($w, self::FORBIDDEN, true)) {
                return "palabra no permitida: $word";
            }
            if (in_array($w, $schemas, true)) {
                return "no se puede consultar otra base de datos ($word)";
            }
            if (in_array($w, $tables, true) && !in_array($w, $allowed, true)) {
                return "la tabla $word no está permitida en los informes";
            }
        }
        return null;
    }

    /** Sustituye los textos entre comillas por '' (su contenido no cuenta como código). */
    private static function stripStrings(string $sql): string
    {
        return (string) preg_replace(["/'(?:[^'\\\\]|\\\\.|'')*'/s", '/"(?:[^"\\\\]|\\\\.|"")*"/s'], "''", $sql);
    }

    /**
     * Palabras e identificadores de la consulta (fuera de los textos), incluidos los entre `comillas`.
     * @return list<string>
     */
    private static function identifiers(string $sql): array
    {
        $code = self::stripStrings($sql);
        preg_match_all('/`((?:[^`]|``)+)`|([A-Za-z_][A-Za-z0-9_$]*)/', $code, $m, PREG_SET_ORDER);
        return array_map(static fn ($x) => isset($x[2]) && $x[2] !== '' ? $x[2] : str_replace('``', '`', $x[1]), $m);
    }
}
