<?php
declare(strict_types=1);

namespace Quasar\Pages;

use DateTimeImmutable;
use Quasar\Controllers\Page;
use Quasar\Core\CalendarSource;
use Quasar\Core\Database;
use Quasar\Core\FormHandler;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\Module;
use Quasar\Fields\Field;
use Quasar\Fields\SelectField;

/**
 * Calendario de los registros de cualquier módulo con un campo de fecha: vista de mes y, si la fecha es
 * datetime, de semana y de día con horas, en las que los eventos se arrastran (cambiar de hora o de día) y se
 * estiran (cambiar la duración).
 *
 *   modules/calendar.json
 *   {
 *     "type": "page", "controller": "Quasar\\Pages\\CalendarPage",
 *     "calendar": {
 *       "source": "activities",   módulo de origen
 *       "date":   "starts_at",    campo date o datetime
 *       "end":    "ends_at",      opcional: campo datetime de fin (duración del evento)
 *       "title":  "title",        texto del evento
 *       "color":  "type",         opcional: campo select (su "colors") o campo con un color "#rrggbb"
 *       "views":  ["month", "week", "day"],  vistas disponibles; la primera es la inicial
 *       "hours":  [8, 20],        horas visibles en semana y día
 *       "slot":   30,             minutos de cada franja (5, 10, 15, 20, 30 o 60)
 *       "days":   [1, 2, 3, 4, 5, 6],  días de la semana visibles en semana (1 = lunes … 7 = domingo)
 *       "duration": 60,           minutos de un evento sin fin y de las altas desde una franja
 *       "extra":  "Quasar\\Hooks\\…"  opcional: eventos de fuera (CalendarSource), de solo lectura
 *     }
 *   }
 *
 * Solo muestra los registros que el usuario ve en el módulo de origen (permiso y "scope"). Pulsar un evento abre
 * su ficha; pulsar un día o una franja crea un registro con esa fecha y hora (si puede crear). Mover o estirar un
 * evento necesita el permiso de editar y pasa por las mismas reglas que guardar la ficha: "scope", "editAccess",
 * validación del campo y hooks (beforeSave/afterSave).
 */
final class CalendarPage extends Page
{
    private const MAX_PER_DAY = 4;
    private const COLORS = 6;
    private const VIEWS = ['month', 'week', 'day'];
    private const SLOTS = [5, 10, 15, 20, 30, 60];

    public function index(): void
    {
        $c = $this->config();
        $source = $c['source'];
        $view = in_array($this->param('view'), $c['views'], true) ? (string) $this->param('view') : $c['views'][0];

        // Día de referencia: ?date=2026-10-21 (o ?month=2026-10, como antes); por defecto, hoy
        $date = $this->param('date');
        $month = $this->param('month');
        $anchor = match (true) {
            $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false => new DateTimeImmutable($date),
            $month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 => new DateTimeImmutable($month . '-01'),
            default => new DateTimeImmutable('today'),
        };
        $anchor = $anchor->setTime(0, 0);

        $notice = $this->extraNotice($c); // antes que la leyenda y los eventos (puede fijar qué se ve)
        $vars = [
            'heading' => $this->module->title(),
            'view' => $view,
            'views' => count($c['views']) > 1 ? array_map(fn ($v) => ['view' => $v, 'label' => $this->text('cal.views.' . $v),
                'url' => $this->pageUrl('index', ['view' => $v, 'date' => $anchor->format('Y-m-d')])], $c['views']) : [],
            'newUrl' => $this->allowed('create', $source) ? $this->app->url('edit', ['m' => $source->name]) : null,
            'legend' => array_merge($c['color'] instanceof SelectField ? $this->legend($c['color']) : [], $this->extraLegend($c)),
            'maxPerDay' => self::MAX_PER_DAY,
            'notice' => $notice,
        ];
        $vars += $view === 'month' ? $this->monthView($c, $anchor) : $this->timeView($c, $anchor, $view);
        $this->view($vars, 'pages/calendar'); // la misma plantilla para cualquier módulo de calendario
    }

