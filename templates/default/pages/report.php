<?php
/**
 * Informe PDF ("type": "report"): formulario con sus filtros. El PDF se abre en una pestaña nueva;
 * dentro de la ventana que abre el listado ("list.reports"), la ventana se cierra al generarlo.
 *
 * @var \Quasar\Core\View $this
 * @var \Quasar\Core\Module $module
 * @var array<string,\Quasar\Fields\Field> $fields filtros ("params"), con name p[…]
 * @var array<string,mixed> $values
 * @var array<string,string> $errors  "_form" = error general
 * @var bool $modal
 */
?>
<form method="get" action="index.php" target="_blank" class="qs-form qs-report-form<?= $modal ? ' is-modal' : '' ?>" data-report-form>
  <input type="hidden" name="r" value="page">
  <input type="hidden" name="m" value="<?= $this->e($module->name) ?>">
  <input type="hidden" name="a" value="pdf">

  <?php if ($modal): ?>
    <h1 class="qs-report-form-title"><?= $this->icon($module->icon()) ?> <?= $this->e($module->title()) ?></h1>
    <?php if ($module->description() !== null): ?><p class="qs-muted"><?= $this->e($module->description()) ?></p><?php endif ?>
  <?php endif ?>

  <?php if (isset($errors['_form'])): ?>
    <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($errors['_form']) ?></div>
  <?php elseif ($errors): ?>
    <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($this->t('form.has_errors')) ?></div>
  <?php endif ?>

  <?php if ($fields): ?>
    <fieldset class="qs-section">
      <div class="qs-grid">
        <?php foreach ($fields as $name => $field): ?>
          <?php if ($field->isHidden()): ?>
            <?= $field->render($values[$name] ?? null, ['isNew' => true]) ?>
            <?php continue ?>
          <?php endif ?>
          <?php
          $error = $errors[$name] ?? null;
          $classes = ['qs-field', 'qs-w-' . $field->width(), 'qs-type-' . $field->type()];
          if ($error) {
              $classes[] = 'has-error';
          }
          if ($field->isRequired(true)) {
              $classes[] = 'is-required';
          }
          ?>
          <div class="<?= $this->e(implode(' ', $classes)) ?>">
            <label for="<?= $this->e($field->inputId()) ?>"><?= $this->e($field->label()) ?></label>
            <?= $field->render($values[$name] ?? null, ['isNew' => true]) ?>
            <?php if ($error): ?>
              <div class="qs-error"><?= $this->e($error) ?></div>
            <?php elseif ($help = $field->help()): ?>
              <div class="qs-help"><?= $this->e($help) ?></div>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
    </fieldset>
  <?php endif ?>

  <div class="qs-toolbar">
    <button type="submit" class="qs-btn qs-btn-primary"><?= $this->icon('file-down') ?> <?= $this->e($this->t('report.generate')) ?></button>
    <?php if ($modal): ?>
      <button type="button" class="qs-btn" data-modal-close><?= $this->e($this->t('dialog.cancel')) ?></button>
    <?php endif ?>
  </div>
</form>
