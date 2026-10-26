<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\Database;
use Quasar\Core\HttpException;
use Quasar\Fields\SelectField;
use Quasar\Fields\TagsField;

/**
 * Opciones para los componentes de app.js (JSON):
 *   r=options&m={módulo}&field={campo}&q=texto     select con "search": "remote" → resultados
 *   r=options&m={módulo}&field={campo}&value=12     select remoto → etiqueta de un valor
 *   r=options&m={módulo}&field={campo}&q=texto     tags con "suggestions": "existing" → etiquetas ya usadas
 */
final class OptionsController extends Controller
{
    private const TAG_SUGGESTIONS = 12;
    private const TAG_SCAN_ROWS = 500;

    public function index(): void
    {
        $m = $this->module('view');
        $path = $_GET['field'] ?? '';
        $field = is_string($path) ? $m->fieldByPath($path) : null;
        $q = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';

        if ($field instanceof SelectField) {
            $value = $_GET['value'] ?? null;
            if (is_string($value) && $value !== '') {
                $label = $field->labelFor($value);
                $this->app->json(['results' => $label === null ? [] : [['value' => $value, 'label' => $label]]]);
                return;
            }
            $this->app->json(['results' => $field->search($q)]);
            return;
        }

        // Sugerencias de etiquetas ya usadas (solo campos tags de primer nivel)
        if ($field instanceof TagsField && $field->suggestsExisting() && $field->sqlExpr() !== null && !str_contains((string) $path, '.')) {
            $col = $field->sqlExpr();
            $scope = $m->rowCondition(); // solo etiquetas de registros que ve el usuario
            $rows = $this->app->db->fetchAll(sprintf(
                'SELECT %s AS t FROM %s WHERE %s LIKE ?%s ORDER BY %s DESC LIMIT %d',
                $col,
                Database::ident($m->table()),
                $col,
                $scope !== null ? ' AND ' . $scope[0] : '',
                Database::ident($m->primaryKey()),
                self::TAG_SCAN_ROWS
            ), ['%' . addcslashes($q, '%_\\') . '%', ...($scope[1] ?? [])]);
            $count = [];
            $needle = mb_strtolower($q);
            foreach ($rows as $row) {
                foreach ($field->fromDb($row['t']) as $tag) {
                    if ($needle === '' || str_contains(mb_strtolower($tag), $needle)) {
                        $count[$tag] = ($count[$tag] ?? 0) + 1;
                    }
                }
            }
            arsort($count);
            $this->app->json(['results' => array_slice(array_keys($count), 0, self::TAG_SUGGESTIONS)]);
            return;
        }

        throw new HttpException(404);
    }
}
