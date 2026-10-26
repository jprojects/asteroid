<?php
/**
 * Layout de los correos HTML. Estilos en línea y maquetación con tablas:
 * es lo único que respetan de forma fiable Outlook, Gmail y compañía.
 *
 * @var \Quasar\Core\View $this
 * @var string $content   HTML de la plantilla concreta
 * @var ?string $logoSrc  "cid:…" del logo incrustado
 * @var string $color
 * @var string $company
 * @var ?string $preheader texto de vista previa (opcional)
 */
?>
<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $this->e($company) ?></title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
<?php if (!empty($preheader)): ?>
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?= $this->e($preheader) ?></div>
<?php endif ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;">
  <tr>
    <td align="center" style="padding:24px 12px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e5e7eb;">
        <tr>
          <td style="height:4px;background:<?= $this->e($color) ?>;font-size:0;line-height:0;">&nbsp;</td>
        </tr>
        <tr>
          <td style="padding:20px 28px 8px;">
            <?php if ($logoSrc): ?>
              <img src="<?= $this->e($logoSrc) ?>" alt="<?= $this->e($company) ?>" height="40" style="display:block;height:40px;width:auto;border:0;">
            <?php else: ?>
              <strong style="font-size:18px;color:<?= $this->e($color) ?>;"><?= $this->e($company) ?></strong>
            <?php endif ?>
          </td>
        </tr>
        <tr>
          <td style="padding:8px 28px 28px;font-size:14px;line-height:1.6;">
            <?= $content ?>
          </td>
        </tr>
        <tr>
          <td style="padding:14px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:11px;color:#6b7480;">
            <?= $this->e($company) ?> · <?= $this->e($this->t('mail.footer')) ?>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
