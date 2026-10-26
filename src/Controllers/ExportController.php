<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\DataQuery;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Export\PdfTableReport;
use Quasar\Export\XlsxWriter;

/**
 * Descarga de la tabla de un módulo en Excel o PDF, con los mismos filtros,
 * búsqueda y orden que el usuario tiene aplicados en pantalla.
 *
 *   GET index.php?r=export&m={módulo}&format=xlsx|pdf&q=…&f[…]=…&sort=…&dir=…
 */
final class ExportController extends Controller
{
    public function export(): void
    {
        $m = $this->module('export');
        $cfg = $m->exportConfig() ?? throw new HttpException(404);
        $format = $_GET['format'] ?? '';
        if (!is_string($format) || !in_array($format, $cfg['formats'], true)) {
            throw new HttpException(404);
        }
        @set_time_limit(300);

        $limit = max(1, (int) ($this->app->config['export_max_rows'] ?? 10000));
        $query = new DataQuery($m, $this->app->db);
        $data = $query->export($_GET, $cfg['columns'], $limit);
        $branding = $this->app->branding();
        $fields = array_map(static fn ($c) => $c['field'], $cfg['columns']);

        $subtitle = [$this->t('export.generated', [
            'date' => date($this->t('format.datetime')),
            'user' => $this->user()->name(),
        ])];
        $filters = array_map(static fn ($f) => "{$f[0]}: {$f[1]}", $query->describe($_GET));
        if ($filters) {
            $subtitle[] = $this->t('export.filters') . ': ' . implode(' · ', $filters);
        }
        $shown = count($data['rows']);
        $subtitle[] = $data['truncated']
            ? $this->t('export.truncated', ['n' => $shown, 'total' => $data['total']])
            : $this->t('export.count', ['n' => $data['total']]);

        $filename = $m->name . '-' . date('Ymd-His') . '.' . $format;
        Log::info('{module}: exportación {format} de {rows} filas', [
            'module' => $m->name, 'format' => $format, 'rows' => $shown, 'filters' => $filters,
        ]);

        if ($format === 'xlsx') {
            $rows = [];
            foreach ($data['rows'] as $row) {
                $rows[] = array_map(static fn ($f) => $f->exportValue($row[$f->name] ?? null), $fields);
            }
            $columns = array_map(static fn ($c) => [
                'label' => $c['label'],
                'format' => $c['field']->excelFormat(),
                'align' => $c['align'],
            ], $cfg['columns']);

            $file = (new XlsxWriter())->write($m->title(), [$branding['company'] . ' · ' . $m->title(), ...$subtitle], $columns, $rows);
            try {
                $this->send('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename, (int) filesize($file));
                readfile($file);
            } finally {
                @unlink($file);
            }
            return;
        }

        $rows = [];
        foreach ($data['rows'] as $row) {
            $rows[] = array_map(static fn ($f) => $f->format($row[$f->name] ?? null), $fields);
        }
        $pdf = (new PdfTableReport(
            [
                'title' => $m->title(),
                'subtitle' => $subtitle,
                'company' => $branding['company'],
                'footer' => $this->t('export.page'),
                'empty' => $this->t('table.empty'),
                'logo' => $branding['logo_pdf'],
                'color' => $branding['color'],
                'orientation' => $cfg['orientation'],
            ],
            array_map(static fn ($c) => ['label' => $c['label'], 'align' => $c['align']], $cfg['columns']),
            $rows
        ))->render();
        $this->send('application/pdf', $filename, strlen($pdf));
        echo $pdf;
    }

    private function send(string $mime, string $filename, int $length): void
    {
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $length);
        header('Cache-Control: no-store');
    }
}
