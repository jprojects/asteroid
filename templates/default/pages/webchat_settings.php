<?php
/**
 * Asteroid · página "webchat_settings" (Quasar\Pages\WebchatSettingsPage). Las URL llegan sin escapar.
 *
 * @var \Quasar\Core\View $this
 * @var bool $enabled
 * @var array $client
 * @var ?array $site
 * @var array<string,string> $errors
 * @var string $snippet
 * @var string $previewUrl
 * @var string $inboxUrl
 * @var string $action
 */
$errors ??= [];
$field = function (string $name, string $label, string $control, ?string $help = null) use ($errors): string {
    $error = $errors[$name] ?? null;
    return '<div class="qs-field qs-w-full' . ($error ? ' has-error' : '') . '"><label for="wc-' . $name . '">' . $this->e($label) . '</label>' . $control
        . ($error ? '<div class="qs-error">' . $this->e($error) . '</div>' : ($help ? '<div class="qs-help">' . $this->e($help) . '</div>' : '')) . '</div>';
};
?>
<style>
.ast-wcs { display: grid; gap: 14px; max-width: 860px; flex-shrink: 0; }
.ast-wcs .qs-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
.ast-wcs .qs-w-full { grid-column: 1 / -1; }
.ast-wcs .ast-half { grid-column: auto; }
.ast-wcs textarea.ast-code { font: 12.5px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace; min-height: 70px; }
.ast-wcs-color { display: flex; gap: 8px; align-items: center; }
.ast-wcs-color input[type=color] { width: 46px; height: 34px; padding: 2px; }
.ast-wcs-actions { display: flex; flex-wrap: wrap; gap: 8px; }
@media (max-width: 640px) { .ast-wcs .qs-grid { grid-template-columns: 1fr; } }
</style>

<div class="ast-wcs">
  <?php if (!$enabled): ?>
    <div class="qs-flash qs-flash-info"><?= $this->e($this->t('disabled', ['client' => (string) $client['name']])) ?></div>
  <?php else: ?>
    <form method="post" action="<?= $this->e($action) ?>" class="qs-form" data-dirty-check>
      <?= $this->csrfField() ?>
      <div class="qs-toolbar">
        <button type="submit" class="qs-btn qs-btn-primary" data-shortcut="save" title="Ctrl+S"><?= $this->e($this->t('actions.save')) ?></button>
        <a class="qs-btn" href="<?= $this->e($inboxUrl) ?>"><?= $this->icon('messages-square') ?> <?= $this->e($this->t('inbox')) ?></a>
        <?php if ((int) $site['active']): ?>
          <a class="qs-btn" href="<?= $this->e($previewUrl) ?>" target="_blank" rel="noopener"><?= $this->icon('external-link') ?> <?= $this->e($this->t('preview')) ?></a>
        <?php endif ?>
      </div>

      <fieldset class="qs-section">
        <legend><?= $this->e($this->t('section_look')) ?></legend>
        <div class="qs-grid">
          <div class="qs-field qs-w-full qs-type-checkbox">
            <label class="qs-check-line"><input type="checkbox" class="qs-input qs-checkbox" name="active" value="1"<?= (int) $site['active'] ? ' checked' : '' ?>> <?= $this->e($this->t('active')) ?></label>
            <div class="qs-help"><?= $this->e($this->t('active_help')) ?></div>
          </div>
          <?= $field('name', $this->t('name'), '<input class="qs-input" id="wc-name" name="name" maxlength="80" required value="' . $this->e((string) $site['name']) . '">', $this->t('name_help')) ?>
          <div class="qs-field ast-half<?= isset($errors['color']) ? ' has-error' : '' ?>">
            <label for="wc-color"><?= $this->e($this->t('color')) ?></label>
            <span class="ast-wcs-color"><input type="color" id="wc-color" name="color" value="<?= $this->e((string) $site['color']) ?>"></span>
            <?php if (isset($errors['color'])): ?><div class="qs-error"><?= $this->e($errors['color']) ?></div><?php endif ?>
          </div>
          <div class="qs-field ast-half">
            <label for="wc-position"><?= $this->e($this->t('position')) ?></label>
            <select class="qs-input" id="wc-position" name="position">
              <option value="right"<?= $site['position'] !== 'left' ? ' selected' : '' ?>><?= $this->e($this->t('position_right')) ?></option>
              <option value="left"<?= $site['position'] === 'left' ? ' selected' : '' ?>><?= $this->e($this->t('position_left')) ?></option>
            </select>
          </div>
          <?= $field('greeting', $this->t('greeting'), '<textarea class="qs-input" id="wc-greeting" name="greeting" rows="3" maxlength="500">' . $this->e((string) $site['greeting']) . '</textarea>', $this->t('greeting_help')) ?>
          <?= $field('domains', $this->t('domains'), '<textarea class="qs-input" id="wc-domains" name="domains" rows="3" placeholder="www.example.com">' . $this->e((string) $site['domains']) . '</textarea>', $this->t('domains_help')) ?>
        </div>
      </fieldset>
    </form>

    <fieldset class="qs-section qs-form">
      <legend><?= $this->e($this->t('section_code')) ?></legend>
      <p class="qs-help"><?= $this->e($this->t('code_help')) ?></p>
      <textarea class="qs-input ast-code" readonly rows="2" aria-label="<?= $this->e($this->t('section_code')) ?>"><?= $this->e($snippet) ?></textarea>
      <?php if (!(int) $site['active']): ?>
        <p class="qs-help"><?= $this->icon('triangle-alert') ?> <?= $this->e($this->t('inactive_note')) ?></p>
      <?php endif ?>
    </fieldset>
  <?php endif ?>
</div>
