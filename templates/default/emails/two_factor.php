<?php
/**
 * Correo con el código de verificación en dos pasos.
 *
 * @var \Quasar\Core\View $this
 * @var string $name
 * @var string $code
 * @var int $minutes
 * @var string $ip
 * @var string $color
 */
?>
<p style="margin:0 0 12px;"><?= $this->e($this->t('two_factor.mail_hello', ['name' => $name])) ?></p>
<p style="margin:0 0 16px;"><?= $this->e($this->t('two_factor.mail_body')) ?></p>
<p style="margin:0 0 16px;text-align:center;">
  <span style="display:inline-block;padding:12px 22px;border-radius:8px;background:#f3f4f6;border:1px solid #e5e7eb;font-family:'Courier New',Courier,monospace;font-size:30px;font-weight:bold;letter-spacing:8px;color:<?= $this->e($color) ?>;"><?= $this->e($code) ?></span>
</p>
<p style="margin:0 0 16px;"><?= $this->e($this->t('two_factor.mail_expires', ['minutes' => $minutes])) ?></p>
<p style="margin:0;font-size:12px;color:#6b7480;">
  <?= $this->e($this->t('two_factor.mail_warning', ['ip' => $ip !== '' ? $ip : '—'])) ?>
</p>
