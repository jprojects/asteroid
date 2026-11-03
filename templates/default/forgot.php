<?php
/**
 * «He olvidado la contraseña»: pide el usuario o el email. Tras enviarlo, el mismo mensaje exista o no.
 *
 * @var \Quasar\Core\View $this
 * @var ?string $error
 * @var bool $sent
 * @var string $login
 * @var string $loginField username | email | both
 */
?>
<div class="qs-login">
  <form method="post" action="<?= $this->url('forgot') ?>" class="qs-login-card">
    <div class="qs-login-brand">
      <?php if ($this->logoUrl()): ?>
        <img class="<?= $this->logoClass('qs-login-logo') ?>" src="<?= $this->logoUrl() ?>" alt="<?= $this->e($this->appName()) ?>">
      <?php else: ?>
        <img class="qs-brand-mark" src="<?= $this->asset('logo.svg') ?>" alt="" width="28" height="28"><?= $this->e($this->appName()) ?>
      <?php endif ?>
    </div>
    <h1><?= $this->e($this->t('reset.title')) ?></h1>

    <?php foreach ($this->flashes() as [$type, $message]): ?>
      <div class="qs-flash qs-flash-<?= $this->e($type) ?>"><?= $this->e($message) ?></div>
    <?php endforeach ?>
    <?php if ($error): ?>
      <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($error) ?></div>
    <?php endif ?>

    <?php if ($sent): ?>
      <div class="qs-flash qs-flash-success" role="status"><?= $this->e($this->t('reset.sent')) ?></div>
    <?php else: ?>
      <p class="qs-muted"><?= $this->e($this->t('reset.intro_' . $loginField)) ?></p>
      <?= $this->csrfField() ?>
      <label class="qs-field">
        <span><?= $this->e($this->t($loginField === 'username' ? 'login.username' : 'login.' . $loginField)) ?></span>
        <input class="qs-input" type="<?= $loginField === 'email' ? 'email' : 'text' ?>" name="login" value="<?= $this->e($login) ?>"
               autocomplete="username" maxlength="150" required autofocus>
      </label>
      <button type="submit" class="qs-btn qs-btn-primary qs-btn-block"><?= $this->e($this->t('reset.send')) ?></button>
    <?php endif ?>

    <div class="qs-verify-links">
      <a href="<?= $this->url('login') ?>"><?= $this->e($this->t('reset.back')) ?></a>
    </div>
  </form>
</div>
