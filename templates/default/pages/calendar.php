<?php
/**
 * Página "calendar" (Quasar\Pages\CalendarPage): vista de mes o rejilla de horas (semana y día). Las URL llegan
 * sin escapar. Los textos genéricos son cal.* de common.json; cada calendario puede redefinirlos en su idioma.
 *
 * @var \Quasar\Core\View $this
 * @var string $view   month | week | day
 * @var list<array{view:string,label:string,url:string}> $views  selector de vista (vacío si solo hay una)
 * @var string $label
 * @var string $prevUrl
 * @var string $nextUrl
 * @var string $todayUrl
 * @var bool $isCurrent
 * @var ?string $newUrl
 * @var list<array{label:string,color:int,tone:?string}> $legend
 * @var int $total
 * @var int $maxPerDay
 *
 * Mes:      list<list<array{date:string,day:int,inMonth:bool,isToday:bool,weekend:bool,events:list<array>,newUrl:?string,dayUrl:?string}>> $weeks,
 *           list<string> $weekdays
 * Semana/día: list<array{date:string,label:string,isToday:bool,weekend:bool,untimed:list<array>,events:list<array>,newUrl:?string,dayUrl:?string}> $columns,
 *           list<?string> $slots, int $span, array $grid (configuración para app.js)
 */