    /**
     * Mover o estirar un evento (vistas de semana y día): POST id, start y end ("Y-m-d\TH:i").
     * Responde JSON: {ok: true} o {error} con el mensaje para el usuario.
     */
    public function actionMove(): void
    {
        $this->requirePost();
        $c = $this->config();
        $source = $c['source'];
        $user = $this->user();
        if (!$c['editable'] || !$this->allowed('edit', $source)) {
            $this->json(['error' => $this->text('cal.move_denied')], 403);
            return;
        }
        $id = (string) $this->param('id');
        $handler = new FormHandler($source, $this->app->db);
        $existing = $id !== '' ? $handler->load($id) : null; // null: no existe o queda fuera de su "scope"
        if ($existing === null) {
            $this->json(['error' => $this->text('cal.move_missing')], 404);
            return;
        }

        $values = [$c['date']->name => (string) $this->param('start')];
        if ($c['end'] !== null) {
            $values[$c['end']->name] = (string) $this->param('end');
        }
        $data = [];
        foreach ($values as $name => $input) {
            $field = $source->field($name);
            $value = $field->fromRequest([$name => $input]);
            if ($value === '' || $field->validate($value, false)) {
                $this->json(['error' => $this->text('cal.move_invalid')], 422);
                return;
            }
            $data[$field->column()] = $field->toDb($value);
        }
        if ($c['end'] !== null && $data[$c['end']->column()] <= $data[$c['date']->column()]) {
            $this->json(['error' => $this->text('cal.move_invalid')], 422);
            return;
        }

        $hooks = $source->hooks($this->app->db);
        $hooks?->setFormValues(array_merge($existing, $values));
        if ($hooks && ($err = $hooks->beforeSave($data, $id, $existing, $user)) !== null) {
            $this->json(['error' => $source->t($err)], 422);
            return;
        }
        $data = array_merge($data, $source->scopeValues($user));
        $this->app->db->update($source->table(), $data, $source->primaryKey(), $id);
        $hooks?->afterSave($id, $data, false, $user);
        Log::info('{module}: registro {id} movido en el calendario', ['module' => $source->name, 'id' => $id]);
        $this->json(['ok' => true]);
    }

    /**
     * Configuración del calendario, comprobada (un error en el JSON da un mensaje claro).
     * @return array{source:Module,date:Field,end:?Field,title:Field,color:?Field,views:list<string>,hours:array{0:int,1:int},
     *               slot:int,days:list<int>,duration:int,timed:bool,editable:bool}
     */
    private function config(): array
    {
        $cfg = (array) $this->module->config('calendar', []);
        $source = $this->otherModule((string) ($cfg['source'] ?? ''));
        if (!$this->allowed('view', $source)) {
            throw new HttpException(403, 'calendar:no_access');
        }
        $dateField = $source->field((string) ($cfg['date'] ?? ''));
        $titleField = $source->field((string) ($cfg['title'] ?? ''));
        $endField = isset($cfg['end']) ? $source->field((string) $cfg['end']) : null;
        $colorField = isset($cfg['color']) ? $source->field((string) $cfg['color']) : null;
        if (!$dateField->canView() || !$titleField->canView()) {
            throw new HttpException(403, 'calendar:no_access'); // campos limitados con "access"
        }
        if ($colorField !== null && !$colorField->canView()) {
            $colorField = null;
        }
        $sourceTimed = $dateField->type() === 'datetime';
        $error = fn (string $msg) => new \RuntimeException("Módulo '{$this->module->name}': calendar.$msg");
        $extra = null;
        if (isset($cfg['extra'])) {
            $class = (string) $cfg['extra'];
            if (!class_exists($class) || !is_subclass_of($class, CalendarSource::class)) {
                throw $error("extra tiene que ser una clase que implemente Quasar\\Core\\CalendarSource ($class)");
            }
            $extra = new $class();
        }
        // Semana y día: con fecha y hora en el módulo o con eventos de fuera (que pueden tener hora)
        $timed = $sourceTimed || $extra !== null;
        if ($endField !== null && ($endField->type() !== 'datetime' || !$sourceTimed)) {
            throw $error('end tiene que ser un campo datetime, y date también');
        }

        $views = array_values((array) ($cfg['views'] ?? ['month']));
        if (!$views || array_diff($views, self::VIEWS)) {
            throw $error('views admite ' . implode(', ', self::VIEWS));
        }
        if (!$timed && $views !== ['month']) {
            throw $error('las vistas week y day necesitan que date sea datetime');
        }
        $hours = array_map('intval', (array) ($cfg['hours'] ?? [8, 20]));
        if (count($hours) !== 2 || $hours[0] < 0 || $hours[1] > 24 || $hours[0] >= $hours[1]) {
            throw $error('hours tiene que ser [inicio, fin] entre 0 y 24');
        }
        $slot = (int) ($cfg['slot'] ?? 30);
        if (!in_array($slot, self::SLOTS, true)) {
            throw $error('slot admite ' . implode(', ', self::SLOTS) . ' minutos');
        }
        $days = array_values(array_unique(array_map('intval', (array) ($cfg['days'] ?? [1, 2, 3, 4, 5, 6, 7]))));
        sort($days);
        if (!$days || min($days) < 1 || max($days) > 7) {
            throw $error('days admite de 1 (lunes) a 7 (domingo)');
        }
        $duration = (int) ($cfg['duration'] ?? 60);
        if ($duration < $slot || $duration > 24 * 60) {
            throw $error('duration tiene que ir de slot a 1440 minutos');
        }

        return [
            'source' => $source, 'date' => $dateField, 'end' => $endField, 'title' => $titleField, 'color' => $colorField,
            'views' => $views, 'hours' => $hours, 'slot' => $slot, 'days' => $days, 'duration' => $duration, 'timed' => $timed,
            'sourceTimed' => $sourceTimed, 'extra' => $extra,
            // Se arrastra si la fecha (y el fin) se pueden editar: sin "readonly" ni "editAccess" que lo impidan
            'editable' => $sourceTimed && !$dateField->isReadonly() && $dateField->canEdit()
                && ($endField === null || (!$endField->isReadonly() && $endField->canEdit())),
        ];
    }

