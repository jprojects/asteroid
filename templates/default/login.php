<?php
/** @var \Quasar\Core\View $this */
/** @var ?string $error */
/** @var string $username */
?>
<div class="qs-login">
  <form method="post" action="<?= $this->url('login') ?>" class="qs-login-card">
    <div class="qs-login-brand">
      <?php if ($this->logoUrl()): ?>
        <img class="qs-login-logo" src="<?= $this->logoUrl() ?>" alt="<?= $this->e($this->appName()) ?>">
      <?php else: ?>
        <img class="qs-brand-mark" src="<?= $this->asset('logo.svg') ?>" alt="" width="28" height="28"><?= $this->e($this->appName()) ?>
      <?php endif ?>
    </div>
    <h1><?= $this->e($this->t('login.title')) ?></h1>

    <?php foreach ($this->flashes() as [$type, $message]): ?>
      <div class="qs-flash qs-flash-<?= $this->e($type) ?>"><?= $this->e($message) ?></div>
    <?php endforeach ?>
    <?php if ($error): ?>
      <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($error) ?></div>
    <?php endif ?>

    <?= $this->csrfField() ?>
    <label class="qs-field">
      <span><?= $this->e($this->t('login.username')) ?></span>
      <input class="qs-input" type="text" name="username" value="<?= $this->e($username) ?>"
             autocomplete="username" required autofocus>
    </label>
    <label class="qs-field">
      <span><?= $this->e($this->t('login.password')) ?></span>
      <input class="qs-input" type="password" name="password" autocomplete="current-password" required>
    </label>
    <?php if (count($this->locales()) > 1): ?>
      <label class="qs-field">
        <span><?= $this->e($this->t('login.language')) ?></span>
        <select class="qs-input" name="lang" data-autosubmit-lang>
          <?php foreach ($this->locales() as $code => $label): ?>
            <option value="<?= $this->e($code) ?>"<?= $code === $this->locale() ? ' selected' : '' ?>><?= $this->e($label) ?></option>
          <?php endforeach ?>
        </select>
      </label>
    <?php endif ?>
    <button type="submit" class="qs-btn qs-btn-primary qs-btn-block"><?= $this->e($this->t('login.submit')) ?></button>
  </form>
</div>