$dateFmt = $this->t('format.date');
// Color de un evento/leyenda: el "colors" del campo o el color guardado; si no, la paleta por posición
$tone = static function (?string $tone, int $index, string $style = ''): string {
    $style = str_replace('%', '%%', $style); // el resultado pasa por sprintf()
    if ($tone === null) {
        return 'class="%s qs-cal-c' . $index . '"' . ($style !== '' ? ' style="' . $style . '"' : '');
    }
    return str_starts_with($tone, '#')
        ? 'class="%s qs-toned" style="--t:' . $tone . ';' . $style . '"'
        : 'class="%s qs-toned qs-tone-' . $tone . '"' . ($style !== '' ? ' style="' . $style . '"' : '');
};
$eventTitle = fn (array $ev): string => trim(
    ($ev['time'] ? $ev['time'] . ($ev['timeEnd'] ? '–' . $ev['timeEnd'] : '') . ' · ' : '') . $ev['title'] . ($ev['colorLabel'] ? ' · ' . $ev['colorLabel'] : '')
);
$navLabel = ['month' => ['cal.prev_month', 'cal.next_month'], 'week' => ['cal.prev_week', 'cal.next_week'], 'day' => ['cal.prev_day', 'cal.next_day']][$view];
?>
<div class="qs-calendar qs-cal-view-<?= $this->e($view) ?>">
  <div class="qs-toolbar">
    <?php if ($newUrl): ?>
      <a class="qs-btn qs-btn-primary" href="<?= $this->e($newUrl) ?>" data-shortcut="new" title="Alt+N">
        <?= $this->icon('plus') ?> <?= $this->e($this->t('cal.new')) ?>
      </a>
    <?php endif ?>
    <span class="qs-cal-nav">
      <a class="qs-btn qs-btn-sm" href="<?= $this->e($prevUrl) ?>" title="<?= $this->e($this->t($navLabel[0])) ?>" aria-label="<?= $this->e($this->t($navLabel[0])) ?>"><?= $this->icon('chevron-left') ?></a>
      <a class="qs-btn qs-btn-sm<?= $isCurrent ? ' is-active' : '' ?>" href="<?= $this->e($todayUrl) ?>"><?= $this->e($this->t('cal.today')) ?></a>
      <a class="qs-btn qs-btn-sm" href="<?= $this->e($nextUrl) ?>" title="<?= $this->e($this->t($navLabel[1])) ?>" aria-label="<?= $this->e($this->t($navLabel[1])) ?>"><?= $this->icon('chevron-right') ?></a>
    </span>
    <strong class="qs-cal-label"><?= $this->e($label) ?></strong>
    <span class="qs-toolbar-spacer"></span>
    <?php foreach ($legend as $item): ?>
      <span class="qs-cal-legend"><span <?= sprintf($tone($item['tone'], (int) $item['color']), 'qs-cal-dot') ?>></span><?= $this->e($item['label']) ?></span>
    <?php endforeach ?>
    <?php if ($views): ?>
      <span class="qs-cal-views" role="group" aria-label="<?= $this->e($this->t('cal.view')) ?>">
        <?php foreach ($views as $v): ?>
          <a class="qs-btn qs-btn-sm<?= $v['view'] === $view ? ' is-active' : '' ?>" href="<?= $this->e($v['url']) ?>"
             <?= $v['view'] === $view ? 'aria-current="page"' : '' ?>><?= $this->e($v['label']) ?></a>
        <?php endforeach ?>
      </span>
    <?php endif ?>
  </div>
  <?php if (!empty($notice) && ($notice['text'] !== '' || $notice['links'])): ?>
    <div class="qs-toolbar qs-cal-notice">
      <?php if ($notice['text'] !== ''): ?><span class="qs-muted"><?= $this->e($notice['text']) ?></span><?php endif ?>
      <?php foreach ($notice['links'] as $l): ?>
        <a class="qs-btn qs-btn-sm<?= !empty($l['active']) ? ' is-active' : '' ?>" href="<?= $this->e((string) $l['url']) ?>"<?= !empty($l['active']) ? ' aria-current="true"' : '' ?>><?= $this->e((string) $l['label']) ?></a>
      <?php endforeach ?>
    </div>
  <?php endif ?>

  <?php if ($view === 'month'): ?>
    <div class="qs-cal" role="grid" aria-label="<?= $this->e($label) ?>">
      <div class="qs-cal-head" role="row">
        <?php foreach ($weekdays as $wd): ?>
          <div role="columnheader"><?= $this->e($wd) ?></div>
        <?php endforeach ?>
      </div>
      <?php foreach ($weeks as $week): ?>
        <div class="qs-cal-week" role="row">
          <?php foreach ($week as $day): ?>
            <?php
            $classes = ['qs-cal-day'];
            if (!$day['inMonth']) { $classes[] = 'is-other'; }
            if ($day['isToday']) { $classes[] = 'is-today'; }
            if ($day['weekend']) { $classes[] = 'is-weekend'; }
            $dayLabel = date($dateFmt, (int) strtotime($day['date']));
            ?>
            <div class="<?= implode(' ', $classes) ?>" role="gridcell" aria-label="<?= $this->e($dayLabel) ?>">
              <div class="qs-cal-day-head">
                <?php if ($day['dayUrl']): ?>
                  <a class="qs-cal-num" href="<?= $this->e($day['dayUrl']) ?>" title="<?= $this->e($dayLabel) ?>"><?= $day['day'] ?></a>
                <?php else: ?>
                  <span class="qs-cal-num"><?= $day['day'] ?></span>
                <?php endif ?>
                <?php if ($day['newUrl']): ?>
                  <a class="qs-icon-btn qs-cal-add" href="<?= $this->e($day['newUrl']) ?>"
                     title="<?= $this->e($this->t('cal.add_on', ['date' => $dayLabel])) ?>" aria-label="<?= $this->e($this->t('cal.add_on', ['date' => $dayLabel])) ?>"><?= $this->icon('plus') ?></a>
                <?php endif ?>
              </div>
              <?php foreach (array_slice($day['events'], 0, $maxPerDay) as $ev): ?>
                <a <?= sprintf($tone($ev['tone'], (int) $ev['color']), 'qs-cal-event' . ($ev['external'] ? ' is-fixed' : '')) ?> href="<?= $this->e($ev['url']) ?>" title="<?= $this->e($eventTitle($ev)) ?>"<?= $ev['external'] ? ' target="_blank" rel="noopener"' : '' ?>>
                  <?php if ($ev['time']): ?><span class="qs-cal-time"><?= $this->e($ev['time']) ?></span><?php endif ?>
                  <span class="qs-cal-title"><?= $this->e($ev['title']) ?></span>
                </a>
              <?php endforeach ?>
              <?php if (count($day['events']) > $maxPerDay): ?>
                <?php $rest = array_slice($day['events'], $maxPerDay); ?>
                <?php $moreTitle = implode("\n", array_map(static fn ($e) => trim(($e['time'] ?? '') . ' ' . $e['title']), $rest)); ?>
                <?php if ($day['dayUrl']): ?>
                  <a class="qs-cal-more" href="<?= $this->e($day['dayUrl']) ?>" title="<?= $this->e($moreTitle) ?>"><?= $this->e($this->t('cal.more', ['n' => count($rest)])) ?></a>
                <?php else: ?>
                  <span class="qs-cal-more" title="<?= $this->e($moreTitle) ?>"><?= $this->e($this->t('cal.more', ['n' => count($rest)])) ?></span>
                <?php endif ?>
              <?php endif ?>
            </div>
          <?php endforeach ?>
        </div>
      <?php endforeach ?>
    </div>

  <?php else: ?>
    <?php $hasUntimed = (bool) array_filter($columns, static fn ($c) => $c['untimed']); ?>
    <?php $slotH = max(16, intdiv(48 * $grid['slot'], 60)); // una hora: 48 px como mínimo, y una franja, 16 ?>
    <div class="qs-cal-tgrid<?= $grid['moveUrl'] ? ' qs-cal-grid-edit' : '' ?><?= $grid['newUrl'] ? ' qs-cal-grid-new' : '' ?>"
         style="--cols: <?= count($columns) ?>; --slot-h: <?= $slotH ?>px"
         data-cal-grid="<?= $this->json($grid) ?>" role="grid" aria-label="<?= $this->e($label) ?>">
      <div class="qs-cal-thead" role="row">
        <div class="qs-cal-gutter"></div>
        <?php foreach ($columns as $col): ?>
          <div class="qs-cal-colhead<?= $col['isToday'] ? ' is-today' : '' ?>" role="columnheader">
            <?php if ($col['dayUrl']): ?>
              <a href="<?= $this->e($col['dayUrl']) ?>"><?= $this->e($col['label']) ?></a>
            <?php else: ?>
              <span><?= $this->e($col['label']) ?></span>
            <?php endif ?>
            <?php if ($col['newUrl']): ?>
              <a class="qs-icon-btn qs-cal-add" href="<?= $this->e($col['newUrl']) ?>"
                 title="<?= $this->e($this->t('cal.add_on', ['date' => date($dateFmt, (int) strtotime($col['date']))])) ?>"
                 aria-label="<?= $this->e($this->t('cal.add_on', ['date' => date($dateFmt, (int) strtotime($col['date']))])) ?>"><?= $this->icon('plus') ?></a>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
      <?php if ($hasUntimed): ?>
        <div class="qs-cal-untimed" role="row">
          <div class="qs-cal-gutter"><?= $this->e($this->t('cal.no_time')) ?></div>
          <?php foreach ($columns as $col): ?>
            <div>
              <?php foreach ($col['untimed'] as $ev): ?>
                <a <?= sprintf($tone($ev['tone'], (int) $ev['color']), 'qs-cal-event' . ($ev['external'] ? ' is-fixed' : '')) ?> href="<?= $this->e($ev['url']) ?>" title="<?= $this->e($eventTitle($ev)) ?>"<?= $ev['external'] ? ' target="_blank" rel="noopener"' : '' ?>>
                  <span class="qs-cal-title"><?= $this->e($ev['title']) ?></span>
                </a>
              <?php endforeach ?>
            </div>
          <?php endforeach ?>
        </div>
      <?php endif ?>
      <div class="qs-cal-tbody">
        <div class="qs-cal-gutter qs-cal-hours" aria-hidden="true">
          <?php foreach ($slots as $slotLabel): ?>
            <div class="qs-cal-slot<?= $slotLabel !== null ? ' is-hour' : '' ?>"><?= $slotLabel !== null ? $this->e($slotLabel) : '' ?></div>
          <?php endforeach ?>
        </div>
        <?php foreach ($columns as $col): ?>
          <div class="qs-cal-col<?= $col['isToday'] ? ' is-today' : '' ?><?= $col['weekend'] ? ' is-weekend' : '' ?>" data-date="<?= $this->e($col['date']) ?>" role="gridcell">
            <?php foreach ($slots as $slotLabel): ?>
              <div class="qs-cal-slot<?= $slotLabel !== null ? ' is-hour' : '' ?>"></div>
            <?php endforeach ?>
            <?php foreach ($col['events'] as $ev): ?>
              <?php
              $style = sprintf('top:%.4f%%;height:%.4f%%;left:%.4f%%;width:%.4f%%',
                  $ev['top'] / $span * 100, ($ev['bottom'] - $ev['top']) / $span * 100,
                  $ev['lane'] / $ev['lanes'] * 100, 100 / $ev['lanes']);
              ?>
              <a <?= sprintf($tone($ev['tone'], (int) $ev['color'], $style), 'qs-cal-tevent' . ($ev['clipped'] ? ' is-clipped' : '') . ($ev['external'] ? ' is-fixed' : '')) ?>
                 href="<?= $this->e($ev['url']) ?>" title="<?= $this->e($eventTitle($ev)) ?>"
                 <?php if ($ev['external']): ?>target="_blank" rel="noopener"<?php else: ?>data-id="<?= $this->e($ev['id']) ?>" data-start="<?= $this->e($ev['start']) ?>" data-end="<?= $this->e((string) $ev['end']) ?>"<?php endif ?>>
                <span class="qs-cal-time"><?= $this->e($ev['time'] . ($ev['timeEnd'] ? '–' . $ev['timeEnd'] : '')) ?></span>
                <span class="qs-cal-title"><?= $this->e($ev['title']) ?></span>
                <?php if ($grid['moveUrl'] && !$ev['clipped'] && !$ev['external']): ?><span class="qs-cal-resize" aria-hidden="true"></span><?php endif ?>
              </a>
            <?php endforeach ?>
          </div>
        <?php endforeach ?>
      </div>
    </div>
  <?php endif ?>

  <?php if ($total === 0): ?>
    <p class="qs-help"><?= $this->e($this->t('cal.empty')) ?></p>
  <?php endif ?>
</div>
