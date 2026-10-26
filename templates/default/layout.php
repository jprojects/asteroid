<?php
/** @var \Quasar\Core\View $this */
/** @var string $content */
/**
 * Variables de cabecera que pasa cada controlador (todas opcionales):
 * @var ?string $heading      título grande (por defecto $title)
 * @var ?string $subtitle
 * @var ?string $icon
 * @var ?array  $breadcrumbs  [[texto, url|null], …]
 */
$user = $this->user();
$heading = $heading ?? $title ?? '';
$breadcrumbs = $breadcrumbs ?? [[$this->t('home.title'), $this->url('home')], [$heading, null]];
$pageTitle = ($title ?? '') !== '' ? $title . ' · ' . $this->appName() : $this->appName();
?>
<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= $this->e($this->csrfToken()) ?>">
<title><?= $this->e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= $this->asset('style.css') ?>">
<meta name="qs-i18n" content="<?= $this->json($this->dialogTexts()) ?>">
<link rel="icon" href="<?= $this->logoUrl() ?? $this->asset('logo.svg') ?>">
<?php if ($this->brandColor()): ?><style>:root { --primary: <?= $this->e($this->brandColor()) ?>; }</style><?php endif ?>
<script src="<?= $this->asset('app.js') ?>" defer></script>
</head>
<body class="qs-app<?= $this->sidebarCollapsed() ? ' is-sidebar-collapsed' : '' ?>">
<aside class="qs-sidebar" id="qs-sidebar">
  <a class="qs-brand" href="<?= $this->url('home') ?>" title="<?= $this->e($this->appName()) ?>">
    <?php if ($this->logoUrl()): ?>
      <span class="qs-brand-logo"><img src="<?= $this->logoUrl() ?>" alt="<?= $this->e($this->appName()) ?>"></span>
    <?php else: ?>
      <img class="qs-brand-mark" src="<?= $this->asset('logo.svg') ?>" alt="" width="28" height="28"><span class="qs-brand-name"><?= $this->e($this->appName()) ?></span>
    <?php endif ?>
  </a>
  <nav class="qs-nav">
    <?php foreach ($this->menu() as $group => $modules): ?>
      <div class="qs-nav-group"><?= $this->e($this->t('menu.' . $group)) ?></div>
      <?php foreach ($modules as $m): ?>
        <a href="<?= $this->e($m->url()) ?>" title="<?= $this->e($m->title()) ?>"
           class="qs-nav-item<?= $this->currentModule() === $m->name ? ' is-active' : '' ?>">
          <?= $this->icon($m->icon(), 'qs-nav-icon') ?>
          <span class="qs-nav-label"><?= $this->e($m->title()) ?></span>
        </a>
      <?php endforeach ?>
    <?php endforeach ?>
  </nav>
  <div class="qs-sidebar-foot" title="<?= $this->e($this->appName() . ' ' . $this->version()) ?>">v<?= $this->e($this->version()) ?></div>
</aside>

<div class="qs-main">
  <header class="qs-topbar">
    <button type="button" class="qs-icon-btn qs-sidebar-toggle" data-sidebar-toggle aria-controls="qs-sidebar"
            aria-expanded="<?= $this->sidebarCollapsed() ? 'false' : 'true' ?>"
            title="<?= $this->e($this->t('nav.toggle')) ?> (Ctrl+B)" aria-label="<?= $this->e($this->t('nav.toggle')) ?>">
      <?= $this->icon('panel-left') ?>
    </button>
    <nav class="qs-breadcrumbs" aria-label="<?= $this->e($this->t('page.breadcrumbs')) ?>">
      <ol>
        <?php foreach ($breadcrumbs as $i => [$label, $url]): ?>
          <?php $last = $i === array_key_last($breadcrumbs); ?>
          <li<?= $last ? ' aria-current="page"' : '' ?>>
            <?php if ($url !== null && !$last): ?>
              <a href="<?= $this->e($url) ?>"><?= $this->e($label) ?></a>
            <?php else: ?>
              <span><?= $this->e($label) ?></span>
            <?php endif ?>
          </li>
        <?php endforeach ?>
      </ol>
    </nav>
    <?php foreach ($this->quickCreates() as $q): ?>
      <button type="button" class="qs-btn qs-btn-sm qs-quick-create" data-quick-create="<?= $this->json($q) ?>" title="<?= $this->e($q['label']) ?>">
        <?= $this->icon($q['icon']) ?><span class="qs-quick-label"><?= $this->e($q['label']) ?></span>
      </button>
    <?php endforeach ?>
    <?php if ($user): ?>
      <div class="qs-notify" data-notifications="<?= $this->json($this->notificationsConfig()) ?>">
        <button type="button" class="qs-icon-btn qs-notify-btn" aria-haspopup="true" aria-expanded="false"
                title="<?= $this->e($this->t('notifications.title')) ?>" aria-label="<?= $this->e($this->t('notifications.title')) ?>">
          <?= $this->icon('bell') ?><span class="qs-notify-count" hidden></span>
        </button>
        <div class="qs-notify-panel" hidden>
          <div class="qs-notify-head">
            <strong><?= $this->e($this->t('notifications.title')) ?></strong>
            <button type="button" class="qs-linkbtn" data-notify-readall><?= $this->e($this->t('notifications.read_all')) ?></button>
          </div>
          <div class="qs-notify-list" role="list"></div>
          <div class="qs-notify-foot">
            <button type="button" class="qs-linkbtn" data-notify-desktop hidden><?= $this->icon('bell') ?> <?= $this->e($this->t('notifications.desktop')) ?></button>
          </div>
        </div>
      </div>
      <div class="qs-user">
        <span class="qs-avatar" aria-hidden="true"><?= $this->e(mb_strtoupper(mb_substr($user->name(), 0, 1))) ?></span>
        <a class="qs-user-name" href="<?= $this->url('profile') ?>" title="<?= $this->e($this->t('profile.link')) ?>"><?= $this->e($user->name()) ?></a>
        <form method="post" action="<?= $this->url('logout') ?>">
          <?= $this->csrfField() ?>
          <button type="submit" class="qs-btn qs-btn-ghost qs-btn-sm"><?= $this->icon('log-out') ?> <?= $this->e($this->t('logout')) ?></button>
        </form>
      </div>
    <?php endif ?>
  </header>

  <main class="qs-content">
    <?php if ($heading !== ''): ?>
      <header class="qs-pagehead">
        <?php if (!empty($icon)): ?>
          <span class="qs-pagehead-icon"><?= $this->icon($icon) ?></span>
        <?php endif ?>
        <div class="qs-pagehead-text">
          <h1><?= $this->e($heading) ?></h1>
          <?php if (!empty($subtitle)): ?>
            <p><?= $this->e($subtitle) ?></p>
          <?php endif ?>
        </div>
      </header>
    <?php endif ?>

    <div class="qs-flashes">
      <?php foreach ($this->flashes() as [$type, $message]): ?>
        <div class="qs-flash qs-flash-<?= $this->e($type) ?>" role="status"><?= $this->e($message) ?></div>
      <?php endforeach ?>
    </div>

    <?= $content ?>
  </main>
</div>
<?= $this->iconSprite() ?>
</body>
</html>
