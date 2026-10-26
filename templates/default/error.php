<?php
/**
 * @var \Quasar\Core\View $this
 * @var int $status
 * @var string $message
 * @var ?string $debug
 */
?>
<div class="qs-error-page">
  <div class="qs-error-code"><?= (int) $status ?></div>
  <p class="qs-lead"><?= $this->e($message) ?></p>
  <p>
    <?php if ($status === 401 || !$this->user()): ?>
      <a class="qs-btn qs-btn-primary" href="<?= $this->url('login') ?>"><?= $this->e($this->t('login.title')) ?></a>
    <?php else: ?>
      <a class="qs-btn" href="<?= $this->url('home') ?>"><?= $this->e($this->t('errors.back_home')) ?></a>
    <?php endif ?>
  </p>
  <?php if ($debug !== null): ?>
    <pre class="qs-debug"><?= $this->e($debug) ?></pre>
  <?php endif ?>
</div>
