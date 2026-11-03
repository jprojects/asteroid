<?php
/**
 * Contraseña nueva con el enlace del correo (el token va en la sesión, no en el formulario).
 *
 * @var \Quasar\Core\View $this
 * @var ?string $error
 * @var string $name
 * @var int $minLength
 */
?>
<div class="qs-login">
  <form method="post" action="<?= $this->url('reset') ?>" class="qs-login-card">
    <div class="qs-login-brand">
      <?php if ($this->logoUrl()): ?>
        <img class="<?= $this->logoClass('qs-login-logo') ?>" src="<?= $this->logoUrl() ?>" alt="<?= $this->e($this->appName()) ?>">
      <?php else: ?>
        <img class="qs-brand-mark" src="<?= $this->asset('logo.svg') ?>" alt="" width="28" height="28"><?= $this->e($this->appName()) ?>
      <?php endif ?>
    </div>
    <h1><?= $this->e($this->t('reset.new_title')) ?></h1>
    <p class="qs-muted"><?= $this->e($this->t('reset.new_intro', ['name' => $name, 'min' => $minLength])) ?></p>

    <?php if ($error): ?>
      <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($error) ?></div>
    <?php endif ?>

    <?= $this->csrfField() ?>
    <label class="qs-field">
      <span><?= $this->e($this->t('reset.password')) ?></span>
      <input class="qs-input" type="password" name="password" autocomplete="new-password" minlength="<?= $minLength ?>" maxlength="255" required autofocus>
    </label>
    <label class="qs-field">
      <span><?= $this->e($this->t('reset.password_confirm')) ?></span>
      <input class="qs-input" type="password" name="password_confirm" autocomplete="new-password" minlength="<?= $minLength ?>" maxlength="255" required>
    </label>
    <button type="submit" class="qs-btn qs-btn-primary qs-btn-block"><?= $this->e($this->t('reset.save')) ?></button>

    <div class="qs-verify-links">
      <a href="<?= $this->url('login') ?>"><?= $this->e($this->t('reset.back')) ?></a>
    </div>
  </form>
</div>
