<?php
/**
 * Correo de aviso (Notifier): uno o varios avisos del usuario.
 *
 * @var \Quasar\Core\View $this
 * @var string $name
 * @var list<array{title:string,body:?string,link:?string}> $items
 * @var string $centerUrl  perfil (para cambiar los avisos por correo)
 * @var string $color
 */
?>
<p style="margin:0 0 14px;"><?= $this->e($this->t('notifications.mail_hello', ['name' => $name])) ?></p>
<?php foreach ($items as $item): ?>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 12px;border:1px solid #e5e7eb;border-radius:6px;">
    <tr>
      <td style="padding:12px 14px;">
        <p style="margin:0 0 6px;font-weight:bold;"><?= $this->e($item['title']) ?></p>
        <?php if (($item['body'] ?? '') !== ''): ?>
          <p style="margin:0 0 10px;color:#4b5563;white-space:pre-line;"><?= $this->e($item['body']) ?></p>
        <?php endif ?>
        <?php if (!empty($item['link'])): ?>
          <a href="<?= $this->e($item['link']) ?>" style="display:inline-block;padding:7px 14px;border-radius:5px;background:<?= $this->e($color) ?>;color:#ffffff;text-decoration:none;font-size:13px;"><?= $this->e($this->t('notifications.mail_open')) ?></a>
        <?php endif ?>
      </td>
    </tr>
  </table>
<?php endforeach ?>
<p style="margin:14px 0 0;font-size:12px;color:#6b7480;">
  <a href="<?= $this->e($centerUrl) ?>" style="color:#6b7480;"><?= $this->e($this->t('notifications.mail_settings')) ?></a>
</p>
