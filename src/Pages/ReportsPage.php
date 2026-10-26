<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Ai\LlmException;
use Quasar\Ai\LlmFactory;
use Quasar\Ai\Usage;
use Quasar\Controllers\Page;
use Quasar\Core\Database;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Export\PdfTableReport;
use Quasar\Export\XlsxWriter;
use Quasar\Reports\Report;
use Quasar\Reports\ReportAi;
use Quasar\Reports\ReportRunner;
use Quasar\Reports\ReportSchema;

/**
 * Informes con lenguaje natural (modules/reports.json, config 'reports').
 *
 * El usuario describe los datos que quiere; la IA escribe una consulta SELECT sobre las tablas
 * permitidas; Quasar la valida (SqlGuard), la ejecuta en solo lectura (ReportRunner) y muestra el
 * resultado como una tabla con paginación, orden y Excel/PDF. El navegador nunca envía SQL: solo el
 * id del informe, que se lee de la tabla reports.
 *
 * Permisos de la página: view (ver informes guardados), ask (pedir informes nuevos a la IA),
 * save (guardar con nombre y borrar), export (Excel y PDF).
 */
final class ReportsPage extends Page
{
    private const PAGE_SIZES = [25, 50, 100];
    private const MAX_PHRASE = 1000;

    public function index(): void
    {
        $user = $this->user();
        $report = null;
        if (($id = $this->param('id')) !== null) {
            $report = $this->load($id);
        }
        $this->view([
            'canAsk' => $this->canAsk(),
            'canSave' => $this->allowed('save'),
            'canExport' => $this->allowed('export'),
            'report' => $report,
            'isOwner' => $report !== null && ($report->userId === $user->id() || $user->isAdmin()),
            'saved' => $this->app->db->fetchAll('SELECT id, name, title FROM reports WHERE name IS NOT NULL ORDER BY name'),
            'recent' => $this->app->db->fetchAll(
                'SELECT id, title, created_at FROM reports WHERE user_id = ? AND name IS NULL ORDER BY id DESC LIMIT 10',
                [$user->id()]
            ),
            'askUrl' => $this->pageUrl('ask'),
            'table' => $report !== null ? $this->tableConfig($report) : null,
        ]);
    }

