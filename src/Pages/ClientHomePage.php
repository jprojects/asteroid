<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\HtmlSanitizer;
use Quasar\Hooks\Asteroid;
use Quasar\WebAudit\WebAudit;

/**
 * Asteroid · página de inicio de los usuarios de los clientes (modules/client_home.json; con 'home' => 'client_home' en
 * config.local.php es su página al entrar): sus incidencias abiertas, los mensajes sin leer de sus conversaciones,
 * las resueltas hace poco y los documentos nuevos de sus carpetas. Todo pasa por el "scope" de cada módulo: el usuario
 * solo ve lo suyo. Quien no es de un cliente (equipo, administradores) va al inicio de siempre.
 */
final class ClientHomePage extends Page
{
    private const OPEN_LIMIT = 15;
    private const UNREAD_LIMIT = 8;
    private const RESOLVED_DAYS = 30;
    private const FILES_LIMIT = 8;

    public function index(): void
    {
        if (!Asteroid::isClient($this->user())) {
            $this->app->redirect($this->app->url('home', ['menu' => 1]));
        }
        $this->view($this->data() + [
            'title' => $this->text('title'),
            'subtitle' => $this->text('welcome', ['name' => $this->user()->name()]),
            'newIssueUrl' => $this->allowed('create', $this->otherModule('issues')) ? $this->app->url('edit', ['m' => 'issues']) : null,
            'issuesUrl' => $this->otherModule('issues')->url(),
            'filesUrl' => $this->allowed('view', $this->otherModule('files')) ? $this->otherModule('files')->url() : null,
            'web' => $this->webHealth(),
        ]);
    }

    /** Lo mismo para una app (GET /api/v1/pages/client_home). */
    public function api(): ?array
    {
        $data = $this->data();
        $strip = static fn (array $rows) => array_map(static fn ($r) => array_diff_key($r, ['url' => 1, 'download' => 1]), $rows);
        return [
            'open' => $strip($data['open']),
            'unread' => $strip($data['unread']),
            'resolved' => $strip($data['resolved']),
            'files' => $strip($data['files']),
        ];
    }

