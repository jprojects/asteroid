<?php
declare(strict_types=1);

namespace Quasar\Pages;

use DateTimeImmutable;
use Quasar\Controllers\Page;
use Quasar\Core\Database;
use Quasar\Core\HttpException;
use Quasar\Core\Module;
use Quasar\Fields\Field;
use Quasar\Fields\SelectField;

/**
 * Calendario mensual de los registros de cualquier módulo con un campo de fecha.
 *
 *   modules/calendar.json
 *   {
 *     "type": "page", "controller": "Quasar\\Pages\\CalendarPage",
 *     "calendar": {
 *       "source": "activities",   módulo de origen
 *       "date":   "starts_at",    campo date o datetime
 *       "title":  "title",        texto del evento
 *       "color":  "type"          opcional: campo select cuyo valor da el color
 *     }
 *   }
 *
 * Solo muestra los registros si el usuario puede ver el módulo de origen; al pulsar un
 * evento se abre su ficha y al pulsar un día se crea un registro con esa fecha (si puede crear).
 */
final class CalendarPage extends Page
{
    private const MAX_PER_DAY = 4;
    private const COLORS = 6;

    public function index(): void
    {
        $cfg = (array) $this->module->config('calendar', []);
        $source = $this->otherModule((string) ($cfg['source'] ?? ''));
        if (!$this->allowed('view', $source)) {
            throw new HttpException(403, 'calendar:no_access');
        }
        $dateField = $source->field((string) ($cfg['date'] ?? ''));
        $titleField = $source->field((string) ($cfg['title'] ?? ''));
        $colorField = isset($cfg['color']) ? $source->field((string) $cfg['color']) : null;
        if (!$dateField->canView() || !$titleField->canView()) {
            throw new HttpException(403, 'calendar:no_access'); // campos limitados con "access"
        }
        if ($colorField !== null && !$colorField->canView()) {
            $colorField = null;
        }

        // Mes pedido (?month=2026-10) o el actual
        $month = $this->param('month');
        $first = ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month))
            ? new DateTimeImmutable($month . '-01')
            : new DateTimeImmutable('first day of this month');
        $first = $first->setTime(0, 0);
        // Rejilla de semanas completas, de lunes a domingo
        $gridStart = $first->modify('monday this week');
        $gridEnd = $first->modify('last day of this month')->modify('sunday this week');

        $events = $this->events($source, $dateField, $titleField, $colorField, $gridStart, $gridEnd->modify('+1 day'));
        $canCreate = $this->allowed('create', $source);
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        $weeks = [];
        $i = 0;
        for ($day = $gridStart; $day <= $gridEnd; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $weeks[intdiv($i++, 7)][] = [
                'date' => $key,
                'day' => (int) $day->format('j'),
                'inMonth' => $day->format('m') === $first->format('m'),
                'isToday' => $key === $today,
                'weekend' => (int) $day->format('N') >= 6,
                'events' => $events[$key] ?? [],
                // Alta con la fecha del día ya puesta (ModuleController::prefill)
                'newUrl' => $canCreate ? $this->app->url('edit', [
                    'm' => $source->name,
                    'v' => [$dateField->name => $key . ($dateField->type() === 'datetime' ? 'T09:00' : '')],
                ]) : null,
            ];
        }

        $monthLabel = $this->text('months.' . ((int) $first->format('n') - 1)) . ' ' . $first->format('Y');
        $this->view([
            'heading' => $this->module->title(),
            'subtitle' => mb_strtoupper(mb_substr($monthLabel, 0, 1)) . mb_substr($monthLabel, 1),
            'weeks' => $weeks,
            'weekdays' => array_map(fn ($i) => $this->text('weekdays.' . $i), range(0, 6)),
            'monthLabel' => $monthLabel,
            'prevUrl' => $this->pageUrl('index', ['month' => $first->modify('-1 month')->format('Y-m')]),
            'nextUrl' => $this->pageUrl('index', ['month' => $first->modify('+1 month')->format('Y-m')]),
            'todayUrl' => $this->pageUrl(),
            'isCurrentMonth' => $first->format('Y-m') === (new DateTimeImmutable())->format('Y-m'),
            'newUrl' => $canCreate ? $this->app->url('edit', ['m' => $source->name]) : null,
            'legend' => $colorField instanceof SelectField ? $this->legend($colorField) : [],
            'total' => array_sum(array_map('count', $events)),
            'maxPerDay' => self::MAX_PER_DAY,
        ], 'pages/calendar'); // la misma plantilla para cualquier módulo de calendario
    }

    /**
     * Registros del rango, agrupados por día.
     * @return array<string,list<array{id:string,title:string,time:?string,color:int,colorLabel:?string,url:string}>>
     */
    private function events(Module $source, Field $dateField, Field $titleField, ?Field $colorField, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $dateExpr = $dateField->sqlExpr() ?? throw new \RuntimeException('El campo de fecha del calendario debe existir en SQL');
        $select = [
            Database::ident($source->primaryKey()) . ' AS `__id`',
            $dateExpr . ' AS `__date`',
            ($titleField->sqlExpr() ?? "''") . ' AS `__title`',
        ];
        if ($colorField?->sqlExpr() !== null) {
            $select[] = $colorField->sqlExpr() . ' AS `__color`';
        }
        $scope = $source->rowCondition(); // solo los registros que ve el usuario
        $rows = $this->app->db->fetchAll(sprintf(
            'SELECT %s FROM %s WHERE %s >= ? AND %s < ?%s ORDER BY %s',
            implode(', ', $select),
            Database::ident($source->table()),
            $dateExpr,
            $dateExpr,
            $scope !== null ? ' AND ' . $scope[0] : '',
            $dateExpr
        ), [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), ...($scope[1] ?? [])]);

        $colorIndex = $colorField instanceof SelectField ? array_flip(array_map('strval', array_keys($colorField->options()))) : [];
        $out = [];
        foreach ($rows as $row) {
            $date = new DateTimeImmutable((string) $row['__date']);
            $time = $dateField->type() === 'datetime' && $date->format('H:i') !== '00:00' ? $date->format('H:i') : null;
            $out[$date->format('Y-m-d')][] = [
                'id' => (string) $row['__id'],
                'title' => $titleField->format($row['__title']) ?: '#' . $row['__id'],
                'time' => $time,
                'color' => isset($row['__color'], $colorIndex[(string) $row['__color']]) ? $colorIndex[(string) $row['__color']] % self::COLORS : 0,
                'colorLabel' => isset($row['__color']) && $colorField ? $colorField->format($row['__color']) : null,
                'tone' => isset($row['__color']) && $colorField ? $colorField->badgeColor($row['__color']) : null,
                'url' => $this->app->url('edit', ['m' => $source->name, 'id' => $row['__id']]),
            ];
        }
        return $out;
    }

    /** @return list<array{label:string,color:int,tone:?string}> */
    private function legend(SelectField $field): array
    {
        $out = [];
        $i = 0;
        foreach ($field->options() as $value => $label) {
            $out[] = ['label' => $label, 'color' => $i++ % self::COLORS, 'tone' => $field->badgeColor((string) $value)];
        }
        return $out;
    }
}
