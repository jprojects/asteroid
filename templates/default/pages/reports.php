<?php
/**
 * Informes con lenguaje natural: petición, resultado como tabla (app.js) e informes guardados.
 *
 * @var \Quasar\Core\View $this
 * @var \Quasar\Core\Module $module
 * @var bool $canAsk
 * @var bool $canSave
 * @var bool $canExport
 * @var ?\Quasar\Reports\Report $report
 * @var bool $isOwner
 * @var list<array{id:int|string,name:string,title:string}> $saved
 * @var list<array{id:int|string,title:string,created_at:string}> $recent
 * @var string $askUrl
 * @var ?array $table configuración de la tabla de app.js
 */
$link = fn ($id) => $this->e($module->url(['id' => $id]));
?>
<div class="qs-reports">
  <div class="qs-reports-main">
    <?php if ($canAsk): ?>
      <form class="qs-ai-bar qs-report-ask" data-report-ask action="<?= $this->e($askUrl) ?>" autocomplete="off">
        <?= $this->icon('sparkles', 'qs-ai-icon') ?>
        <textarea class="qs-input" name="q" rows="2" maxlength="1000" required
                  placeholder="<?= $this->e($this->t('ask.placeholder')) ?>" aria-label="<?= $this->e($this->t('ask.label')) ?>"><?= $report !== null ? $this->e($report->prompt) : '' ?></textarea>
        <button type="submit" class="qs-btn qs-btn-primary"><?= $this->e($this->t('ask.submit')) ?></button>
      </form>
    <?php endif ?>

    <?php if ($report === null): ?>
      <div class="qs-report-empty">
        <?= $this->icon('file-chart-column') ?>
        <p><?= $this->e($this->t($canAsk ? 'intro' : 'intro_readonly')) ?></p>
      </div>
    <?php else: ?>
      <section class="qs-report">
        <header class="qs-report-head">
          <div>
            <h2><?= $this->e($report->name ?? $report->title) ?></h2>
            <?php if ($report->explanation !== ''): ?><p class="qs-report-explanation"><?= $this->e($report->explanation) ?></p><?php endif ?>
          </div>
          <?php if ($canSave && $isOwner): ?>
            <div class="qs-report-actions">
              <form method="post" action="<?= $this->e($module->url(['a' => 'save'])) ?>" class="qs-report-save">
                <?= $this->csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $report->id ?>">
                <input class="qs-input" name="name" maxlength="150" required value="<?= $this->e($report->name ?? $report->title) ?>"
                       aria-label="<?= $this->e($this->t('save_name')) ?>">
                <button type="submit" class="qs-btn"><?= $this->icon('save') ?> <?= $this->e($this->t($report->name === null ? 'save' : 'rename')) ?></button>
              </form>
              <form method="post" action="<?= $this->e($module->url(['a' => 'delete'])) ?>">
                <?= $this->csrfField() ?>
                <input type="hidden" name="id" value="<?= (int) $report->id ?>">
                <button type="submit" class="qs-btn qs-btn-danger" data-confirm="<?= $this->e($this->t('confirm_delete')) ?>"
                        title="<?= $this->e($this->t('delete')) ?>" aria-label="<?= $this->e($this->t('delete')) ?>"><?= $this->icon('trash-2') ?></button>
              </form>
            </div>
          <?php endif ?>
        </header>
        <details class="qs-report-sql">
          <summary><?= $this->e($this->t('sql')) ?></summary>
          <pre><?= $this->e($report->query) ?></pre>
        </details>

        <div class="qs-datatable" data-config="<?= $this->json($table) ?>">
          <div class="qs-toolbar">
            <span class="qs-toolbar-spacer"></span>
            <span class="qs-loading-indicator" aria-hidden="true"></span>
            <?php if ($canExport): ?>
              <?php foreach (['xlsx', 'pdf'] as $format): ?>
                <button type="button" class="qs-btn qs-btn-export" data-export="<?= $format ?>" title="<?= $this->e($this->t('export.title')) ?>">
                  <span class="qs-file-badge qs-file-<?= $format ?>" aria-hidden="true"><?= $format === 'xlsx' ? 'XLS' : 'PDF' ?></span>
                  <?= $this->e($this->t('export.' . ($format === 'xlsx' ? 'excel' : 'pdf'))) ?>
                </button>
              <?php endforeach ?>
            <?php endif ?>
          </div>
          <div class="qs-table-wrap">
            <table class="qs-table">
              <thead>
                <tr>
                  <?php foreach ($table['columns'] as $c): ?>
                    <th scope="col" data-field="<?= $this->e($c['field']) ?>" class="qs-align-<?= $this->e($c['align']) ?>" data-sortable aria-sort="none"><?= $this->e($c['label']) ?></th>
                  <?php endforeach ?>
                  <th scope="col" class="qs-col-actions"></th>
                </tr>
              </thead>
              <tbody>
                <tr><td class="qs-empty" colspan="<?= count($table['columns']) + 1 ?>"><?= $this->e($this->t('table.loading')) ?></td></tr>
              </tbody>
            </table>
          </div>
          <div class="qs-statusbar">
            <span data-role="info"></span>
            <span class="qs-toolbar-spacer"></span>
            <div class="qs-pager">
              <button type="button" class="qs-btn qs-btn-sm" data-page="first" title="<?= $this->e($this->t('table.first')) ?>"><?= $this->icon('chevrons-left') ?></button>
              <button type="button" class="qs-btn qs-btn-sm" data-page="prev" title="<?= $this->e($this->t('table.prev')) ?>"><?= $this->icon('chevron-left') ?></button>
              <span class="qs-pager-pos">
                <input type="number" class="qs-input" min="1" value="1" data-role="page" aria-label="<?= $this->e($this->t('table.page')) ?>">
                / <span data-role="pages">1</span>
              </span>
              <button type="button" class="qs-btn qs-btn-sm" data-page="next" title="<?= $this->e($this->t('table.next')) ?>"><?= $this->icon('chevron-right') ?></button>
              <button type="button" class="qs-btn qs-btn-sm" data-page="last" title="<?= $this->e($this->t('table.last')) ?>"><?= $this->icon('chevrons-right') ?></button>
            </div>
            <label class="qs-pagesize">
              <select class="qs-input" data-role="size">
                <?php foreach ($table['pageSizes'] as $n): ?><option value="<?= $n ?>"><?= $n ?></option><?php endforeach ?>
              </select>
              <?= $this->e($this->t('table.per_page')) ?>
            </label>
          </div>
        </div>
      </section>
    <?php endif ?>
  </div>

  <aside class="qs-reports-side">
    <h3><?= $this->icon('bookmark') ?> <?= $this->e($this->t('saved')) ?></h3>
    <?php if (!$saved): ?><p class="qs-muted"><?= $this->e($this->t('saved_empty')) ?></p><?php endif ?>
    <ul>
      <?php foreach ($saved as $s): ?>
        <li<?= $report !== null && (int) $s['id'] === $report->id ? ' class="is-current"' : '' ?>><a href="<?= $link($s['id']) ?>" title="<?= $this->e($s['title']) ?>"><?= $this->e($s['name']) ?></a></li>
      <?php endforeach ?>
    </ul>
    <?php if ($recent): ?>
      <h3><?= $this->icon('history') ?> <?= $this->e($this->t('recent')) ?></h3>
      <ul>
        <?php foreach ($recent as $r): ?>
          <li<?= $report !== null && (int) $r['id'] === $report->id ? ' class="is-current"' : '' ?>><a href="<?= $link($r['id']) ?>"><?= $this->e($r['title']) ?></a></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </aside>
</div>
