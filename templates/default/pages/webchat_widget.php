<?php
/**
 * Asteroid · el chat web dentro del iframe (Quasar\Pages\WebchatWidgetPage), sin el layout de la aplicación.
 * Script: index.php?r=public&m=webchat_widget&a=js (src/Webchat/widget.js); configuración en <body data-config>.
 *
 * @var \Quasar\Core\View $this
 * @var array $site
 * @var array $config
 * @var string $version
 */
$color = \Quasar\Webchat\Webchat::validColor((string) $site['color']) ? (string) $site['color'] : \Quasar\Webchat\Webchat::COLOR;
$name = (string) $site['name'];
?>
<!doctype html>
<html lang="<?= $this->e($this->locale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $this->e($name !== '' ? $name : 'Chat') ?></title>
<style>
:root { --c: <?= $this->e($color) ?>; --bg: #f5f6f8; --text: #1f2933; --muted: #6b7480; --line: #e3e6ea; }
* { box-sizing: border-box; }
[hidden] { display: none !important; }
html, body { height: 100%; margin: 0; }
body { display: flex; flex-direction: column; font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: var(--text); background: var(--bg); }
.wc-head { display: flex; align-items: center; gap: 10px; padding: 14px 16px; background: var(--c); color: #fff; }
.wc-head h1 { flex: 1; margin: 0; font-size: 16px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wc-head button { border: 0; background: transparent; color: inherit; cursor: pointer; padding: 4px; border-radius: 6px; display: grid; place-items: center; }
.wc-head button:hover { background: rgb(255 255 255 / .18); }
.wc-head svg { width: 20px; height: 20px; }
#wc-list { flex: 1; overflow-y: auto; padding: 14px 12px; display: flex; flex-direction: column; gap: 8px; }
.wc-msg { max-width: 82%; display: flex; flex-direction: column; }
.wc-bubble { padding: 8px 12px; border-radius: 14px; white-space: pre-wrap; overflow-wrap: anywhere; }
.wc-meta { font-size: 11px; color: var(--muted); margin: 2px 6px 0; }
.wc-agent { align-self: flex-start; }
.wc-agent .wc-bubble { background: #fff; border: 1px solid var(--line); border-bottom-left-radius: 4px; }
.wc-visitor { align-self: flex-end; align-items: flex-end; }
.wc-visitor .wc-bubble { background: var(--c); color: #fff; border-bottom-right-radius: 4px; }
.wc-system { align-self: center; max-width: 90%; font-size: 12px; color: var(--muted); text-align: center; }
#wc-note, #wc-error { margin: 0 12px 8px; padding: 8px 10px; border-radius: 8px; font-size: 12.5px; }
#wc-note { background: #fff; border: 1px solid var(--line); color: var(--muted); }
#wc-error { background: #fdecec; color: #b42318; }
form { padding: 10px 12px 12px; background: #fff; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: 8px; }
#wc-who { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
#wc-who p { grid-column: 1 / -1; margin: 0; font-size: 12px; color: var(--muted); }
input, textarea { width: 100%; font: inherit; color: inherit; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: #fff; }
input:focus, textarea:focus { outline: 2px solid var(--c); outline-offset: -1px; border-color: transparent; }
.wc-send { display: flex; gap: 8px; align-items: flex-end; }
textarea { resize: none; min-height: 40px; max-height: 120px; }
.wc-send button { flex-shrink: 0; width: 42px; height: 40px; border: 0; border-radius: 8px; background: var(--c); color: #fff; cursor: pointer; display: grid; place-items: center; }
.wc-send svg { width: 20px; height: 20px; }
form.is-sending .wc-send button { opacity: .6; cursor: wait; }
.wc-trap { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
</style>
</head>
<body data-config="<?= $this->json($config + ['greeting' => (string) $site['greeting']]) ?>">
  <header class="wc-head">
    <h1><?= $this->e($name !== '' ? $name : 'Chat') ?></h1>
    <button type="button" id="wc-close" aria-label="<?= $this->e($config['i18n']['close']) ?>" title="<?= $this->e($config['i18n']['close']) ?>">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
  </header>

  <div id="wc-list" role="log" aria-live="polite">
    <?php if (trim((string) $site['greeting']) !== ''): ?>
      <div class="wc-msg wc-agent"><div class="wc-bubble"><?= $this->e((string) $site['greeting']) ?></div></div>
    <?php endif ?>
  </div>

  <p id="wc-note" hidden><?= $this->e($config['i18n']['closed']) ?></p>
  <p id="wc-error" hidden role="alert"></p>

  <form id="wc-form" autocomplete="on">
    <div id="wc-who">
      <p><?= $this->e($config['i18n']['intro']) ?></p>
      <input id="wc-name" name="name" maxlength="80" autocomplete="name" placeholder="<?= $this->e($config['i18n']['name']) ?>" aria-label="<?= $this->e($config['i18n']['name']) ?>">
      <input id="wc-email" name="email" type="email" maxlength="150" autocomplete="email" placeholder="<?= $this->e($config['i18n']['email']) ?>" aria-label="<?= $this->e($config['i18n']['email']) ?>">
    </div>
    <div class="wc-trap" aria-hidden="true"><input id="wc-website" name="website" tabindex="-1" autocomplete="off"></div>
    <div class="wc-send">
      <textarea id="wc-body" rows="1" maxlength="2000" required placeholder="<?= $this->e($config['i18n']['placeholder']) ?>" aria-label="<?= $this->e($config['i18n']['placeholder']) ?>"></textarea>
      <button type="submit" aria-label="<?= $this->e($config['i18n']['send']) ?>" title="<?= $this->e($config['i18n']['send']) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/></svg>
      </button>
    </div>
  </form>
  <script src="<?= $this->url('public', ['m' => 'webchat_widget', 'a' => 'js', 'v' => $version]) ?>" defer></script>
</body>
</html>
