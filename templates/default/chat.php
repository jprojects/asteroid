<?php
/**
 * Panel de conversación de la ficha (módulos con "chat"). El contenido lo pinta app.js (initChat).
 *
 * @var \Quasar\Core\View $this
 * @var array $chat configuración de ModuleController::chatConfig()
 */
$t = $chat['i18n'];
?>
<section class="qs-chat" id="chat" data-chat="<?= $this->json($chat) ?>" aria-label="<?= $this->e($t['title']) ?>">
  <header class="qs-chat-head">
    <h2><?= $this->icon('message-circle') ?> <?= $this->e($t['title']) ?></h2>
    <span class="qs-toolbar-spacer"></span>
    <?php if ($chat['meet'] !== null): ?>
      <button type="button" class="qs-btn qs-btn-sm" data-chat-meet><?= $this->icon('video') ?> <?= $this->e($t['meet']) ?></button>
    <?php endif ?>
    <button type="button" class="qs-icon-btn" data-chat-mute aria-pressed="false" title="<?= $this->e($t['mute']) ?>" aria-label="<?= $this->e($t['mute']) ?>">
      <?= $this->icon('bell') ?>
    </button>
  </header>
  <div class="qs-chat-log" role="log" aria-live="polite" tabindex="0"></div>
  <form class="qs-chat-compose" data-chat-form autocomplete="off">
    <ul class="qs-chat-files" hidden></ul>
    <div class="qs-chat-input">
      <textarea name="body" rows="2" class="qs-input" placeholder="<?= $this->e($t['placeholder']) ?>" aria-label="<?= $this->e($t['placeholder']) ?>"></textarea>
      <div class="qs-dropdown qs-chat-mentions" hidden><ul class="qs-dropdown-list" role="listbox"></ul></div>
    </div>
    <div class="qs-chat-actions">
      <?php if ($chat['upload'] !== null): ?>
        <label class="qs-icon-btn" title="<?= $this->e($t['attach']) ?>">
          <?= $this->icon('paperclip') ?><span class="qs-visually-hidden"><?= $this->e($t['attach']) ?></span>
          <input type="file" multiple hidden data-chat-file>
        </label>
      <?php endif ?>
      <span class="qs-chat-hint"><?= $this->e($t['enterHint']) ?></span>
      <span class="qs-toolbar-spacer"></span>
      <button type="submit" class="qs-btn qs-btn-primary qs-btn-sm"><?= $this->icon('send') ?> <?= $this->e($t['send']) ?></button>
    </div>
  </form>
</section>
