<?php
/**
 * Página "calendar" (Quasar\Pages\CalendarPage). Las URL llegan sin escapar.
 *
 * @var \Quasar\Core\View $this
 * @var list<list<array{date:string,day:int,inMonth:bool,isToday:bool,weekend:bool,events:list<array>,newUrl:?string}>> $weeks
 * @var list<string> $weekdays
 * @var string $monthLabel
 * @var string $prevUrl
 * @var string $nextUrl
 * @var string $todayUrl
 * @var bool $isCurrentMonth
 * @var ?string $newUrl
 * @var list<array{label:string,color:int,tone:?string}> $legend
 * @var int $total
 * @var int $maxPerDay
 */
$dateFmt = $this->t('format.date');
// Color de un evento/leyenda: el "colors" del campo si lo hay; si no, la paleta por posición
$tone = static function (?string $tone, int $index): string {
    if ($tone === null) {
        return 'class="%s qs-cal-c' . $index . '"';
    }
    return str_starts_with($tone, '#')
        ? 'class="%s qs-toned" style="--t:' . $tone . '"'
        : 'class="%s qs-toned qs-tone-' . $tone . '"';
};
?>
<div class="qs-calendar">
  <div class="qs-toolbar">
    <?php if ($newUrl): ?>
      <a class="qs-btn qs-btn-primary" href="<?= $this->e($newUrl) ?>" data-shortcut="new" title="Alt+N">
        <?= $this->icon('plus') ?> <?= $this->e($this->t('new')) ?>
      </a>
    <?php endif ?>
    <span class="qs-cal-nav">
      <a class="qs-btn qs-btn-sm" href="<?= $this->e($prevUrl) ?>" title="<?= $this->e($this->t('prev')) ?>" aria-label="<?= $this->e($this->t('prev')) ?>"><?= $this->icon('chevron-left') ?></a>
      <a class="qs-btn qs-btn-sm<?= $isCurrentMonth ? ' is-active' : '' ?>" href="<?= $this->e($todayUrl) ?>"><?= $this->e($this->t('today')) ?></a>
      <a class="qs-btn qs-btn-sm" href="<?= $this->e($nextUrl) ?>" title="<?= $this->e($this->t('next')) ?>" aria-label="<?= $this->e($this->t('next')) ?>"><?= $this->icon('chevron-right') ?></a>
    </span>
    <span class="qs-toolbar-spacer"></span>
    <?php foreach ($legend as $item): ?>
      <span class="qs-cal-legend"><span <?= sprintf($tone($item['tone'], (int) $item['color']), 'qs-cal-dot') ?>></span><?= $this->e($item['label']) ?></span>
    <?php endforeach ?>
  </div>

  <div class="qs-cal" role="grid" aria-label="<?= $this->e($monthLabel) ?>">
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
          $label = date($dateFmt, (int) strtotime($day['date']));
          ?>
          <div class="<?= implode(' ', $classes) ?>" role="gridcell" aria-label="<?= $this->e($label) ?>">
            <div class="qs-cal-day-head">
              <span class="qs-cal-num"><?= $day['day'] ?></span>
              <?php if ($day['newUrl']): ?>
                <a class="qs-icon-btn qs-cal-add" href="<?= $this->e($day['newUrl']) ?>"
                   title="<?= $this->e($this->t('add_on', ['date' => $label])) ?>" aria-label="<?= $this->e($this->t('add_on', ['date' => $label])) ?>"><?= $this->icon('plus') ?></a>
              <?php endif ?>
            </div>
            <?php foreach (array_slice($day['events'], 0, $maxPerDay) as $ev): ?>
              <a <?= sprintf($tone($ev['tone'], (int) $ev['color']), 'qs-cal-event') ?> href="<?= $this->e($ev['url']) ?>"
                 title="<?= $this->e(trim(($ev['time'] ? $ev['time'] . ' · ' : '') . $ev['title'] . ($ev['colorLabel'] ? ' · ' . $ev['colorLabel'] : ''))) ?>">
                <?php if ($ev['time']): ?><span class="qs-cal-time"><?= $this->e($ev['time']) ?></span><?php endif ?>
                <span class="qs-cal-title"><?= $this->e($ev['title']) ?></span>
              </a>
            <?php endforeach ?>
            <?php if (count($day['events']) > $maxPerDay): ?>
              <?php $rest = array_slice($day['events'], $maxPerDay); ?>
              <span class="qs-cal-more" title="<?= $this->e(implode("\n", array_map(static fn ($e) => trim(($e['time'] ?? '') . ' ' . $e['title']), $rest))) ?>">
                <?= $this->e($this->t('more', ['n' => count($rest)])) ?>
              </span>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
    <?php endforeach ?>
  </div>
  <?php if ($total === 0): ?>
    <p class="qs-help"><?= $this->e($this->t('empty')) ?></p>
  <?php endif ?>
</div>
