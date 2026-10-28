<?php
/**
 * Asteroid · página "pissarra" (Quasar\Pages\PissarraPage). Las URL llegan sin escapar.
 * Los estilos van aquí y no en style.css, que es del núcleo.
 *
 * @var \Quasar\Core\View $this
 * @var list<array> $cards
 * @var list<array{key:string,label:string,url:string,active:bool}> $tabs
 * @var int $total
 * @var ?string $newUrl
 */
$dateFmt = $this->t('format.date');
$date = static fn (string $d): string => date($dateFmt, (int) strtotime($d));
$priorityTone = [2 => 'red', 1 => 'orange', 0 => 'gray'];
$priorityKey = [2 => 'panic', 1 => 'urgent', 0 => 'normal'];
?>
<style>
.ast-tabs { flex-wrap: wrap; gap: 4px; }
.ast-board { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(290px, 100%), 1fr)); gap: 14px; }
.ast-card {
  display: flex; flex-direction: column; gap: 10px; padding: 14px 16px;
  background: var(--surface); border: 1px solid var(--border); border-left: 4px solid var(--primary);
  border-radius: var(--radius);
}
.ast-card.is-panic { border-left-color: var(--tone-red); background: color-mix(in srgb, var(--tone-red) 6%, var(--surface)); }
.ast-card.is-urgent { border-left-color: var(--tone-orange); }
.ast-card-head { display: flex; align-items: flex-start; gap: 10px; }
.ast-card-title { flex: 1; min-width: 0; }
.ast-card-title h2 { margin: 0; font-size: 15px; line-height: 1.3; }
.ast-card-title h2 a { color: var(--text); text-decoration: none; }
.ast-card-title h2 a:hover { color: var(--primary); }
.ast-card-client { color: var(--text-muted); font-size: 12px; }
.ast-count { text-align: center; line-height: 1; }
.ast-count strong { display: block; font-size: 28px; font-weight: 700; }
.ast-count span { font-size: 11px; color: var(--text-muted); }
.ast-card.is-panic .ast-count strong { color: var(--tone-red); }
.ast-tags { display: flex; flex-wrap: wrap; gap: 5px; }
.ast-meta { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 12px; color: var(--text-muted); }
.ast-meta span { display: inline-flex; align-items: center; gap: 4px; }
.ast-meta svg { width: 14px; height: 14px; }
.ast-late { color: var(--tone-red); font-weight: 600; }
.ast-issues { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--border); }
.ast-issues li { border-bottom: 1px solid var(--border); }
.ast-issues a { display: flex; align-items: center; gap: 7px; padding: 6px 0; color: var(--text); text-decoration: none; font-size: 12.5px; }
.ast-issues a:hover .ast-issue-title { color: var(--primary); }
.ast-dot { flex-shrink: 0; width: 8px; height: 8px; border-radius: 50%; background: var(--t); }
.ast-issue-id { color: var(--text-muted); font-variant-numeric: tabular-nums; }
.ast-issue-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ast-issue-side { flex-shrink: 0; color: var(--text-muted); font-size: 11.5px; }
.ast-card-foot { margin-top: auto; }
.ast-empty { padding: 40px 16px; text-align: center; color: var(--text-muted); background: var(--surface); border: 1px dashed var(--border); border-radius: var(--radius); }
</style>

<div class="qs-toolbar">
  <?php if ($newUrl): ?>
    <a class="qs-btn qs-btn-primary" href="<?= $this->e($newUrl) ?>" data-shortcut="new" title="Alt+N">
      <?= $this->icon('plus') ?> <?= $this->e($this->t('new')) ?>
    </a>
  <?php endif ?>
  <span class="qs-filter-toggle-group ast-tabs" role="group" aria-label="<?= $this->e($this->t('issues:fields.worker_id')) ?>">
    <?php foreach ($tabs as $tab): ?>
      <a class="qs-btn qs-btn-sm<?= $tab['active'] ? ' is-active' : '' ?>" href="<?= $this->e($tab['url']) ?>"
         <?= $tab['active'] ? 'aria-current="page"' : '' ?>><?= $this->e($tab['label']) ?></a>
    <?php endforeach ?>
  </span>
  <span class="qs-toolbar-spacer"></span>
  <?php if ($cards): ?>
    <span class="ast-card-client"><?= $this->e($this->t('summary', ['issues' => $total, 'projects' => count($cards)])) ?></span>
  <?php endif ?>