    /** POST q = petición → {ok, url} con el informe nuevo, {ok: false, note} o {error}. */
    public function actionAsk(): void
    {
        $this->requirePost();
        if (!$this->canAsk()) {
            throw new HttpException(403);
        }
        $phrase = trim((string) preg_replace('/\s+/u', ' ', (string) $this->param('q', '')));
        if ($phrase === '' || mb_strlen($phrase) > self::MAX_PHRASE) {
            throw new HttpException(400);
        }
        $user = $this->user();
        $ai = (array) ($this->app->config['ai'] ?? []);
        $usage = new Usage($this->app->db, $ai);
        if ($usage->state()['state'] === 'blocked') {
            $this->json(['error' => $this->t('ai.limit_reached')], 429);
            return;
        }
        if ($usage->userOverRate($user->id())) {
            $this->json(['error' => $this->t('ai.too_many')], 429);
            return;
        }
        // La misma petición del mismo usuario hoy: se reutiliza (las fechas relativas van en el SQL)
        $same = $this->app->db->fetchValue(
            'SELECT id FROM reports WHERE user_id = ? AND prompt = ? AND created_at >= CURDATE() ORDER BY id DESC LIMIT 1',
            [$user->id(), $phrase]
        );
        if ($same !== null) {
            $this->json(['ok' => true, 'url' => $this->pageUrl('index', ['id' => $same])]);
            return;
        }

        $cfg = $this->reportsConfig();
        $db = $this->reportsDb();
        $schema = new ReportSchema($db, $cfg, $this->app->modules());
        $guard = $schema->guard();
        $runner = new ReportRunner($db, max(1, (int) ($cfg['timeout'] ?? 15)), max(1, (int) ($cfg['max_rows'] ?? 10000)));
        $prompt = new ReportAi($schema, $this->app->lang->locale());
        $model = (string) ($cfg['model'] ?? $ai['model'] ?? '');
        $provider = LlmFactory::make(['model' => $model, 'effort' => $cfg['effort'] ?? 'medium'] + $ai);
        $system = $prompt->system();
        $log = ['user_id' => $user->id(), 'module' => $this->module->name, 'provider' => $provider->name(), 'model' => $model, 'prompt' => $phrase];

        $retry = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $start = hrtime(true);
            try {
                $response = $provider->complete($system, $prompt->userMessage($phrase, new \DateTimeImmutable('now'), $retry), $prompt->responseSchema());
            } catch (LlmException $e) {
                Log::error('Informes IA: {error}', ['error' => $e->getMessage()]);
                $usage->log($log + ['status' => 'error', 'result' => $e->getMessage(), 'duration_ms' => self::ms($start)], $e->usage);
                $this->json(['error' => $this->t('ai.error')], 502);
                return;
            }
            $data = $response->data ?? ['understood' => false, 'note' => null];
            $sql = trim((string) ($data['sql'] ?? ''));
            if (empty($data['understood']) || $sql === '') {
                $note = is_string($data['note'] ?? null) && $data['note'] !== '' ? $data['note'] : $this->text('not_understood');
                $usage->log($log + ['status' => 'not_understood', 'result' => $note, 'duration_ms' => self::ms($start)], $response);
                $this->json(['ok' => false, 'note' => $note]);
                return;
            }
            // Comprobación de seguridad y prueba real (columnas) antes de guardar nada
            $error = $guard->check($sql);
            $columns = null;
            if ($error === null) {
                try {
                    $columns = Report::mergeColumns($runner->columns($sql), (array) ($data['columns'] ?? []));
                    if (count(array_unique(array_column($columns, 'name'))) !== count($columns)) {
                        $error = 'hay columnas con el mismo nombre: pon un alias distinto a cada una';
                    }
                } catch (\PDOException $e) {
                    $error = $e->getMessage();
                }
            }
            $usage->log($log + [
                'status' => $error === null ? 'ok' : 'error',
                'result' => $sql . ($error !== null ? "\n-- $error" : ''),
                'duration_ms' => self::ms($start),
            ], $response);
            if ($error !== null) {
                Log::notice('Informes IA: consulta rechazada ({error})', ['error' => $error, 'sql' => $sql, 'attempt' => $attempt]);
                $retry = ['sql' => $sql, 'error' => $error];
                continue;
            }

            $sort = array_values(array_filter((array) ($data['sort'] ?? []), static fn ($s) => is_array($s) && in_array($s['column'] ?? null, array_column($columns, 'name'), true)));
            $limit = is_int($data['limit'] ?? null) && $data['limit'] > 0 ? $data['limit'] : null;
            $explanation = trim((string) ($data['explanation'] ?? ''));
            if (is_string($data['note'] ?? null) && trim($data['note']) !== '') {
                $explanation = trim($explanation . ' ' . trim($data['note']));
            }
            $id = $this->app->db->insert('reports', [
                'user_id' => $user->id(),
                'prompt' => $phrase,
                'title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: $phrase, 0, 200),
                'explanation' => $explanation,
                'query' => $sql,
                'columns' => json_encode($columns, JSON_UNESCAPED_UNICODE),
                'sort' => json_encode($sort, JSON_UNESCAPED_UNICODE),
                'row_limit' => $limit,
            ]);
            Log::info('Informes IA: informe {id} creado', ['id' => $id]);
            $this->json(['ok' => true, 'url' => $this->pageUrl('index', ['id' => $id])]);
            return;
        }
        $this->json(['ok' => false, 'note' => $this->text('failed')]);
    }

    /** GET id, page, size, sort (c0, c1…), dir → filas en el formato de la tabla de Quasar. */
    public function actionData(): void
    {
        $report = $this->load((string) $this->param('id', '')) ?? throw new HttpException(404);
        $runner = $this->runner();
        $size = (int) $this->param('size', '25');
        $size = in_array($size, self::PAGE_SIZES, true) ? $size : self::PAGE_SIZES[0];
        [$sortKey, $dir, $order] = $this->userOrder($report);
        try {
            $total = $runner->count($report->query, $report->limit);
            $pages = max(1, (int) ceil($total / $size));
            $page = min(max(1, (int) $this->param('page', '1')), $pages);
            $rows = $runner->rows($report->query, $report->baseOrder(), $report->limit, $order, $size, ($page - 1) * $size);
        } catch (\PDOException $e) {
            Log::warning('Informes: el informe {id} no se puede ejecutar: {error}', ['id' => $report->id, 'error' => $e->getMessage()]);
            $this->json(['error' => $this->text('run_error')], 500);
            return;
        }
        $t = fn (string $k) => $this->t($k);
        $out = [];
        foreach ($rows as $n => $row) {
            $cells = [];
            foreach ($row as $i => $v) {
                $cells["c$i"] = $report->format($i, $v, $t);
            }
            $out[] = ['id' => (string) (($page - 1) * $size + $n + 1), 'cells' => $cells];
        }
        $this->json(['rows' => $out, 'total' => $total, 'page' => $page, 'pages' => $pages, 'size' => $size, 'sort' => $sortKey, 'dir' => $dir]);
    }

    /** GET id, format = xlsx|pdf, sort, dir → descarga con todas las filas (hasta 'max_rows'). */
    public function actionExport(): void
    {
        if (!$this->allowed('export')) {
            throw new HttpException(403);
        }
        $report = $this->load((string) $this->param('id', '')) ?? throw new HttpException(404);
        $format = (string) $this->param('format', '');
        if (!in_array($format, ['xlsx', 'pdf'], true)) {
            throw new HttpException(404);
        }
        @set_time_limit(300);
        [, , $order] = $this->userOrder($report);
        $max = max(1, (int) ($this->reportsConfig()['max_rows'] ?? 10000));
        $runner = $this->runner();
        $total = $runner->count($report->query, $report->limit);
        $rows = $runner->rows($report->query, $report->baseOrder(), $report->limit, $order, $max, 0);
        $t = fn (string $k) => $this->t($k);
        $branding = $this->app->branding();
        $subtitle = array_values(array_filter([
            $report->explanation !== '' ? $report->explanation : null,
            $this->t('export.generated', ['date' => date($this->t('format.datetime')), 'user' => $this->user()->name()]),
            $this->t('export.count', ['n' => $total]),
        ]));
        $filename = 'informe-' . $report->id . '-' . date('Ymd-His') . '.' . $format;
        Log::info('Informes: exportación {format} del informe {id} ({rows} filas)', ['format' => $format, 'id' => $report->id, 'rows' => count($rows)]);

        if ($format === 'xlsx') {
            $columns = [];
            foreach ($report->columns as $i => $c) {
                $columns[] = ['label' => $c['label'], 'format' => $report->excelFormat($i, $t), 'align' => $report->align($i)];
            }
            $data = array_map(fn ($row) => array_map(fn ($i) => $report->exportValue($i, $row[$i] ?? null, $t), array_keys($report->columns)), $rows);
            $file = (new XlsxWriter())->write(mb_substr($report->title, 0, 31), [$branding['company'] . ' · ' . $report->title, ...$subtitle], $columns, $data);
            try {
                $this->sendHeaders('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename, (int) filesize($file));
                readfile($file);
            } finally {
                @unlink($file);
            }
            return;
        }
        $data = array_map(fn ($row) => array_map(fn ($i) => $report->format($i, $row[$i] ?? null, $t), array_keys($report->columns)), $rows);
        $pdf = (new PdfTableReport(
            [
                'title' => $report->title,
                'subtitle' => $subtitle,
                'company' => $branding['company'],
                'footer' => $this->t('export.page'),
                'empty' => $this->t('table.empty'),
                'logo' => $branding['logo_pdf'],
                'color' => $branding['color'],
                'orientation' => 'auto',
            ],
            array_map(fn ($i) => ['label' => $report->columns[$i]['label'], 'align' => $report->align($i)], array_keys($report->columns)),
            $data
        ))->render();
        $this->sendHeaders('application/pdf', $filename, strlen($pdf));
        echo $pdf;
    }

    /** POST id, name: guarda el informe con un nombre (visible para todos los que tienen acceso). */
    public function actionSave(): void
    {
        $this->requirePost();
        $report = $this->ownReport();
        $name = trim((string) $this->param('name', ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $this->flash('error', $this->text('name_required'));
        } else {
            $this->app->db->update('reports', ['name' => $name, 'saved_at' => date('Y-m-d H:i:s')], 'id', $report->id);
            $this->flash('success', $this->text('flash.saved'));
        }
        $this->app->redirect($this->pageUrl('index', ['id' => $report->id]));
    }

    /** POST id: borra el informe (el autor o un administrador). */
    public function actionDelete(): void
    {
        $this->requirePost();
        $report = $this->ownReport();
        $this->app->db->delete('reports', 'id', $report->id);
        $this->flash('success', $this->text('flash.deleted'));
        $this->app->redirect($this->pageUrl());
    }

    // ---------------------------------------------------------------- piezas

    private function canAsk(): bool
    {
        return $this->allowed('ask') && LlmFactory::enabled((array) ($this->app->config['ai'] ?? []));
    }

    private function reportsConfig(): array
    {
        return (array) ($this->app->config['reports'] ?? []);
    }

    /** Conexión de los informes: el usuario de solo lectura de 'reports.db' si se configura. */
    private function reportsDb(): Database
    {
        $ro = $this->reportsConfig()['db'] ?? null;
        return is_array($ro) && $ro !== [] ? new Database(array_replace($this->app->config['db'], $ro)) : $this->app->db;
    }

    private function runner(): ReportRunner
    {
        $cfg = $this->reportsConfig();
        return new ReportRunner($this->reportsDb(), max(1, (int) ($cfg['timeout'] ?? 15)), max(1, (int) ($cfg['max_rows'] ?? 10000)));
    }

    /**
     * Informe que el usuario puede ver: uno guardado o uno suyo sin guardar (los administradores, todos).
     * Su consulta se vuelve a validar: si la configuración ya no permite alguna tabla, no se ejecuta.
     */
    private function load(string $id): ?Report
    {
        if (!ctype_digit($id)) {
            return null;
        }
        $row = $this->app->db->fetch('SELECT * FROM reports WHERE id = ?', [(int) $id]);
        if ($row === null) {
            return null;
        }
        $user = $this->user();
        if ($row['name'] === null && (int) $row['user_id'] !== $user->id() && !$user->isAdmin()) {
            return null;
        }
        $schema = new ReportSchema($this->reportsDb(), $this->reportsConfig());
        if (($error = $schema->guard()->check((string) $row['query'])) !== null) {
            Log::warning('Informes: el informe {id} ya no pasa la validación: {error}', ['id' => $id, 'error' => $error]);
            throw new HttpException(403, 'reports:not_allowed');
        }
        return Report::fromRow($row);
    }

    /** Informe que el usuario puede guardar o borrar (permiso "save" y autor o administrador). */
    private function ownReport(): Report
    {
        $report = $this->load((string) $this->param('id', '')) ?? throw new HttpException(404);
        $user = $this->user();
        if (!$this->allowed('save') || ($report->userId !== $user->id() && !$user->isAdmin())) {
            throw new HttpException(403);
        }
        return $report;
    }

    /** @return array{0:string,1:string,2:list<array{0:int,1:string}>} clave de orden (c2), dirección y orden en posiciones */
    private function userOrder(Report $report): array
    {
        $sort = (string) $this->param('sort', '');
        $dir = $this->param('dir') === 'desc' ? 'desc' : 'asc';
        if (preg_match('/^c(\d+)$/', $sort, $m) && (int) $m[1] < count($report->columns)) {
            return [$sort, $dir, [[(int) $m[1] + 1, $dir]]];
        }
        return ['', $dir, []];
    }

    /** Configuración de la tabla de app.js (la misma que la de un listado). */
    private function tableConfig(Report $report): array
    {
        $columns = [];
        foreach ($report->columns as $i => $c) {
            $columns[] = ['field' => "c$i", 'align' => $report->align($i), 'label' => $c['label']] + ($c['type'] === 'text' ? ['overflow' => 'wrap', 'lines' => 2] : []);
        }
        return [
            'module' => 'report-' . $report->id,
            'dataUrl' => $this->pageUrl('data', ['id' => $report->id]),
            'exportUrl' => $this->pageUrl('export', ['id' => $report->id]),
            'editUrl' => '',
            'deleteUrl' => '',
            'canEdit' => false,
            'canDelete' => false,
            'columns' => $columns,
            'expand' => [],
            'rowActions' => [],
            'sort' => '',
            'dir' => 'asc',
            'size' => self::PAGE_SIZES[0],
            'filtersOpen' => false,
            'ai' => null,
            'pageSizes' => self::PAGE_SIZES,
            'i18n' => [
                'loading' => $this->t('table.loading'),
                'empty' => $this->t('table.empty'),
                'error' => $this->t('table.error'),
                'info' => $this->t('table.info'),
                'edit' => '', 'view' => '', 'delete' => '', 'confirmDelete' => '', 'deleteTitle' => '', 'deleted' => '',
                'expand' => '', 'collapse' => '', 'expandAll' => '', 'collapseAll' => '',
                'search' => $this->t('filter.search'),
                'removeFilter' => $this->t('filter.remove'),
                'aiNotUnderstood' => '',
            ],
        ];
    }

    private function sendHeaders(string $mime, string $filename, int $length): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $length);
        header('Cache-Control: no-store');
    }

    private static function ms(int $start): int
    {
        return (int) ((hrtime(true) - $start) / 1_000_000);
    }
}
