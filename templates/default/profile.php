<?php
/**
 * Perfil del usuario conectado.
 *
 * @var \Quasar\Core\View $this
 * @var array{personal:list<\Quasar\Fields\Field>,security:list<\Quasar\Fields\Field>,password:list<\Quasar\Fields\Field>} $sections
 * @var array $values
 * @var array<string,string> $errors
 * @var string $mode            off | optional | required
 * @var bool $twoFactorActive
 * @var int $trustedDevices
 * @var ?string $lastLogin
 * @var string $username
 */
$renderField = function (\Quasar\Fields\Field $field) use ($values, $errors): string {
    $error = $errors[$field->name] ?? null;
    $classes = ['qs-field', 'qs-w-' . $field->width(), 'qs-type-' . $field->type()];
    if ($error) {
        $classes[] = 'has-error';
    }
    if ($field->isRequired(true)) {
        $classes[] = 'is-required';
    }
    ob_start(); ?>
    <div class="<?= $this->e(implode(' ', $classes)) ?>">
      <label for="<?= $this->e($field->inputId()) ?>"><?= $this->e($field->label()) ?></label>
      <?= $field->render($values[$field->name] ?? null, ['isNew' => true]) ?>
      <?php if ($error): ?>
        <div class="qs-error"><?= $this->e($error) ?></div>
      <?php elseif ($help = $field->help()): ?>
        <div class="qs-help"><?= $this->e($help) ?></div>
      <?php endif ?>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<form method="post" action="<?= $this->url('profile') ?>" class="qs-form" data-dirty-check autocomplete="off">
  <?= $this->csrfField() ?>

  <div class="qs-toolbar">
    <a class="qs-btn" href="<?= $this->url('home') ?>" data-role="back" title="Esc"><?= $this->icon('arrow-left') ?> <?= $this->e($this->t('actions.back')) ?></a>
    <button type="submit" class="qs-btn qs-btn-primary" data-shortcut="save" title="Ctrl+S"><?= $this->e($this->t('actions.save')) ?></button>
    <span class="qs-toolbar-spacer"></span>
    <span class="qs-muted">
      <?= $this->e($this->t('info.username', ['username' => $username])) ?>
      <?php if ($lastLogin): ?> · <?= $this->e($this->t('info.last_login', ['date' => date($this->t('format.datetime'), (int) strtotime($lastLogin))])) ?><?php endif ?>
    </span>
  </div>

  <?php if ($errors): ?>
    <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($this->t('form.has_errors')) ?></div>
  <?php endif ?>

  <fieldset class="qs-section">
    <legend><?= $this->e($this->t('sections.personal')) ?></legend>
    <div class="qs-grid">
      <?php foreach ($sections['personal'] as $field): ?><?= $renderField($field) ?><?php endforeach ?>
    </div>
  </fieldset>

  <?php if ($mode !== 'off'): ?>
    <fieldset class="qs-section">
      <legend><?= $this->e($this->t('sections.security')) ?></legend>
      <div class="qs-grid">
        <?php if ($mode === 'required'): ?>
          <p class="qs-field qs-w-full"><?= $this->e($this->t('two_factor_status.required')) ?></p>
        <?php endif ?>
        <?php foreach ($sections['security'] as $field): ?><?= $renderField($field) ?><?php endforeach ?>
        <?php if ($twoFactorActive): ?>
          <div class="qs-field qs-w-full qs-trusted">
            <span><?= $this->e($this->t('two_factor_status.devices', ['n' => $trustedDevices])) ?></span>
            <?php if ($trustedDevices > 0): ?>
              <button type="submit" name="_forget" value="1" class="qs-btn qs-btn-sm" formnovalidate
                      data-confirm="<?= $this->e($this->t('two_factor_status.forget_confirm')) ?>" data-confirm-variant="warning">
                <?= $this->e($this->t('two_factor_status.forget')) ?>
              </button>
            <?php endif ?>
          </div>
        <?php endif ?>
      </div>
    </fieldset>
  <?php endif ?>

  <fieldset class="qs-section">
    <legend><?= $this->e($this->t('sections.password')) ?></legend>
    <p class="qs-help"><?= $this->e($this->t('help.password_section')) ?></p>
    <div class="qs-grid">
      <?php foreach ($sections['password'] as $field): ?><?= $renderField($field) ?><?php endforeach ?>
    </div>
  </fieldset>
</form>