    /** @return array<string,mixed> variables de la vista de mes */
    private function monthView(array $c, DateTimeImmutable $anchor): array
    {
        $first = $anchor->modify('first day of this month');
        // Rejilla de semanas completas, de lunes a domingo
        $gridStart = $first->modify('monday this week');
        $gridEnd = $first->modify('last day of this month')->modify('sunday this week');

        $events = $this->events($c, $gridStart, $gridEnd->modify('+1 day'));
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $canCreate = $this->allowed('create', $c['source']);

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
                'newUrl' => $canCreate ? $this->newUrl($c, $key . ($c['sourceTimed'] ? 'T09:00' : '')) : null,
                'dayUrl' => in_array('day', $c['views'], true) ? $this->pageUrl('index', ['view' => 'day', 'date' => $key]) : null,
            ];
        }

        $monthLabel = $this->monthLabel($first);
        return [
            'subtitle' => $monthLabel,
            'label' => $monthLabel,
            'weeks' => $weeks,
            'weekdays' => array_map(fn ($i) => $this->text('cal.weekdays.' . $i), range(0, 6)),
            'prevUrl' => $this->pageUrl('index', ['view' => 'month', 'date' => $first->modify('-1 month')->format('Y-m-d')]),
            'nextUrl' => $this->pageUrl('index', ['view' => 'month', 'date' => $first->modify('+1 month')->format('Y-m-d')]),
            'todayUrl' => $this->pageUrl('index', ['view' => 'month']),
            'isCurrent' => $first->format('Y-m') === (new DateTimeImmutable())->format('Y-m'),
            'total' => array_sum(array_map('count', $events)),
        ];
    }

    /** @return array<string,mixed> variables de las vistas de semana y día (rejilla de horas) */
    private function timeView(array $c, DateTimeImmutable $anchor, string $view): array
    {
        $dates = [];
        if ($view === 'week') {
            $monday = $anchor->modify('monday this week');
            foreach ($c['days'] as $n) {
                $dates[] = $monday->modify('+' . ($n - 1) . ' days');
            }
            $step = '1 week';
        } else {
            $dates[] = $anchor;
            $step = '1 day';
        }
        $last = $dates[count($dates) - 1];

        [$startH, $endH] = $c['hours'];
        $span = ($endH - $startH) * 60;
        $byDay = $this->events($c, $dates[0], $last->modify('+1 day'));
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $canCreate = $this->allowed('create', $c['source']);

        $columns = [];
        $total = 0;
        foreach ($dates as $d) {
            $key = $d->format('Y-m-d');
            $timed = [];
            $untimed = [];
            foreach ($byDay[$key] ?? [] as $ev) {
                $total++;
                if ($ev['time'] === null) {
                    $untimed[] = $ev; // sin hora (00:00 y sin fin): arriba, fuera de la rejilla
                    continue;
                }
                // Minutos desde el inicio visible, recortados a la rejilla
                $start = ((int) substr($ev['start'], 11, 2) - $startH) * 60 + (int) substr($ev['start'], 14, 2);
                $end = $ev['end'] !== null && substr($ev['end'], 0, 10) === $key
                    ? ((int) substr($ev['end'], 11, 2) - $startH) * 60 + (int) substr($ev['end'], 14, 2)
                    : ($ev['end'] !== null ? $span : $start + $c['duration']);
                $ev['top'] = max(0, min($span - $c['slot'], $start));
                $ev['bottom'] = max($ev['top'] + $c['slot'], min($span, $end));
                $ev['clipped'] = $start < 0 || $end > $span;
                $timed[] = $ev;
            }
            $columns[] = [
                'date' => $key,
                'label' => $this->text('cal.weekdays.' . ((int) $d->format('N') - 1)) . ' ' . $d->format('j'),
                'isToday' => $key === $today,
                'weekend' => (int) $d->format('N') >= 6,
                'untimed' => $untimed,
                'events' => $this->lanes($timed),
                'newUrl' => $canCreate ? $this->newUrl($c, $key . ($c['sourceTimed'] ? 'T' . sprintf('%02d:00', $startH) : '')) : null,
                'dayUrl' => $view === 'week' && in_array('day', $c['views'], true) ? $this->pageUrl('index', ['view' => 'day', 'date' => $key]) : null,
            ];
        }

        $label = $view === 'day'
            ? $this->text('cal.weekdays_long.' . ((int) $anchor->format('N') - 1)) . ', ' . $anchor->format('j') . ' ' . $this->text('cal.months_short.' . ((int) $anchor->format('n') - 1)) . ' ' . $anchor->format('Y')
            : $this->rangeLabel($dates[0], $last);
        $slots = [];
        for ($m = 0; $m < $span; $m += $c['slot']) {
            $slots[] = $m % 60 === 0 ? sprintf('%02d:00', $startH + intdiv($m, 60)) : null;
        }
        $isCurrent = $view === 'day'
            ? $anchor->format('Y-m-d') === $today
            : $anchor->modify('monday this week')->format('Y-m-d') === (new DateTimeImmutable('monday this week'))->format('Y-m-d');

        return [
            'subtitle' => $label,
            'label' => $label,
            'columns' => $columns,
            'slots' => $slots,
            'span' => $span,
            'prevUrl' => $this->pageUrl('index', ['view' => $view, 'date' => $anchor->modify('-' . $step)->format('Y-m-d')]),
            'nextUrl' => $this->pageUrl('index', ['view' => $view, 'date' => $anchor->modify('+' . $step)->format('Y-m-d')]),
            'todayUrl' => $this->pageUrl('index', ['view' => $view]),
            'isCurrent' => $isCurrent,
            'total' => $total,
            // Para app.js: arrastrar, estirar y crear en una franja
            'grid' => [
                'moveUrl' => $c['editable'] && $this->allowed('edit', $c['source']) ? $this->pageUrl('move') : null,
                // Crear en una franja: solo si la fecha del módulo tiene hora
                'newUrl' => $canCreate && $c['sourceTimed'] ? $this->app->url('edit', ['m' => $c['source']->name]) : null,
                'dateField' => $c['date']->name,
                'endField' => $c['end']?->name,
                'startHour' => $startH,
                'span' => $span,
                'slot' => $c['slot'],
                'duration' => $c['duration'],
                'error' => $this->text('cal.move_error'),
            ],
        ];
    }

    /**
     * Columnas para los eventos que se solapan: cada evento recibe su carril y los carriles de su grupo.
     * @param list<array<string,mixed>> $events
     * @return list<array<string,mixed>>
     */
    private function lanes(array $events): array
    {
        usort($events, static fn ($a, $b) => [$a['top'], -$a['bottom']] <=> [$b['top'], -$b['bottom']]);
        $out = [];
        $group = [];
        $laneEnds = [];
        $groupEnd = -1;
        $flush = static function () use (&$group, &$out, &$laneEnds): void {
            foreach ($group as $ev) {
                $ev['lanes'] = count($laneEnds);
                $out[] = $ev;
            }
            $group = [];
            $laneEnds = [];
        };
        foreach ($events as $ev) {
            if ($ev['top'] >= $groupEnd) {
                $flush();
            }
            $lane = null;
            foreach ($laneEnds as $i => $end) {
                if ($end <= $ev['top']) {
                    $lane = $i;
                    break;
                }
            }
            $lane ??= count($laneEnds);
            $laneEnds[$lane] = $ev['bottom'];
            $ev['lane'] = $lane;
            $group[] = $ev;
            $groupEnd = max($groupEnd, $ev['bottom']);
        }
        $flush();
        return $out;
    }

    /**
     * Registros del rango, agrupados por día.
     * @return array<string,list<array<string,mixed>>>
     */
    private function events(array $c, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var Module $source */
        $source = $c['source'];
        $dateExpr = $c['date']->sqlExpr() ?? throw new \RuntimeException('El campo de fecha del calendario debe existir en SQL');
        $select = [
            Database::ident($source->primaryKey()) . ' AS `__id`',
            $dateExpr . ' AS `__date`',
            ($c['title']->sqlExpr() ?? "''") . ' AS `__title`',
        ];
        if ($c['end']?->sqlExpr() !== null) {
            $select[] = $c['end']->sqlExpr() . ' AS `__end`';
        }
        if ($c['color']?->sqlExpr() !== null) {
            $select[] = $c['color']->sqlExpr() . ' AS `__color`';
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

        $colorField = $c['color'];
        $colorIndex = $colorField instanceof SelectField ? array_flip(array_map('strval', array_keys($colorField->options()))) : [];
        $out = [];
        foreach ($rows as $row) {
            $date = new DateTimeImmutable((string) $row['__date']);
            $end = isset($row['__end']) && !str_starts_with((string) $row['__end'], '0000') ? new DateTimeImmutable((string) $row['__end']) : null;
            if ($end !== null && $end <= $date) {
                $end = null;
            }
            $time = $c['sourceTimed'] && ($date->format('H:i') !== '00:00' || $end !== null) ? $date->format('H:i') : null;
            $color = isset($row['__color']) ? (string) $row['__color'] : null;
            // Un campo que guarda el color ("#rrggbb") lo da tal cual; un select, por su "colors" o su posición
            $hex = $color !== null && !$colorField instanceof SelectField && preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : null;
            $out[$date->format('Y-m-d')][] = [
                'id' => (string) $row['__id'],
                'title' => $c['title']->format($row['__title']) ?: '#' . $row['__id'],
                'time' => $time,
                'timeEnd' => $time !== null && $end !== null && $end->format('Y-m-d') === $date->format('Y-m-d') ? $end->format('H:i') : null,
                'start' => $date->format('Y-m-d H:i'),
                'end' => $end?->format('Y-m-d H:i'),
                'color' => $color !== null && isset($colorIndex[$color]) ? $colorIndex[$color] % self::COLORS : 0,
                'colorLabel' => $color !== null && $colorField instanceof SelectField ? $colorField->format($color) : null,
                'tone' => $hex ?? ($color !== null && $colorField instanceof SelectField ? $colorField->badgeColor($color) : null),
                'url' => $this->app->url('edit', ['m' => $source->name, 'id' => $row['__id']]),
                'external' => false,
            ];
        }
        if ($c['extra'] !== null) {
            foreach ($this->extraEvents($c['extra'], $from, $to) as $key => $list) {
                $out[$key] = array_merge($out[$key] ?? [], $list);
            }
            foreach ($out as &$list) {
                usort($list, static fn ($a, $b) => [$a['time'] !== null, $a['start']] <=> [$b['time'] !== null, $b['start']]);
            }
            unset($list);
        }
        return $out;
    }

    /**
     * Eventos de la fuente "extra" (CalendarSource), con el mismo formato que los del módulo y agrupados por día.
     * Los de todo el día que duran varios días salen en cada uno. Un error de la fuente no rompe el calendario.
     * @return array<string,list<array<string,mixed>>>
     */
    private function extraEvents(CalendarSource $extra, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        try {
            $events = $extra->events($this->app, $this->user(), $from, $to);
        } catch (\Throwable $e) {
            Log::error('{module}: los eventos de fuera del calendario han fallado', ['module' => $this->module->name, 'exception' => $e]);
            return [];
        }
        $out = [];
        foreach ($events as $n => $e) {
            try {
                $start = new DateTimeImmutable((string) $e['start']);
                $end = !empty($e['end']) ? new DateTimeImmutable((string) $e['end']) : null;
            } catch (\Exception) {
                continue;
            }
            $allDay = !empty($e['allDay']) || strlen((string) $e['start']) === 10;
            $item = [
                'title' => (string) ($e['title'] ?? ''),
                'color' => 0,
                'colorLabel' => isset($e['label']) ? (string) $e['label'] : null,
                'tone' => isset($e['tone']) && $e['tone'] !== '' ? (string) $e['tone'] : null,
                'url' => (string) ($e['url'] ?? '#'),
                'external' => true,
            ];
            if ($allDay) {
                $last = $end !== null && $end > $start ? $end : $start->modify('+1 day'); // fin exclusivo
                for ($d = max($start, $from); $d < $last && $d < $to; $d = $d->modify('+1 day')) {
                    $out[$d->format('Y-m-d')][] = $item + ['id' => 'x' . $n, 'time' => null, 'timeEnd' => null,
                        'start' => $d->format('Y-m-d 00:00'), 'end' => null];
                }
                continue;
            }
            if ($start < $from || $start >= $to) {
                continue;
            }
            $sameDay = $end !== null && $end->format('Y-m-d') === $start->format('Y-m-d');
            $out[$start->format('Y-m-d')][] = $item + [
                'id' => 'x' . $n,
                'time' => $start->format('H:i'),
                'timeEnd' => $sameDay ? $end->format('H:i') : null,
                'start' => $start->format('Y-m-d H:i'),
                'end' => $end !== null && $end > $start ? $end->format('Y-m-d H:i') : null,
            ];
        }
        return $out;
    }

    /** @return ?array{text:string,links:list<array{label:string,url:string,active?:bool}>} aviso de la fuente "extra" */
    private function extraNotice(array $c): ?array
    {
        if ($c['extra'] === null || !method_exists($c['extra'], 'notice')) {
            return null;
        }
        try {
            $n = $c['extra']->notice($this->app, $this->user());
            return is_array($n) ? ['text' => (string) ($n['text'] ?? ''), 'links' => array_values((array) ($n['links'] ?? []))] : null;
        } catch (\Throwable $e) {
            Log::error('{module}: el aviso de los eventos de fuera ha fallado', ['module' => $this->module->name, 'exception' => $e]);
            return null;
        }
    }

    /** @return list<array{label:string,color:int,tone:?string}> la leyenda de la fuente "extra" */
    private function extraLegend(array $c): array
    {
        if ($c['extra'] === null) {
            return [];
        }
        try {
            return array_map(static fn (array $l) => ['label' => (string) $l['label'], 'color' => 0, 'tone' => (string) $l['tone']],
                $c['extra']->legend($this->app, $this->user()));
        } catch (\Throwable $e) {
            Log::error('{module}: la leyenda de los eventos de fuera ha fallado', ['module' => $this->module->name, 'exception' => $e]);
            return [];
        }
    }

    /** Alta con la fecha (y el fin, si lo hay) ya puestos (ModuleController::prefill) */
    private function newUrl(array $c, string $start): string
    {
        $v = [$c['date']->name => $start];
        if ($c['end'] !== null && strlen($start) > 10) {
            $v[$c['end']->name] = (new DateTimeImmutable($start))->modify('+' . $c['duration'] . ' minutes')->format('Y-m-d\TH:i');
        }
        return $this->app->url('edit', ['m' => $c['source']->name, 'v' => $v]);
    }

    private function monthLabel(DateTimeImmutable $d): string
    {
        $label = $this->text('cal.months.' . ((int) $d->format('n') - 1)) . ' ' . $d->format('Y');
        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }

    /** «3 – 8 nov. 2026», «27 oct. – 1 nov. 2026» */
    private function rangeLabel(DateTimeImmutable $a, DateTimeImmutable $b): string
    {
        return $this->text('cal.range', [
            'from' => $a->format('j') . ($a->format('m') !== $b->format('m') ? ' ' . $this->text('cal.months_short.' . ((int) $a->format('n') - 1)) : ''),
            'to' => $b->format('j') . ' ' . $this->text('cal.months_short.' . ((int) $b->format('n') - 1)),
            'year' => $b->format('Y'),
        ]);
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