</div>

<?php if (!$cards): ?>
  <div class="ast-empty"><?= $this->icon('circle-check') ?> <?= $this->e($this->t('empty')) ?></div>
<?php else: ?>
  <div class="ast-board">
    <?php foreach ($cards as $c): ?>
      <section class="ast-card<?= $c['panic'] ? ' is-panic' : ($c['urgent'] ? ' is-urgent' : '') ?>">
        <div class="ast-card-head">
          <div class="ast-card-title">
            <h2><a href="<?= $this->e($c['url']) ?>"><?= $this->e($c['project']) ?></a></h2>
            <?php if ($c['client'] !== ''): ?><div class="ast-card-client"><?= $this->e($c['client']) ?></div><?php endif ?>
          </div>
          <div class="ast-count"><strong><?= (int) $c['open'] ?></strong><span><?= $this->e($this->t('open')) ?></span></div>
        </div>

        <div class="ast-tags">
          <?php if ($c['panic']): ?><span class="qs-tag qs-tone-red"><?= $this->e($this->t('issues:priority.panic')) ?> · <?= (int) $c['panic'] ?></span><?php endif ?>
          <?php if ($c['urgent']): ?><span class="qs-tag qs-tone-orange"><?= $this->e($this->t('issues:priority.urgent')) ?> · <?= (int) $c['urgent'] ?></span><?php endif ?>
          <?php if ($c['pending']): ?><span class="qs-tag qs-tone-red"><?= $this->e($this->t('issues:status.pending')) ?> · <?= (int) $c['pending'] ?></span><?php endif ?>
          <?php if ($c['in_progress']): ?><span class="qs-tag qs-tone-blue"><?= $this->e($this->t('issues:status.in_progress')) ?> · <?= (int) $c['in_progress'] ?></span><?php endif ?>
        </div>

        <?php if ($c['estimated'] || $c['worked'] || $c['due']): ?>
          <div class="ast-meta">
            <?php if ($c['estimated']): ?><span title="<?= $this->e($this->t('issues:fields.estimated_minutes')) ?>"><?= $this->icon('clock') ?><?= $this->e($this->t('estimated', ['time' => $c['estimated']])) ?></span><?php endif ?>
            <?php if ($c['worked']): ?><span title="<?= $this->e($this->t('issues:fields.worked_minutes')) ?>"><?= $this->icon('timer') ?><?= $this->e($this->t('worked', ['time' => $c['worked']])) ?></span><?php endif ?>
            <?php if ($c['due']): ?>
              <span class="<?= $c['overdue'] ? 'ast-late' : '' ?>" title="<?= $this->e($this->t('issues:fields.due_on')) ?>">
                <?= $this->icon('calendar-clock') ?><?= $this->e($this->t($c['overdue'] ? 'overdue' : 'due', ['date' => $date($c['due'])])) ?>
              </span>
            <?php endif ?>
          </div>
        <?php endif ?>

        <ul class="ast-issues">
          <?php foreach ($c['issues'] as $i): ?>
            <li>
              <a href="<?= $this->e($i['url']) ?>">
                <span class="ast-dot qs-tone-<?= $priorityTone[$i['priority']] ?? 'gray' ?>"
                      title="<?= $this->e($this->t('issues:priority.' . ($priorityKey[$i['priority']] ?? 'normal'))) ?>"></span>
                <span class="ast-issue-id">#<?= (int) $i['id'] ?></span>
                <span class="ast-issue-title"><?= $this->e($i['title']) ?></span>
                <?php if ($i['worker'] !== null): ?>
                  <span class="ast-issue-side"><?= $this->e($i['worker']) ?></span>
                <?php elseif ($i['due'] !== null): ?>
                  <span class="ast-issue-side<?= $i['overdue'] ? ' ast-late' : '' ?>"><?= $this->e($date($i['due'])) ?></span>
                <?php endif ?>
              </a>
            </li>
          <?php endforeach ?>
        </ul>

        <div class="ast-card-foot">
          <a class="qs-btn qs-btn-sm" href="<?= $this->e($c['url']) ?>">
            <?= $this->e($this->t('see_all')) ?> <?= $this->icon('arrow-right') ?>
          </a>
        </div>
      </section>
    <?php endforeach ?>
  </div>
<?php endif ?>
