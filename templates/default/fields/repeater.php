<?php
/**
 * Campo repeater. Cada fila contiene los subcampos ya preparados con su
 * name/id propio; las filas nuevas las crea app.js clonando el <template>
 * y sustituyendo $placeholder por un índice único.
 *
 * @var \Quasar\Core\View $this
 * @var string $id
 * @var string $layout     table | block
 * @var array $headers     etiquetas de los subcampos
 * @var list<list<array{field:\Quasar\Fields\Field,value:mixed,error:?string}>> $rows
 * @var ?list<array> $template
 * @var string $placeholder
 * @var int $min
 * @var int $max
 * @var bool $readonly
 * @var array $opts
 * @var string $addLabel
 */
$visibleCols = count(array_filter($headers, static fn ($h) => !$h['hidden']));

$renderRow = function (array $items) use ($layout, $readonly, $opts, $visibleCols): string {
    ob_start(); ?>
    <div class="qs-repeater-row" data-row>
      <?php if (!$readonly): ?>
        <span class="qs-repeater-handle" data-drag-handle title="<?= $this->e($this->t('repeater.drag')) ?>" aria-hidden="true"><?= $this->icon('grip-vertical') ?></span>
      <?php endif ?>
      <div class="<?= $layout === 'table' ? 'qs-repeater-cells' : 'qs-grid' ?>"
           <?= $layout === 'table' ? 'style="--qs-cols:' . $visibleCols . '"' : '' ?>>
        <?php foreach ($items as $item): $f = $item['field']; ?>
          <?php if ($f->isHidden()): ?>
            <?= $f->render($item['value'], $opts) ?>
            <?php continue ?>
          <?php endif ?>
          <div class="qs-field qs-w-<?= $this->e($f->width()) ?> qs-type-<?= $this->e($f->type()) ?><?= $item['error'] ? ' has-error' : '' ?><?= !$readonly && $f->isRequired($opts['isNew']) ? ' is-required' : '' ?>"
               data-field-name="<?= $this->e($f->name) ?>"
               <?= $f->showOn() ? 'data-showon="' . $this->json($f->showOn()) . '"' : '' ?>
               <?= $item['visible'] ? '' : 'hidden' ?>>
            <label for="<?= $this->e($f->inputId()) ?>"><?= $this->e($f->label()) ?></label>
            <?= $f->render($item['value'], $opts) ?>
            <?php if ($item['error']): ?><div class="qs-error"><?= $this->e($item['error']) ?></div><?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
      <?php if (!$readonly): ?>
        <div class="qs-repeater-tools">
          <button type="button" class="qs-icon-btn" data-move="up" title="<?= $this->e($this->t('repeater.move_up')) ?>"><?= $this->icon('arrow-up') ?></button>
          <button type="button" class="qs-icon-btn" data-move="down" title="<?= $this->e($this->t('repeater.move_down')) ?>"><?= $this->icon('arrow-down') ?></button>
          <button type="button" class="qs-icon-btn qs-danger" data-remove title="<?= $this->e($this->t('repeater.remove')) ?>"><?= $this->icon('x') ?></button>
        </div>
      <?php endif ?>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<div class="qs-repeater qs-repeater-<?= $this->e($layout) ?><?= $readonly ? ' is-readonly' : '' ?>" id="<?= $this->e($id) ?>"
     data-repeater data-placeholder="<?= $this->e($placeholder) ?>" data-min="<?= $min ?>" data-max="<?= $max ?>">
  <?php if ($layout === 'table'): ?>
    <!-- Misma estructura que una fila (con asa y botones invisibles) para que las columnas queden alineadas -->
    <div class="qs-repeater-head-row" aria-hidden="true">
      <?php if (!$readonly): ?><span class="qs-repeater-handle"><?= $this->icon('grip-vertical') ?></span><?php endif ?>
      <div class="qs-repeater-head" style="--qs-cols:<?= $visibleCols ?>">
        <?php foreach ($headers as $h): if ($h['hidden']) continue; ?>
          <span class="<?= !$readonly && $h['required'] ? 'is-required' : '' ?>"><?= $this->e($h['label']) ?></span>
        <?php endforeach ?>
      </div>
      <?php if (!$readonly): ?>
        <span class="qs-repeater-tools"><span class="qs-icon-btn"></span><span class="qs-icon-btn"></span><span class="qs-icon-btn"></span></span>
      <?php endif ?>
    </div>
  <?php endif ?>

  <div class="qs-repeater-rows" data-rows>
    <?php foreach ($rows as $items): ?>
      <?= $renderRow($items) ?>
    <?php endforeach ?>
  </div>
  <div class="qs-repeater-empty" data-empty><?= $this->e($this->t('repeater.empty')) ?></div>

  <?php if ($template !== null): ?>
    <template data-row-template><?= $renderRow($template) ?></template>
    <div class="qs-repeater-footer">
      <button type="button" class="qs-btn qs-btn-sm" data-add><?= $this->icon('plus') ?> <?= $this->e($addLabel) ?></button>
      <?php if ($max > 0): ?><span class="qs-muted" data-counter></span><?php endif ?>
    </div>
  <?php endif ?>
</div>
