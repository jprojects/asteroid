<?php
declare(strict_types=1);

namespace Quasar\WebAudit;

use Quasar\Core\Database;
use Quasar\Core\Log;

/**
 * Asteroid · informes SEO/GEO de las webs de los proyectos, con la API de Pulsar (~/Projects/pulsar, api.php).
 * Configuración en config.local.php:
 *
 *   'pulsar' => [
 *       'url'   => 'https://pulsar.aficat.com/',   // donde está Pulsar (se llama a api.php)
 *       'token' => '…',                            // el de 'api_tokens' en el config.local.php de Pulsar
 *       'days'  => 7,                              // cada cuántos días se vuelve a analizar (cron)
 *   ],
 *
 * Se analizan los proyectos activos con «Informe SEO/GEO» marcado y una web (projects.web_audit, projects.website).
 * Cada análisis queda en web_audits (historial: se compara con el anterior).
 */
final class WebAudit
{
    public const KINDS = ['seo', 'geo'];
    private const TIMEOUT = 150;

    public static function config(array $appConfig): array
    {
        return (array) ($appConfig['pulsar'] ?? []);
    }

    public static function enabled(array $appConfig): bool
    {
        $c = self::config($appConfig);
        return trim((string) ($c['url'] ?? '')) !== '' && trim((string) ($c['token'] ?? '')) !== '';
    }

    /** "https://www.client.cat/" → "www.client.cat/" (el WAF de Webempresa da 403 con URL en los parámetros) */
    public static function site(string $website): string
    {
        $site = trim((string) preg_replace('#^https?://#i', '', trim($website)));
        return $site !== '' && !str_contains($site, '/') ? $site . '/' : $site;
    }

    /** Para mostrar: "www.client.cat/" → "www.client.cat" (sin la barra final de la portada). */
    public static function label(string $website): string
    {
        return rtrim(self::site($website), '/');
    }

    /** Analiza la web del proyecto (SEO y GEO) y guarda los dos informes. @return list<array> filas de web_audits */
    public static function run(Database $db, array $appConfig, array $project): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $out[] = self::analyze($db, $appConfig, $project, $kind);
        }
        return $out;
    }

    public static function analyze(Database $db, array $appConfig, array $project, string $kind): array
    {
        $site = self::site((string) $project['website']);
        $row = ['project_id' => (int) $project['id'], 'kind' => $kind, 'url' => mb_substr($site, 0, 255)];
        try {
            $data = self::call($appConfig, ['site' => $site, 'type' => $kind]);
            if (empty($data['ok'])) {
                throw new \RuntimeException('Pulsar: ' . (string) ($data['error'] ?? 'respuesta no válida'));
            }
            $row += [
                // Si la página no responde, Pulsar da 0 y un error: se guarda como fallo, no como nota
                'score' => $data['error'] ? null : max(0, min(100, (int) $data['score'])),
                'categories' => json_encode($data['categories'] ?? [], JSON_UNESCAPED_UNICODE),
                'issues' => json_encode($data['issues'] ?? [], JSON_UNESCAPED_UNICODE),
                'passed' => (int) ($data['passed'] ?? 0),
                'total' => (int) ($data['total'] ?? 0),
                'report_id' => isset($data['report_id']) ? mb_substr((string) $data['report_id'], 0, 32) : null,
                'error' => $data['error'] ? mb_substr((string) $data['error'], 0, 255) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('Informe {kind} del proyecto {project}: {error}', ['kind' => $kind, 'project' => $project['id'], 'error' => $e->getMessage()]);
            $row += ['score' => null, 'error' => mb_substr($e->getMessage(), 0, 255)];
        }
        $row['id'] = (int) $db->insert('web_audits', $row);
        return $row;
    }

    /** POST JSON a api.php de Pulsar. @return array<string,mixed> */
    private static function call(array $appConfig, array $body): array
    {
        $c = self::config($appConfig);
        $url = rtrim((string) $c['url'], '/');
        $url = str_ends_with($url, '.php') ? $url : $url . '/api.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            // Las dos cabeceras: algunos Apache (Webempresa) no pasan Authorization a PHP
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . (string) $c['token'], 'X-Pulsar-Token: ' . (string) $c['token']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? curl_error($ch) : null;
        if ($raw === false) {
            throw new \RuntimeException('Pulsar no responde: ' . $error);
        }
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Pulsar ha respondido $status sin JSON");
        }
        return $data;
    }

    /** Proyectos que tocan (marcados, con web y sin análisis en los últimos N días). @return list<array> */
    public static function due(Database $db, array $appConfig, bool $all = false): array
    {
        $days = max(1, (int) (self::config($appConfig)['days'] ?? 7));
        return $db->fetchAll(
            "SELECT p.* FROM projects p
              WHERE p.active = 1 AND p.web_audit = 1 AND COALESCE(p.website, '') <> ''" . ($all ? '' : '
                AND NOT EXISTS (SELECT 1 FROM web_audits a WHERE a.project_id = p.id AND a.created_at > ?)') . '
              ORDER BY p.id',
            $all ? [] : [date('Y-m-d H:i:s', time() - $days * 86400 + 3600)]
        );
    }

    /**
     * Los dos últimos informes de cada tipo de un proyecto (el actual y el anterior, para la tendencia).
     * @return array<string,array{current:?array,previous:?array}>
     */
    public static function latest(Database $db, int $projectId): array
    {
        $out = [];
        foreach (self::KINDS as $kind) {
            $rows = $db->fetchAll(
                'SELECT * FROM web_audits WHERE project_id = ? AND kind = ? AND score IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT 2',
                [$projectId, $kind]
            );
            $rows = array_map([self::class, 'decode'], $rows);
            $out[$kind] = ['current' => $rows[0] ?? null, 'previous' => $rows[1] ?? null];
        }
        return $out;
    }

    /** El último análisis (aunque haya fallado), para avisar al equipo. */
    public static function lastAttempt(Database $db, int $projectId): ?array
    {
        $row = $db->fetch('SELECT * FROM web_audits WHERE project_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$projectId]);
        return $row !== null ? self::decode($row) : null;
    }

    public static function decode(array $row): array
    {
        $row['categories'] = json_decode((string) ($row['categories'] ?? ''), true) ?: [];
        $row['issues'] = json_decode((string) ($row['issues'] ?? ''), true) ?: [];
        return $row;
    }

    /** green ≥ 80 · orange ≥ 50 · red */
    public static function tone(?int $score): string
    {
        return $score === null ? 'gray' : ($score >= 80 ? 'green' : ($score >= 50 ? 'orange' : 'red'));
    }

    /** Enlace al informe en Pulsar (pide la sesión de Pulsar; para el equipo). */
    public static function reportUrl(array $appConfig, array $audit, bool $pdf = false): ?string
    {
        $base = rtrim((string) (self::config($appConfig)['url'] ?? ''), '/');
        $base = (string) preg_replace('#/api\.php$#', '', $base);
        if ($base === '' || empty($audit['report_id'])) {
            return null;
        }
        $id = rawurlencode((string) $audit['report_id']);
        return $pdf ? "$base/report.php?type={$audit['kind']}&id=$id" : "$base/{$audit['kind']}.php?id=$id";
    }
}