    private function data(): array
    {
        $issues = $this->otherModule('issues');
        if (!$this->allowed('view', $issues)) {
            return ['open' => [], 'openTotal' => 0, 'unread' => [], 'resolved' => [], 'files' => []];
        }
        [$where, $bind] = $issues->rowCondition() ?? ['1 = 1', []];
        $db = $this->app->db;

        $openTotal = (int) $db->fetchValue(
            "SELECT COUNT(*) FROM issues WHERE ($where) AND issues.status IN (" . Asteroid::STATUS_PENDING . ', ' . Asteroid::STATUS_IN_PROGRESS . ')',
            $bind
        );
        $open = array_map(fn (array $r) => $this->issueRow($r), $db->fetchAll(
            "SELECT issues.id, issues.title, issues.status, issues.priority, issues.created_at, p.title AS project
               FROM issues LEFT JOIN projects p ON p.id = issues.project_id
              WHERE ($where) AND issues.status IN (" . Asteroid::STATUS_PENDING . ', ' . Asteroid::STATUS_IN_PROGRESS . ')
              ORDER BY issues.priority DESC, issues.id DESC LIMIT ' . self::OPEN_LIMIT,
            $bind
        ));

        $resolved = array_map(fn (array $r) => $this->issueRow($r), $db->fetchAll(
            "SELECT issues.id, issues.title, issues.status, issues.priority, issues.resolved_at, p.title AS project
               FROM issues LEFT JOIN projects p ON p.id = issues.project_id
              WHERE ($where) AND issues.status = " . Asteroid::STATUS_RESOLVED . ' AND issues.resolved_at >= ?
              ORDER BY issues.resolved_at DESC LIMIT 5',
            [...$bind, date('Y-m-d H:i:s', time() - self::RESOLVED_DAYS * 86400)]
        ));

        // Conversaciones con mensajes que no ha leído (de otros; los apuntes del sistema no cuentan)
        $unread = [];
        if ($issues->chatAllows($this->user())) {
            $me = $this->user()->id();
            $rows = $db->fetchAll(
                "SELECT m.record_id, COUNT(*) AS unread, MAX(m.id) AS last_id
                   FROM messages m
                   LEFT JOIN message_reads r ON r.user_id = ? AND r.module = 'issues' AND r.record_id = m.record_id
                  WHERE m.module = 'issues' AND m.deleted_at IS NULL AND m.kind <> 'system'
                    AND (m.user_id IS NULL OR m.user_id <> ?) AND m.id > COALESCE(r.last_read_id, 0)
                    AND m.record_id IN (SELECT CAST(issues.id AS CHAR) COLLATE utf8mb4_unicode_ci FROM issues WHERE ($where))
                  GROUP BY m.record_id ORDER BY last_id DESC LIMIT " . self::UNREAD_LIMIT,
                [$me, $me, ...$bind]
            );
            foreach ($rows as $r) {
                $last = $db->fetch(
                    'SELECT m.body, m.kind, m.created_at, u.name AS author, i.title FROM messages m
                       LEFT JOIN users u ON u.id = m.user_id JOIN issues i ON i.id = ? WHERE m.id = ?',
                    [(int) $r['record_id'], (int) $r['last_id']]
                );
                $body = $last['kind'] === 'meet' ? $this->text('meet') : HtmlSanitizer::toText((string) ($last['body'] ?? ''));
                $unread[] = [
                    'id' => (int) $r['record_id'],
                    'title' => (string) ($last['title'] ?? ''),
                    'unread' => (int) $r['unread'],
                    'author' => (string) ($last['author'] ?? ''),
                    'excerpt' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', $body) ?? ''), 0, 140, '…'),
                    'at' => (string) ($last['created_at'] ?? ''),
                    'url' => $this->app->url('edit', ['m' => 'issues', 'id' => $r['record_id']]) . '#chat',
                ];
            }
        }

        // Documentos más recientes de sus carpetas
        $files = [];
        $filesModule = $this->otherModule('files');
        if ($this->allowed('view', $filesModule)) {
            [$fWhere, $fBind] = $filesModule->rowCondition() ?? ['1 = 1', []];
            foreach ($db->fetchAll(
                "SELECT files.id, files.title, files.file, files.created_at, fo.name AS folder, m.original_name, m.size
                   FROM files JOIN folders fo ON fo.id = files.folder_id LEFT JOIN media m ON m.id = files.file
                  WHERE ($fWhere) ORDER BY files.created_at DESC, files.id DESC LIMIT " . self::FILES_LIMIT,
                $fBind
            ) as $r) {
                $files[] = [
                    'id' => (int) $r['id'],
                    'title' => (string) $r['title'],
                    'folder' => (string) $r['folder'],
                    'name' => (string) ($r['original_name'] ?? ''),
                    'ext' => strtolower(pathinfo((string) ($r['original_name'] ?? ''), PATHINFO_EXTENSION)),
                    'at' => (string) $r['created_at'],
                    'url' => $this->app->url('edit', ['m' => 'files', 'id' => $r['id']]),
                    'download' => $r['file'] !== null ? $this->app->url('media', ['id' => $r['file'], 'download' => 1]) : null,
                ];
            }
        }

        return ['open' => $open, 'openTotal' => $openTotal, 'unread' => $unread, 'resolved' => $resolved, 'files' => $files];
    }

    /** Las webs del cliente con informe SEO/GEO (Salut de la web), con la última nota de cada tipo. @return list<array> */
    private function webHealth(): array
    {
        $m = $this->app->modules()->get('web_audit');
        if ($m === null || !$this->allowed('view', $m)) {
            return [];
        }
        $out = [];
        foreach ($this->app->db->fetchAll(
            "SELECT id, title, website FROM projects WHERE client_id = ? AND active = 1 AND web_audit = 1 AND COALESCE(website, '') <> '' ORDER BY title",
            [(int) $this->user()->get('client_id')]
        ) as $p) {
            $audits = WebAudit::latest($this->app->db, (int) $p['id']);
            $out[] = [
                'title' => (string) $p['title'],
                'site' => WebAudit::label((string) $p['website']),
                'seo' => isset($audits['seo']['current']) ? (int) $audits['seo']['current']['score'] : null,
                'geo' => isset($audits['geo']['current']) ? (int) $audits['geo']['current']['score'] : null,
                'issues' => count($audits['seo']['current']['issues'] ?? []) + count($audits['geo']['current']['issues'] ?? []),
                'url' => $m->url(['project' => $p['id']]),
            ];
        }
        return $out;
    }

    private function issueRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'project' => (string) ($r['project'] ?? ''),
            'status' => (int) $r['status'],
            'priority' => (int) $r['priority'],
            'at' => (string) ($r['created_at'] ?? $r['resolved_at'] ?? ''),
            'url' => $this->app->url('edit', ['m' => 'issues', 'id' => $r['id']]),
        ];
    }
}
