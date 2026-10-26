<?php
/**
 * Segundo paso del login: código recibido por email.
 *
 * @var \Quasar\Core\View $this
 * @var string $email        email enmascarado
 * @var ?string $error
 * @var ?string $notice
 * @var int $rememberDays
 * @var int $resendWait      segundos hasta poder reenviar
 * @var int $codeLength
 */
?>
<div class="qs-login">
  <form method="post" action="<?= $this->url('verify') ?>" class="qs-login-card" data-verify>
    <div class="qs-login-brand">
      <?php if ($this->logoUrl()): ?>
        <img class="qs-login-logo" src="<?= $this->logoUrl() ?>" alt="<?= $this->e($this->appName()) ?>">
      <?php else: ?>
        <img class="qs-brand-mark" src="<?= $this->asset('logo.svg') ?>" alt="" width="28" height="28"><?= $this->e($this->appName()) ?>
      <?php endif ?>
    </div>
    <h1><?= $this->e($this->t('two_factor.title')) ?></h1>
    <p class="qs-muted"><?= $this->e($this->t('two_factor.intro', ['email' => $email])) ?></p>

    <?php if ($notice): ?>
      <div class="qs-flash qs-flash-success" role="status"><?= $this->e($notice) ?></div>
    <?php endif ?>
    <?php if ($error): ?>
      <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($error) ?></div>
    <?php endif ?>

    <?= $this->csrfField() ?>
    <label class="qs-field">
      <span><?= $this->e($this->t('two_factor.code')) ?></span>
      <input class="qs-input qs-code-input" type="text" name="code" required autofocus
             inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*"
             maxlength="<?= $codeLength + 2 ?>" data-code-length="<?= $codeLength ?>"
             placeholder="<?= str_repeat('•', $codeLength) ?>">
    </label>
    <?php if ($rememberDays > 0): ?>
      <label class="qs-check-line">
        <input type="checkbox" class="qs-checkbox" name="remember" value="1">
        <span><?= $this->e($this->t('two_factor.remember', ['days' => $rememberDays])) ?></span>
      </label>
    <?php endif ?>
    <button type="submit" class="qs-btn qs-btn-primary qs-btn-block"><?= $this->e($this->t('two_factor.submit')) ?></button>

    <div class="qs-verify-links">
      <button type="submit" name="_resend" value="1" class="qs-linkbtn" formnovalidate
              data-countdown="<?= $resendWait ?>" data-countdown-text="<?= $this->e($this->t('two_factor.resend_in')) ?>">
        <?= $this->e($this->t('two_factor.resend')) ?>
      </button>
      <a href="<?= $this->url('login') ?>"><?= $this->e($this->t('two_factor.cancel')) ?></a>
    </div>
  </form>
</div>
