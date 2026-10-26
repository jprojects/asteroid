<?php
/**
 * Vista de tabla. El contenido de las filas lo pinta app.js a partir del
 * endpoint JSON (r=data); aquí solo se define la estructura.
 *
 * @var \Quasar\Core\View $this
 * @var \Quasar\Core\Module $module
 * @var array $columns
 * @var array $filters
 * @var bool $hasSearch
 * @var list<int> $pageSizes
 * @var bool $canCreate
 * @var list<string> $exportFormats
 * @var list<array<string,mixed>> $toolbarButtons botones propios ("list.toolbar")
 * @var ?array{url:string,blocked:bool,message:?string} $ai barra de filtrar con lenguaje natural
 * @var array $config
 */
?>
<div class="qs-datatable" data-config="<?= $this->json($config) ?>">
  <?php
$formId = 'qs-filters-' . $module->name;
foreach ($module->rowActions() as $action) {
    $this->useIcon($action['icon']); // las filas las pinta app.js con el sprite
}
?>
  <div class="qs-toolbar">
    <?php if ($canCreate): ?>
      <a class="qs-btn qs-btn-primary" data-shortcut="new" title="Alt+N"
         href="<?= $this->url('edit', ['m' => $module->name]) ?>"><?= $this->icon('plus') ?> <?= $this->e($this->t('actions.new')) ?></a>
    <?php endif ?>
    <?php if ($hasSearch): ?>
      <!-- Fuera del <form> pero asociado a él con form="…": se envía junto a los filtros -->
      <input class="qs-input qs-toolbar-search" type="search" name="q" form="<?= $this->e($formId) ?>"
             placeholder="<?= $this->e($this->t('actions.search')) ?>" aria-label="<?= $this->e($this->t('actions.search')) ?>"
             title="/" data-search>
    <?php endif ?>
    <?php if ($filters): ?>
      <span class="qs-filter-toggle-group">
        <button type="button" class="qs-btn" data-filters-toggle aria-controls="<?= $this->e($formId) ?>" aria-expanded="false">
          <?= $this->icon('funnel') ?>
          <?= $this->e($this->t('filter.toggle')) ?>
          <span class="qs-badge" data-filters-count hidden></span>
        </button>
        <button type="button" class="qs-btn qs-btn-ghost qs-btn-sm" data-filters-clear hidden
                title="<?= $this->e($this->t('filter.clear')) ?>" aria-label="<?= $this->e($this->t('filter.clear')) ?>"><?= $this->icon('x') ?></button>
      </span>
    <?php endif ?>
    <?= $this->render('buttons', ['buttons' => $toolbarButtons], null) ?>
    <span class="qs-toolbar-spacer"></span>
    <span class="qs-loading-indicator" aria-hidden="true"></span>
    <?php foreach ($exportFormats as $format): ?>
      <button type="button" class="qs-btn qs-btn-export" data-export="<?= $this->e($format) ?>" title="<?= $this->e($this->t('export.title')) ?>">
        <span class="qs-file-badge qs-file-<?= $this->e($format) ?>" aria-hidden="true"><?= $format === 'xlsx' ? 'XLS' : 'PDF' ?></span>
        <?= $this->e($this->t('export.' . ($format === 'xlsx' ? 'excel' : 'pdf'))) ?>
      </button>
    <?php endforeach ?>
  </div>

  <?php if ($ai): ?>
    <form class="qs-ai-bar<?= $ai['blocked'] ? ' is-blocked' : '' ?>" data-ai autocomplete="off" role="search">
      <?= $this->icon('sparkles', 'qs-ai-icon') ?>
      <input class="qs-input" type="text" name="ai_q" maxlength="300" <?= $ai['blocked'] ? 'disabled' : '' ?>
             placeholder="<?= $this->e($this->t('ai.placeholder')) ?>" aria-label="<?= $this->e($this->t('ai.label')) ?>">
      <button type="submit" class="qs-btn qs-btn-primary" <?= $ai['blocked'] ? 'disabled' : '' ?>><?= $this->e($this->t('ai.submit')) ?></button>
      <?php if ($ai['message'] !== null): ?>
        <span class="qs-ai-message"><?= $this->icon('triangle-alert') ?> <?= $this->e($ai['message']) ?></span>
      <?php endif ?>
    </form>
  <?php endif ?>

  <?php if ($hasSearch || $filters): ?>
    <form class="qs-filters" id="<?= $this->e($formId) ?>" autocomplete="off" role="search" hidden>
      <?php foreach ($filters as $flt): ?>
        <div class="qs-filter qs-filter-<?= $this->e($flt['operator'] === 'between' ? 'range' : 'single') ?>" data-filter="<?= $this->e($flt['field']->name) ?>">
          <label><?= $this->e($flt['label']) ?></label>
          <?= $flt['field']->renderFilter(null, $flt['operator']) ?>
        </div>
      <?php endforeach ?>
      <?php if ($filters): ?>
        <button type="button" class="qs-btn qs-btn-ghost qs-btn-sm" data-filters-clear><?= $this->e($this->t('filter.clear')) ?></button>
      <?php endif ?>
    </form>
  <?php endif ?>

  <div class="qs-chips" data-chips hidden aria-live="polite"></div>

  <div class="qs-table-wrap">
    <table class="qs-table">
      <thead>
        <tr>
          <?php if ($config['expand']): ?>
            <th scope="col" class="qs-col-expand">
              <button type="button" class="qs-icon-btn qs-expand-btn" data-expand-all aria-expanded="false"
                      title="<?= $this->e($this->t('table.expand_all')) ?>" aria-label="<?= $this->e($this->t('table.expand_all')) ?>"><?= $this->icon('chevron-right') ?></button>
            </th>
          <?php endif ?>
          <?php foreach ($columns as $c): ?>
            <?php
            $style = array_filter([
                'width' => $c['width'],
                'min-width' => $c['minWidth'],
                'max-width' => $c['maxWidth'],
            ]);
            ?>
            <th scope="col" data-field="<?= $this->e($c['field']->name) ?>"
                class="qs-align-<?= $this->e($c['align']) ?>"
                <?= $c['sortable'] ? 'data-sortable aria-sort="none"' : '' ?>
                <?= $style ? 'style="' . $this->e(implode(';', array_map(static fn ($k, $v) => "$k:$v", array_keys($style), $style))) . '"' : '' ?>>
              <?= $this->e($c['label']) ?>
            </th>
          <?php endforeach ?>
          <th scope="col" class="qs-col-actions"></th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="qs-empty" colspan="<?= count($columns) + ($config['expand'] ? 2 : 1) ?>"><?= $this->e($this->t('table.loading')) ?></td></tr>
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
        <?php foreach ($pageSizes as $n): ?>
          <option value="<?= $n ?>"><?= $n ?></option>
        <?php endforeach ?>
      </select>
      <?= $this->e($this->t('table.per_page')) ?>
    </label>
  </div>
</div>
