<?php
/**
 * Formulario de un módulo. Cada campo pinta su propio control; aquí solo
 * se decide la maquetación (secciones, rejilla, etiqueta, ayuda y errores).
 *
 * @var \Quasar\Core\View $this
 * @var \Quasar\Core\Module $module
 * @var ?string $id
 * @var bool $isNew
 * @var bool $readonly
 * @var bool $canDelete
 * @var list<array<string,mixed>> $buttons botones propios ("form.buttons")
 * @var list<array{title:?string,fields:list<\Quasar\Fields\Field>}> $sections
 * @var array $values
 * @var array<string,string> $errors
 * @var array<string,bool> $visibility  campos visibles según su "showon"
 * @var ?array $chat  configuración del panel de conversación (módulos con "chat"), o null
 * @var bool $modal  alta rápida dentro de una ventana ("quickCreate"): "Torna" la cierra
 */
$modal = $modal ?? false;
$listUrl = $this->url('list', ['m' => $module->name]);
?>
<div class="qs-record<?= $chat !== null ? ' has-chat' : '' ?>">
<form method="post" action="<?= $this->url('save', ['m' => $module->name]) ?>" class="qs-form" data-dirty-check>
  <?= $this->csrfField() ?>
  <?php if (!$isNew): ?>
    <input type="hidden" name="_id" value="<?= $this->e($id) ?>">
  <?php endif ?>
  <?php if ($modal): ?>
    <input type="hidden" name="_modal" value="1">
  <?php endif ?>

  <div class="qs-toolbar">
    <?php if ($modal): ?>
      <a class="qs-btn" href="#" data-role="back" data-modal-close title="Esc"><?= $this->icon('x') ?> <?= $this->e($this->t('dialog.cancel')) ?></a>
    <?php else: ?>
      <a class="qs-btn" href="<?= $listUrl ?>" data-role="back" title="Esc"><?= $this->icon('arrow-left') ?> <?= $this->e($this->t('actions.back')) ?></a>
    <?php endif ?>
    <?php if (!$readonly): ?>
      <button type="submit" class="qs-btn qs-btn-primary" name="_save" value="1" data-shortcut="save" title="Ctrl+S">
        <?= $this->e($this->t('actions.save')) ?>
      </button>
      <?php if (!$modal): ?>
        <button type="submit" class="qs-btn" name="_close" value="1"><?= $this->e($this->t('actions.save_close')) ?></button>
      <?php endif ?>
    <?php endif ?>
    <?= $this->render('buttons', ['buttons' => $buttons], null) ?>
    <span class="qs-toolbar-spacer"></span>
    <?php if ($canDelete): ?>
      <button type="submit" class="qs-btn qs-btn-danger" formaction="<?= $this->url('delete', ['m' => $module->name]) ?>"
              formnovalidate data-confirm="<?= $this->e($this->t('confirm.delete')) ?>">
        <?= $this->icon('trash-2') ?> <?= $this->e($this->t('actions.delete')) ?>
      </button>
    <?php endif ?>
  </div>

  <?php if ($readonly): ?>
    <div class="qs-flash qs-flash-info"><?= $this->e($this->t('form.readonly')) ?></div>
  <?php endif ?>
  <?php if (isset($errors['_form'])): ?>
    <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($errors['_form']) ?></div>
  <?php elseif ($errors): ?>
    <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($this->t('form.has_errors')) ?></div>
  <?php endif ?>

  <?php foreach ($sections as $section): ?>
    <?php
    // En alta no tiene sentido mostrar una sección compuesta solo por campos de solo lectura
    $visible = array_filter($section['fields'], static fn ($f) => !($isNew && $f->isReadonly()));
    if (!$visible) {
        continue;
    }
    ?>
    <?php
    // Sección oculta si todos sus campos (no ocultos de por sí) lo están por showon
    $shown = array_filter($visible, static fn ($f) => $f->isHidden() || ($visibility[$f->name] ?? true));
    ?>
    <fieldset class="qs-section"<?= $shown ? '' : ' hidden' ?>>
      <?php if ($section['title'] !== null): ?>
        <legend><?= $this->e($section['title']) ?></legend>
      <?php endif ?>
      <div class="qs-grid">
        <?php foreach ($visible as $field): ?>
          <?php if ($field->isHidden()): ?>
            <?= $field->render($values[$field->name] ?? null, ['isNew' => $isNew, 'readonly' => $readonly]) ?>
            <?php continue ?>
          <?php endif ?>
          <?php
          $error = $errors[$field->name] ?? null;
          $classes = ['qs-field', 'qs-w-' . $field->width(), 'qs-type-' . $field->type()];
          if ($error) {
              $classes[] = 'has-error';
          }
          if (!$readonly && $field->isRequired($isNew)) {
              $classes[] = 'is-required';
          }
          ?>
          <div class="<?= $this->e(implode(' ', $classes)) ?>" data-field-name="<?= $this->e($field->name) ?>"
               <?= $field->showOn() ? 'data-showon="' . $this->json($field->showOn()) . '"' : '' ?>
               <?= ($visibility[$field->name] ?? true) ? '' : 'hidden' ?>>
            <label for="<?= $this->e($field->inputId()) ?>"><?= $this->e($field->label()) ?></label>
            <?= $field->render($values[$field->name] ?? null, ['isNew' => $isNew, 'readonly' => $readonly]) ?>
            <?php if ($error): ?>
              <div class="qs-error"><?= $this->e($error) ?></div>
            <?php elseif ($help = $field->help()): ?>
              <div class="qs-help"><?= $this->e($help) ?></div>
            <?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
    </fieldset>
  <?php endforeach ?>

  <?php if (!$readonly): ?>
    <p class="qs-shortcuts"><?= $this->e($this->t('form.shortcuts')) ?></p>
  <?php endif ?>
</form>
<?php if ($chat !== null): ?>
  <?= $this->render('chat', ['chat' => $chat], null) ?>
<?php endif ?>
</div>
