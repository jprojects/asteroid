<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\HtmlSanitizer;
use Quasar\Core\HttpException;
use Quasar\Hooks\Asteroid;

/**
 * Asteroid · «Wiki» (modules/wiki.json): lectura de la documentación de los proyectos (las páginas son el módulo
 * wiki_pages). Lo que ve cada uno lo decide el "scope" de wiki_pages: el equipo, todas; el cliente, solo las de
 * audiencia «Client» de sus proyectos. A la izquierda, proyectos, secciones y páginas; a la derecha, la página.
 *
 *   index.php?r=page&m=wiki[&project=ID][&page=ID][&q=texto]
 */
final class WikiPage extends Page
{
    private const SEARCH_LIMIT = 30;

    public function index(): void
    {
        $pages = $this->otherModule('wiki_pages');
        if (!$this->allowed('view', $pages)) {
            throw new HttpException(403);
        }
        [$where, $bind] = $pages->rowCondition() ?? ['1 = 1', []];
        $db = $this->app->db;
        $client = Asteroid::isClient($this->user());

        $pageId = ctype_digit((string) $this->param('page', '')) ? (int) $this->param('page') : null;
        $projectId = ctype_digit((string) $this->param('project', '')) ? (int) $this->param('project') : null;
        $q = trim((string) $this->param('q', ''));

        $current = null;
        if ($pageId !== null) {
            $current = $db->fetch(
                "SELECT wiki_pages.*, u.name AS author FROM wiki_pages LEFT JOIN users u ON u.id = wiki_pages.updated_by
                  WHERE wiki_pages.id = ? AND ($where)",
                [$pageId, ...$bind]
            ) ?? throw new HttpException(404);
            $projectId = (int) $current['project_id'];
        }

        $projects = $db->fetchAll(
            "SELECT p.id, p.title, c.name AS client, COUNT(*) AS pages FROM wiki_pages
               JOIN projects p ON p.id = wiki_pages.project_id LEFT JOIN clients c ON c.id = p.client_id
              WHERE ($where) GROUP BY p.id, p.title, c.name ORDER BY c.name, p.title",
            $bind
        );
        if ($projectId === null && $q === '' && count($projects) === 1) {
            $projectId = (int) $projects[0]['id'];
        }

        $project = null;
        $nav = [];
        if ($projectId !== null) {
            $project = $db->fetch(
                'SELECT p.id, p.title, p.client_id, c.name AS client FROM projects p LEFT JOIN clients c ON c.id = p.client_id WHERE p.id = ?',
                [$projectId]
            );
            if ($project === null || ($client && (int) $project['client_id'] !== (int) $this->user()->get('client_id'))) {
                throw new HttpException(404);
            }
            $rows = $db->fetchAll(
                "SELECT wiki_pages.id, wiki_pages.title, wiki_pages.section, wiki_pages.audience FROM wiki_pages
                  WHERE wiki_pages.project_id = ? AND ($where)
                  ORDER BY wiki_pages.section = '', wiki_pages.section, wiki_pages.ordering, wiki_pages.title",
                [$projectId, ...$bind]
            );
            foreach ($rows as $r) {
                $nav[(string) $r['section']][] = $r + ['url' => $this->pageUrl('index', ['page' => $r['id']])];
            }
            if ($current === null && $rows && $q === '') {
                $current = $db->fetch(
                    'SELECT wiki_pages.*, u.name AS author FROM wiki_pages LEFT JOIN users u ON u.id = wiki_pages.updated_by WHERE wiki_pages.id = ?',
                    [(int) $rows[0]['id']]
                );
            }
        }

        $results = null;
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $results = array_map(fn (array $r) => [
                'title' => (string) $r['title'],
                'project' => (string) $r['project'],
                'excerpt' => mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', HtmlSanitizer::toText((string) $r['body']))), 0, 180, '…'),
                'url' => $this->pageUrl('index', ['page' => $r['id']]),
            ], $db->fetchAll(
                "SELECT wiki_pages.id, wiki_pages.title, wiki_pages.body, p.title AS project FROM wiki_pages
                   JOIN projects p ON p.id = wiki_pages.project_id
                  WHERE ($where) AND (wiki_pages.title LIKE ? OR wiki_pages.body LIKE ? OR wiki_pages.section LIKE ?)"
                . ($projectId !== null ? ' AND wiki_pages.project_id = ' . $projectId : '') . '
                  ORDER BY wiki_pages.title LIKE ? DESC, wiki_pages.updated_at DESC LIMIT ' . self::SEARCH_LIMIT,
                [...$bind, $like, $like, $like, $like]
            ));
        }

        $canCreate = $this->allowed('create', $pages);
        $canEdit = $this->allowed('edit', $pages);
        $this->view([
            'projects' => array_map(fn (array $p) => $p + ['url' => $this->pageUrl('index', ['project' => $p['id']])], $projects),
            'project' => $project,
            'nav' => $nav,
            'current' => $current,
            'q' => $q,
            'results' => $results,
            'isClient' => $client,
            'searchAction' => $this->pageUrl(),
            'newUrl' => $canCreate ? $this->app->url('edit', ['m' => 'wiki_pages'] + ($project ? ['v' => ['project_id' => $project['id']]] : [])) : null,
            'editUrl' => $canEdit && $current ? $this->app->url('edit', ['m' => 'wiki_pages', 'id' => $current['id']]) : null,
            'manageUrl' => $canEdit ? $pages->url() : null,
            'homeUrl' => $this->pageUrl(),
        ]);
    }
}
