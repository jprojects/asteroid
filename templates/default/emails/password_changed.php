<?php
/**
 * Aviso de que la contraseña se ha cambiado con un enlace de recuperación.
 *
 * @var \Quasar\Core\View $this
 * @var string $name
 * @var string $ip
 */
?>
<p style="margin:0 0 12px;"><?= $this->e($this->t('reset.mail_hello', ['name' => $name])) ?></p>
<p style="margin:0 0 16px;"><?= $this->e($this->t('reset.changed_body')) ?></p>
<p style="margin:0;font-size:12px;color:#6b7480;">
  <?= $this->e($this->t('reset.changed_warning', ['ip' => $ip !== '' ? $ip : '—'])) ?>
</p>
