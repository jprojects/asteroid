<?php
/**
 * Asteroid · página "issue_from_mail" (Quasar\Pages\IssueFromMailPage): incidencia a partir de un correo.
 * Las URL llegan sin escapar. El HTML del borrador ya está limpio (HtmlSanitizer). Estilos aquí, no en style.css.
 *
 * @var \Quasar\Core\View $this
 * @var string $mode            upload | review
 * upload: @var string $readUrl  @var string $maxUpload  @var string $issuesUrl
 * review: @var array $draft  @var string $token  @var list<array> $projects  @var list<array> $media
 *         @var string $createUrl  @var string $backUrl
 */
?>
<style>
.ast-ifm { display: grid; gap: 14px; max-width: 900px; flex-shrink: 0; }
.ast-ifm-drop { position: relative; display: grid; place-items: center; gap: 6px; min-height: 150px; padding: 18px; text-align: center;
  border: 2px dashed var(--border-strong); border-radius: var(--radius); background: var(--surface-alt); color: var(--text-muted); cursor: pointer; }
.ast-ifm-drop:hover, .ast-ifm-drop:focus-within { border-color: var(--primary); color: var(--text); }
.ast-ifm-drop .qs-icon { width: 30px; height: 30px; color: var(--primary); }
.ast-ifm-drop input[type=file] { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
.ast-ifm-drop strong { color: var(--text); }
.ast-ifm-or { text-align: center; color: var(--text-muted); font-size: 12.5px; margin: 4px 0; }
.ast-ifm textarea { width: 100%; min-height: 140px; }
.ast-ifm-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 12px 16px; }
.ast-ifm-grid .ast-full { grid-column: 1 / -1; }
.ast-ifm-from { display: flex; gap: 10px; flex-wrap: wrap; align-items: baseline; color: var(--text-muted); font-size: 13px; }
.ast-ifm-from strong { color: var(--text); }
.ast-ifm-preview { max-height: 420px; overflow: auto; padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); line-height: 1.55; overflow-wrap: anywhere; }
.ast-ifm-preview img { max-width: 100%; height: auto; }
.ast-ifm-files { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; font-size: 13px; }
.ast-ifm-files li { display: flex; gap: 6px; align-items: center; }
.ast-ifm-actions { display: flex; gap: 8px; flex-wrap: wrap; }
@media (max-width: 640px) { .ast-ifm-grid { grid-template-columns: 1fr; } }
</style>

<div class="ast-ifm">
<?php if ($mode === 'upload'): ?>
  <form method="post" action="<?= $this->e($readUrl) ?>" enctype="multipart/form-data" class="qs-section qs-form">
    <?= $this->csrfField() ?>
    <label class="ast-ifm-drop">
      <?= $this->icon('mail-plus') ?>
      <strong><?= $this->e($this->t('drop')) ?></strong>
      <span><?= $this->e($this->t('drop_help', ['max' => $maxUpload])) ?></span>
      <input type="file" name="eml" accept=".eml,message/rfc822" aria-label="<?= $this->e($this->t('drop')) ?>">
    </label>
    <p class="ast-ifm-or"><?= $this->e($this->t('or_paste')) ?></p>
    <textarea class="qs-input" name="text" placeholder="<?= $this->e($this->t('paste_placeholder')) ?>" aria-label="<?= $this->e($this->t('or_paste')) ?>"></textarea>
    <div class="ast-ifm-actions" style="margin-top: 12px">
      <button type="submit" class="qs-btn qs-btn-primary"><?= $this->icon('upload') ?> <?= $this->e($this->t('read')) ?></button>
      <a class="qs-btn" href="<?= $this->e($issuesUrl) ?>"><?= $this->e($this->t('cancel')) ?></a>
    </div>
    <p class="qs-help"><?= $this->e($this->t('how')) ?></p>
  </form>

