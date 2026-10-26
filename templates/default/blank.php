<?php
/** @var \Quasar\Core\View $this */
/** @var string $content */
?>
<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $this->e(($title ?? '') !== '' ? $title . ' · ' . $this->appName() : $this->appName()) ?></title>
<link rel="stylesheet" href="<?= $this->asset('style.css') ?>">
<meta name="qs-i18n" content="<?= $this->json($this->dialogTexts()) ?>">
<link rel="icon" href="<?= $this->logoUrl() ?? $this->asset('logo.svg') ?>">
<?php if ($this->brandColor()): ?><style>:root { --primary: <?= $this->e($this->brandColor()) ?>; }</style><?php endif ?>
<script src="<?= $this->asset('app.js') ?>" defer></script>
</head>
<body class="qs-blank">
<?= $content ?>
</body>
</html>
