<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\Database;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Export\PdfGroupedReport;
use Quasar\Fields\Field;
use Quasar\Reports\ReportDefinition;
use Quasar\Reports\ReportFormat;
use Quasar\Reports\ReportRunner;

/**
 * Informes PDF (módulos con "type": "report"; ver ReportDefinition y el README).
 *
 *   index.php?r=page&m={informe}                  formulario con los filtros ("params")
 *   index.php?r=page&m={informe}&modal=1          el mismo formulario, en la ventana que abre el listado
 *   index.php?r=page&m={informe}&a=pdf&p[x]=…     genera el PDF (los filtros que falten toman su "default")
 *
 * Los valores de los filtros pasan por su tipo de campo (validación, opciones permitidas de un select) y van
 * a la consulta como parámetros de una consulta preparada; :user_id es siempre el usuario conectado. La
 * consulta se ejecuta en solo lectura, con el tiempo y las filas máximas de 'reports' (y su usuario 'db'
 * de solo lectura si lo hay). El permiso es el "view" del informe en la matriz de permisos.
 */
final class PdfReportPage extends Page
{
    public function index(): void
    {
        $values = [];
        foreach ($this->fields() as $field) {
            $values[$field->name] = $this->requested($field) ?? $field->defaultValue();
        }
        $this->form($values, []);
    }