<?php else: ?>
  <form method="post" action="<?= $this->e($createUrl) ?>" class="qs-section qs-form">
    <?= $this->csrfField() ?>
    <input type="hidden" name="draft" value="<?= $this->e($token) ?>">
    <div class="ast-ifm-from">
      <?= $this->icon('mail') ?>
      <?php $sender = trim((string) $draft['from_name']) . ($draft['from_email'] !== '' ? ' <' . $draft['from_email'] . '>' : ''); ?>
      <strong><?= $this->e(trim($sender) !== '' ? trim($sender) : '—') ?></strong>
      <?php if ($draft['date']): ?><span><?= $this->e(date($this->t('format.datetime'), (int) strtotime($draft['date']))) ?></span><?php endif ?>
      <span><?= $this->e($draft['client'] ? $this->t('client_found', ['client' => $draft['client']]) : $this->t('client_unknown')) ?></span>
    </div>

    <div class="ast-ifm-grid" style="margin-top: 12px">
      <div class="qs-field ast-full"><label for="ifm-title"><?= $this->e($this->t('title_label')) ?></label>
        <input class="qs-input" id="ifm-title" name="title" maxlength="150" required value="<?= $this->e((string) $draft['title']) ?>"></div>
      <div class="qs-field"><label for="ifm-project"><?= $this->e($this->t('project')) ?></label>
        <select class="qs-input" id="ifm-project" name="project_id" required>
          <?php if (!$draft['client_id']): ?><option value=""><?= $this->e($this->t('choose_project')) ?></option><?php endif ?>
          <?php foreach ($projects as $g): ?>
            <optgroup label="<?= $this->e($g['label']) ?>">
              <?php foreach ($g['options'] as $p): ?><option value="<?= (int) $p['id'] ?>"><?= $this->e($p['title']) ?></option><?php endforeach ?>
            </optgroup>
          <?php endforeach ?>
        </select></div>
      <div class="qs-field"><label for="ifm-priority"><?= $this->e($this->t('priority')) ?></label>
        <select class="qs-input" id="ifm-priority" name="priority">
          <?php foreach (['0' => 'normal', '1' => 'urgent', '2' => 'panic'] as $v => $k): ?><option value="<?= $v ?>"><?= $this->e($this->t('issues:priority.' . $k)) ?></option><?php endforeach ?>
        </select></div>
      <div class="qs-field ast-full">
        <label class="qs-check-line"><input type="checkbox" class="qs-checkbox" name="mine" value="1" checked> <?= $this->e($this->t('mine')) ?></label>
        <label class="qs-check-line"><input type="checkbox" class="qs-checkbox" name="client_visible" value="1"<?= $draft['client_id'] ? ' checked' : '' ?>> <?= $this->e($this->t('visible')) ?></label>
      </div>
    </div>

    <h3 class="qs-subtitle"><?= $this->e($this->t('body_label')) ?></h3>
    <div class="ast-ifm-preview"><?= $draft['html'] !== '' ? $draft['html'] : '<p class="qs-muted">' . $this->e($this->t('empty_body')) . '</p>' ?></div>
    <p class="qs-help"><?= $this->e($this->t('description_help')) ?></p>

    <?php if ($media || $draft['skipped']): ?>
      <h3 class="qs-subtitle"><?= $this->e($this->t('attachments')) ?></h3>
      <ul class="ast-ifm-files">
        <?php foreach ($media as $f): ?><li><?= $this->icon('paperclip') ?> <?= $this->e($f['name']) ?> <span class="qs-muted">· <?= $this->e($f['sizeText']) ?></span></li><?php endforeach ?>
        <?php foreach ($draft['skipped'] as $s): ?><li class="qs-muted"><?= $this->icon('triangle-alert') ?> <?= $this->e($s['name']) ?> — <?= $this->e($s['reason']) ?></li><?php endforeach ?>
      </ul>
    <?php endif ?>

    <div class="ast-ifm-actions" style="margin-top: 14px">
      <button type="submit" class="qs-btn qs-btn-primary"><?= $this->icon('mail-plus') ?> <?= $this->e($this->t('create')) ?></button>
      <a class="qs-btn" href="<?= $this->e($backUrl) ?>"><?= $this->icon('arrow-left') ?> <?= $this->e($this->t('another')) ?></a>
    </div>
  </form>
<?php endif ?>
</div>
