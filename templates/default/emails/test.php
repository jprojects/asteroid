<?php
/**
 * Correo de prueba (bin/mail-test.php). Sirve también de ejemplo para crear otras
 * plantillas: solo el contenido; el layout (logo, colores, pie) lo pone emails/layout.php.
 *
 * @var \Quasar\Core\View $this
 * @var string $color
 * @var array $settings
 */
?>
<h1 style="margin:0 0 12px;font-size:20px;"><?= $this->e($this->t('mail.test.title')) ?></h1>
<p style="margin:0 0 16px;"><?= $this->e($this->t('mail.test.body')) ?></p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;font-size:13px;">
  <?php foreach ($settings as $label => $value): ?>
    <tr>
      <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;color:#6b7480;width:40%;"><?= $this->e($label) ?></td>
      <td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;"><?= $this->e($value) ?></td>
    </tr>
  <?php endforeach ?>
</table>
<p style="margin:20px 0 0;">
  <span style="display:inline-block;padding:10px 18px;border-radius:6px;background:<?= $this->e($color) ?>;color:#ffffff;font-weight:bold;">✓ <?= $this->e($this->t('mail.test.ok')) ?></span>
</p>