    public function actionPdf(): void
    {
        $report = $this->module->report() ?? throw new HttpException(404);
        $values = $errors = [];
        foreach ($this->module->fields() as $field) {
            // Un filtro que el usuario no ve ("access") vale siempre su "default": no se puede cambiar por URL
            $value = ($field->canView() ? $this->requested($field) : null) ?? $field->defaultValue();
            $values[$field->name] = $value;
            if ($e = $field->validate($value, true)) {
                $errors[$field->name] = $e[0];
            }
        }
        if ($errors) {
            $this->form($values, $errors, 422);
            return;
        }

        $user = $this->user();
        $bind = ['user_id' => $user->id()];
        foreach ($this->module->fields() as $field) {
            $v = $field->toDb($values[$field->name]);
            $bind[$field->name] = $v === '' ? null : $v;
        }
        $args = static fn (array $names) => array_map(static fn ($n) => $bind[$n], $names);

        $cfg = (array) ($this->app->config['reports'] ?? []);
        $max = max(1, (int) ($cfg['max_rows'] ?? 10000));
        $runner = new ReportRunner($this->reportsDb($cfg), max(1, (int) ($cfg['timeout'] ?? 15)), $max);
        @set_time_limit(300);
        try {
            $result = $runner->select($report->sql, $args($report->bindings), $max);
            $extra = [];
            foreach ($report->values as $name => $v) {
                $extra[$name] = $runner->value($v['sql'], $args($v['bindings']));
            }
        } catch (\PDOException $e) {
            Log::error('Informe PDF {module}: la consulta ha fallado: {error}', ['module' => $this->module->name, 'error' => $e->getMessage()]);
            $this->form($values, ['_form' => $this->text('report.run_error') . (!empty($this->app->config['debug']) ? ' (' . $e->getMessage() . ')' : '')], 500);
            return;
        }
        if ($result['truncated']) {
            $this->form($values, ['_form' => $this->text('report.too_many', ['max' => number_format($max, 0, ',', '.')])], 422);
            return;
        }
        $this->checkColumns($report, $result['columns']);

        $pdf = $report->pdf;
        $vars = $this->vars($values, $extra, $user->name());
        $first = $result['rows'][0] ?? [];
        $items = $this->items($report, $result['rows'], $vars, $totals);
        // Pares «etiqueta: valor»; los que quedan vacíos (un filtro opcional sin elegir) no se muestran
        $pairs = fn (array $list) => array_values(array_filter(
            array_map(fn ($p) => [$p['label'] !== '' ? $this->text($p['label']) : '', trim($this->fill($this->text($p['value']), $vars, $first, $totals))], $list),
            static fn ($p) => $p[1] !== ''
        ));
        $branding = $this->app->branding();

        $file = (new PdfGroupedReport(
            [
                'title' => $this->module->title(),
                'subtitle' => $pdf['subtitle'] !== null ? $this->fill($this->text($pdf['subtitle']), $vars, $first, $totals) : null,
                'company' => $branding['company'],
                'footer' => $this->t('export.page'),
                'empty' => $this->text($pdf['empty'] ?? 'report.empty'),
                'logo' => $branding['logo_pdf'],
                'color' => $branding['color'],
                'orientation' => $pdf['orientation'],
                'fontSize' => $pdf['fontSize'],
                'info' => $pairs($pdf['info']),
                'summary' => $pairs($pdf['summary']),
            ],
            array_map(fn ($c) => [
                'label' => $this->text($c['label']),
                'align' => $c['align'] ?? (ReportFormat::isNumeric($c['format']) ? 'right' : 'left'),
                'width' => $c['width'],
                'wrap' => $c['wrap'],
            ], $pdf['columns']),
            $items
        ))->render();

        Log::info('Informe PDF {module}: {rows} filas', ['module' => $this->module->name, 'rows' => count($result['rows'])]);
        $name = $this->module->name . '-' . date('Ymd-His') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '"');
        header('Content-Length: ' . strlen($file));
        header('Cache-Control: no-store');
        echo $file;
    }

    // ---------------------------------------------------------------- piezas

    /** @return array<string,Field> filtros que puede ver el usuario */
    private function fields(): array
    {
        return array_filter($this->module->fields(), static fn (Field $f) => $f->canView());
    }

    /** Valor de un filtro que viene en la URL o en el formulario (p[nombre]); null si no viene. */
    private function requested(Field $field): mixed
    {
        $input = $_GET['p'] ?? null;
        return is_array($input) && array_key_exists($field->name, $input) ? $field->fromRequest($input) : null;
    }

    private function form(array $values, array $errors, int $status = 200): void
    {
        $modal = !empty($_GET['modal']);
        $vars = [
            'fields' => array_map(static fn (Field $f) => $f->withInput("p[{$f->name}]", 'p_' . $f->name), $this->fields()),
            'values' => $values,
            'errors' => $errors,
            'modal' => $modal,
        ];
        if ($modal) {
            $this->render('pages/report', $vars + ['title' => $this->module->title(), 'module' => $this->module], 'blank', $status);
            return;
        }
        $this->view($vars, 'pages/report', $status);
    }

    /** Conexión de los informes: el usuario de solo lectura de 'reports.db' si se configura. */
    private function reportsDb(array $cfg): Database
    {
        $ro = $cfg['db'] ?? null;
        return is_array($ro) && $ro !== [] ? new Database(array_replace($this->app->config['db'], $ro)) : $this->app->db;
    }

    /** Las columnas y la agrupación del JSON tienen que existir en el resultado: error claro para quien lo configura. */
    private function checkColumns(ReportDefinition $report, array $columns): void
    {
        $fields = array_column($report->pdf['columns'], 'field');
        if ($report->pdf['groupBy'] !== null) {
            $fields[] = $report->pdf['groupBy']['field'];
        }
        foreach ($fields as $f) {
            if (!in_array($f, $columns, true)) {
                throw new \RuntimeException("Informe '{$this->module->name}': la columna '$f' de \"pdf\" no está en el resultado de \"query\" (columnas: " . implode(', ', $columns) . ')');
            }
        }
    }

    /**
     * Valores de los {marcadores} de los textos: filtros (con su texto: la etiqueta de un select, la fecha
     * con el formato del idioma), "values" y los del sistema. Cada uno: [valor, texto].
     * @return array<string,array{0:mixed,1:string}>
     */
    private function vars(array $values, array $extra, string $userName): array
    {
        $out = [
            'today' => [date('Y-m-d'), ReportFormat::apply('date', date('Y-m-d'), $this->tc())],
            'now' => [date('Y-m-d H:i:s'), ReportFormat::apply('datetime', date('Y-m-d H:i:s'), $this->tc())],
            'user' => [$userName, $userName],
            'company' => [$this->app->branding()['company'], $this->app->branding()['company']],
        ];
        foreach ($extra as $name => $v) {
            $out[$name] = [$v, is_scalar($v) ? (string) $v : ''];
        }
        foreach ($this->fields() as $field) {
            $v = $values[$field->name] ?? null;
            $out[$field->name] = [$field->toDb($v), $v === null || $v === '' ? '' : $field->format($v)];
        }
        return $out;
    }

    /**
     * Sustituye {nombre} y {nombre|formato} en un texto. Busca en: total.columna (totales del informe),
     * filtros, "values" y los del sistema, y la fila (la primera del informe o del grupo).
     * Un marcador desconocido se deja tal cual para que se vea al revisar el informe.
     */
    private function fill(string $text, array $vars, array $row, array $totals = []): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_.]*)(?:\|([^{}]+))?\}/', function (array $m) use ($vars, $row, $totals): string {
            $name = $m[1];
            if (array_key_exists($name, $totals)) {
                [$raw, $text] = $totals[$name];
            } elseif (array_key_exists($name, $vars)) {
                [$raw, $text] = $vars[$name];
            } elseif (array_key_exists($name, $row)) {
                $raw = $row[$name];
                $text = is_scalar($raw) ? (string) $raw : '';
            } else {
                return $m[0];
            }
            return isset($m[2]) && ReportFormat::isValid($m[2]) ? ReportFormat::apply($m[2], $raw, $this->tc()) : $text;
        }, $text);
    }

    /**
     * Filas, grupos y totales del PDF ya formateados.
     * @param array<string,array{0:mixed,1:string}>|null $totals sale con los totales generales (total.columna)
     */
    private function items(ReportDefinition $report, array $rows, array $vars, ?array &$totals): array
    {
        $pdf = $report->pdf;
        $columns = $pdf['columns'];
        $group = $pdf['groupBy'];
        $items = [];
        $groupRows = [];
        $groupKey = $groupLabel = null;
        $closeGroup = function () use (&$items, &$groupRows, &$groupLabel, $group): void {
            if ($group !== null && $group['subtotal'] && $groupRows && $this->hasTotals()) {
                $items[] = ['type' => 'subtotal', 'label' => $this->text('report.subtotal', ['group' => (string) $groupLabel]), 'cells' => $this->aggregate($groupRows)[1]];
            }
            $groupRows = [];
        };
        foreach ($rows as $row) {
            if ($group !== null) {
                $key = $row[$group['field']];
                if ($items === [] || $key !== $groupKey) {
                    $closeGroup();
                    if ($items !== [] && $group['newPage']) {
                        $items[] = ['type' => 'break'];
                    }
                    $groupKey = $key;
                    $groupLabel = $this->fill($this->text($group['label']), $vars, $row);
                    $items[] = ['type' => 'group', 'label' => $groupLabel];
                }
            }
            $groupRows[] = $row;
            $items[] = ['type' => 'row', 'cells' => array_map(fn ($c) => $this->cell($c, $row[$c['field']] ?? null), $columns)];
        }
        $closeGroup();

        [$raw, $cells] = $this->aggregate($rows);
        $totals = [];
        foreach ($columns as $i => $c) {
            if ($c['total'] !== null) {
                $totals['total.' . $c['field']] = [$raw[$i], $cells[$i]];
            }
        }
        if ($pdf['totals'] && $rows && $this->hasTotals()) {
            $items[] = ['type' => 'total', 'label' => $this->text($pdf['totalLabel']), 'cells' => $cells];
        }
        return $items;
    }

    /** Texto de una celda: el de "options" si el valor está ahí; si no, el valor con su formato. */
    private function cell(array $column, mixed $value): string
    {
        if ($column['options'] !== null && is_scalar($value) && isset($column['options'][(string) $value])) {
            return $this->text($column['options'][(string) $value]);
        }
        return ReportFormat::apply($column['format'], $value, $this->tc());
    }

    private function hasTotals(): bool
    {
        return array_filter(array_column($this->module->report()->pdf['columns'], 'total')) !== [];
    }

    /**
     * Totales de unas filas: sum, avg, min, max (de los valores numéricos) y count (valores no vacíos;
     * 0 y «no» no cuentan, así un sí/no cuenta los «sí»).
     * @return array{0:list<mixed>,1:list<string>} valor y texto de cada columna ('' sin total)
     */
    private function aggregate(array $rows): array
    {
        $raw = $cells = [];
        foreach ($this->module->report()->pdf['columns'] as $c) {
            $values = array_column($rows, $c['field']);
            $nums = array_map('floatval', array_filter($values, 'is_numeric'));
            $value = match ($c['total']) {
                'sum' => array_sum($nums),
                'avg' => $nums ? array_sum($nums) / count($nums) : null,
                'min' => $nums ? min($nums) : null,
                'max' => $nums ? max($nums) : null,
                'count' => count(array_filter($values, static fn ($v) => !in_array($v, [null, '', 0, '0', false], true))),
                default => null,
            };
            $format = match (true) {
                $c['total'] === 'count' => 'int',
                $c['format'] === 'int' && $c['total'] === 'avg' => 'number',
                ReportFormat::isNumeric($c['format']) || str_contains($c['format'], '%') => $c['format'],
                default => $value !== null && floor((float) $value) !== (float) $value ? 'number' : 'int',
            };
            $raw[] = $value;
            $cells[] = $value === null ? '' : ReportFormat::apply($format, $value, $this->tc());
        }
        return [$raw, $cells];
    }

    /** @return \Closure(string):string textos comunes para ReportFormat */
    private function tc(): \Closure
    {
        return fn (string $key) => $this->t($key);
    }
}
