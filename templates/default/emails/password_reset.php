<?php
/**
 * Correo con el enlace para elegir contraseña: pedido desde el login (self), enviado por un administrador
 * desde la ficha (reset) o invitación de un usuario nuevo (invite).
 *
 * @var \Quasar\Core\View $this
 * @var string $name
 * @var string $username
 * @var string $url
 * @var string $kind self | reset | invite
 * @var string $by nombre del administrador (reset, invite)
 * @var string $expires texto de caducidad
 * @var string $ip
 * @var string $color
 */
$body = match ($kind) {
    'invite' => $this->t('reset.invite_body', ['by' => $by, 'app' => $this->appName(), 'username' => $username]),
    'reset' => $this->t('reset.admin_body', ['by' => $by]),
    default => $this->t('reset.mail_body'),
};
?>
<p style="margin:0 0 12px;"><?= $this->e($this->t('reset.mail_hello', ['name' => $name])) ?></p>
<p style="margin:0 0 16px;"><?= $this->e($body) ?></p>
<p style="margin:0 0 16px;text-align:center;">
  <a href="<?= $this->e($url) ?>" style="display:inline-block;padding:10px 20px;border-radius:6px;background:<?= $this->e($color) ?>;color:#ffffff;text-decoration:none;font-weight:bold;"><?= $this->e($this->t($kind === 'invite' ? 'reset.invite_button' : 'reset.mail_button')) ?></a>
</p>
<p style="margin:0 0 16px;"><?= $this->e($expires) ?></p>
<p style="margin:0 0 16px;font-size:12px;color:#6b7480;word-break:break-all;"><?= $this->e($this->t('reset.mail_link')) ?><br><?= $this->e($url) ?></p>
<?php if ($kind === 'self'): ?>
  <p style="margin:0;font-size:12px;color:#6b7480;">
    <?= $this->e($this->t('reset.mail_warning', ['ip' => $ip !== '' ? $ip : '—'])) ?>
  </p>
<?php endif ?>
