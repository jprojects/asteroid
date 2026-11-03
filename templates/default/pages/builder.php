<?php
/**
 * Constructor de módulos: petición (descripción y/o Excel/CSV) y revisión de la propuesta antes de crearla.
 *
 * @var \Quasar\Core\View $this
 * @var \Quasar\Core\Module $module
 * @var ?array $draft propuesta en curso (sesión): spec, note, prompt, file
 * @var ?array $spec propuesta normalizada
 * @var list<string> $errors lo que impide crearla
 * @var ?string $sql CREATE TABLE que se ejecutará
 * @var string $json propuesta editable
 * @var list<string> $notWritable carpetas sin permiso de escritura
 * @var bool $aiEnabled
 */
$locale = $this->locale();
$tr = static fn (array $texts) => $texts[$locale] ?? reset($texts);
?>
<div class="qs-builder">
  <?php if ($notWritable): ?>
    <div class="qs-flash qs-flash-warning"><?= $this->e($this->t('not_writable', ['dirs' => implode(', ', $notWritable)])) ?></div>
  <?php endif ?>

  <?php if ($draft === null): ?>
    <form method="post" action="<?= $this->e($module->url(['a' => 'propose'])) ?>" enctype="multipart/form-data"
          class="qs-builder-ask" data-busy="<?= $this->e($this->t('ask.working')) ?>">
      <?= $this->csrfField() ?>
      <p class="qs-builder-intro"><?= $this->icon('blocks') ?> <?= $this->e($this->t('intro')) ?></p>
      <label class="qs-field">
        <span><?= $this->e($this->t('ask.label')) ?></span>
        <textarea class="qs-input" name="q" rows="4" maxlength="4000" placeholder="<?= $this->e($this->t('ask.placeholder')) ?>"></textarea>
      </label>
      <label class="qs-field">
        <span><?= $this->e($this->t('ask.file')) ?></span>
        <input class="qs-input" type="file" name="file" accept=".xlsx,.csv,.txt">
        <small class="qs-muted"><?= $this->e($this->t('ask.file_help')) ?></small>
      </label>
      <div>
        <button type="submit" class="qs-btn qs-btn-primary"<?= $aiEnabled ? '' : ' disabled' ?>><?= $this->icon('sparkles') ?> <?= $this->e($this->t('ask.submit')) ?></button>
      </div>
    </form>
  <?php else: ?>
    <section class="qs-builder-draft">
      <header class="qs-builder-head">
        <span class="qs-builder-icon"><?= $this->icon($spec['icon']) ?></span>
        <div>
          <h2><?= $this->e($tr($spec['title'])) ?> <code><?= $this->e($spec['name']) ?></code></h2>
          <?php if ($spec['description'] !== null): ?><p class="qs-muted"><?= $this->e($tr($spec['description'])) ?></p><?php endif ?>
          <?php if (!empty($draft['file'])): ?>
            <p class="qs-muted"><?= $this->icon('file-spreadsheet') ?>
              <?= $this->e($this->t('from_file', ['name' => $draft['file']['name'], 'rows' => (string) $draft['file']['rows']])) ?>
              <?php if (!empty($draft['file']['truncated'])): ?> · <?= $this->e($this->t('file_truncated')) ?><?php endif ?>
            </p>
          <?php endif ?>
        </div>
      </header>

      <?php if (!empty($draft['note'])): ?>
        <div class="qs-builder-note"><?= $this->icon('sparkles') ?> <span><?= $this->e($draft['note']) ?></span></div>
      <?php endif ?>
      <?php if ($errors): ?>
        <div class="qs-builder-errors" role="alert">
          <strong><?= $this->icon('triangle-alert') ?> <?= $this->e($this->t('errors.title')) ?></strong>
          <ul><?php foreach ($errors as $e): ?><li><?= $this->e($e) ?></li><?php endforeach ?></ul>
        </div>
      <?php endif ?>

      <div class="qs-table-wrap qs-builder-fields">
        <table class="qs-table">
          <thead>
            <tr>
              <th scope="col"><?= $this->e($this->t('col.field')) ?></th>
              <th scope="col"><?= $this->e($this->t('col.label')) ?></th>
              <th scope="col"><?= $this->e($this->t('col.type')) ?></th>
              <th scope="col"><?= $this->e($this->t('col.details')) ?></th>
              <th scope="col" class="qs-align-center"><?= $this->e($this->t('col.required')) ?></th>
              <th scope="col" class="qs-align-center"><?= $this->e($this->t('col.list')) ?></th>
              <th scope="col" class="qs-align-center"><?= $this->e($this->t('col.filter')) ?></th>
              <th scope="col" class="qs-align-center"><?= $this->e($this->t('col.search')) ?></th>
              <?php if (!empty($draft['file'])): ?><th scope="col"><?= $this->e($this->t('col.from')) ?></th><?php endif ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($spec['fields'] as $f): ?>
              <tr>
                <td><code><?= $this->e($f['name']) ?></code></td>
                <td><?= $this->e($tr($f['label'])) ?></td>
                <td><?= $this->e($this->t('types.' . $f['type'])) ?></td>
                <td class="qs-builder-details">
                  <?php if ($f['source'] !== null): ?>
                    <?= $this->e($this->t('details.source', ['table' => $f['source']['table'], 'label' => $f['source']['label']])) ?>
                  <?php elseif ($f['options']): ?>
                    <?php if ($f['multiple']): ?><?= $this->e($this->t('details.multiple')) ?>: <?php endif ?>
                    <?php foreach ($f['options'] as $o): ?>
                      <span class="qs-tag<?= $o['color'] ? ' qs-tone-' . $this->e($o['color']) : '' ?>"><?= $this->e($tr($o['label'])) ?></span>
                    <?php endforeach ?>
                  <?php elseif ($f['type'] === 'text'): ?>
                    <?= $this->e($this->t('details.maxlength', ['n' => (string) $f['maxlength']])) ?>
                  <?php elseif ($f['type'] === 'number'): ?>
                    <?= $this->e($this->t('details.decimals', ['n' => (string) $f['decimals']])) ?>
                  <?php endif ?>
                </td>
                <?php foreach (['required', 'list', 'filter', 'search'] as $flag): ?>
                  <td class="qs-align-center"><?= $f[$flag] ? $this->icon('check') : '' ?></td>
                <?php endforeach ?>
                <?php if (!empty($draft['file'])): ?><td class="qs-muted"><?= $this->e($f['from'] ?? '—') ?></td><?php endif ?>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>

      <?php if (count($spec['sections']) > 1): ?>
        <p class="qs-builder-sections"><strong><?= $this->e($this->t('sections')) ?>:</strong>
          <?= $this->e(implode(' · ', array_map(static fn ($s) => $tr($s['title']) . ' (' . count($s['fields']) . ')', $spec['sections']))) ?>
        </p>
      <?php endif ?>

      <form method="post" action="<?= $this->e($module->url(['a' => 'refine'])) ?>" class="qs-ai-bar qs-builder-refine"
            data-busy="<?= $this->e($this->t('refine.working')) ?>">
        <?= $this->csrfField() ?>
        <?= $this->icon('sparkles', 'qs-ai-icon') ?>
        <input class="qs-input" name="instruction" maxlength="2000" required placeholder="<?= $this->e($this->t('refine.placeholder')) ?>"
               aria-label="<?= $this->e($this->t('refine.label')) ?>">
        <button type="submit" class="qs-btn"<?= $aiEnabled ? '' : ' disabled' ?>><?= $this->e($this->t('refine.submit')) ?></button>
      </form>

      <?php if ($sql !== null): ?>
        <details class="qs-report-sql">
          <summary><?= $this->icon('database') ?> <?= $this->e($this->t('sql')) ?></summary>
          <pre><?= $this->e($sql) ?></pre>
        </details>
      <?php endif ?>
      <details class="qs-report-sql"<?= $errors ? ' open' : '' ?>>
        <summary><?= $this->icon('code') ?> <?= $this->e($this->t('json')) ?></summary>
        <form method="post" action="<?= $this->e($module->url(['a' => 'edit'])) ?>" class="qs-builder-json">
          <?= $this->csrfField() ?>
          <textarea class="qs-input" name="spec" rows="18" spellcheck="false"><?= $this->e($json) ?></textarea>
          <div><button type="submit" class="qs-btn"><?= $this->icon('check') ?> <?= $this->e($this->t('save_json')) ?></button></div>
        </form>
      </details>

      <div class="qs-builder-actions">
        <form method="post" action="<?= $this->e($module->url(['a' => 'discard'])) ?>">
          <?= $this->csrfField() ?>
          <button type="submit" class="qs-btn" data-confirm="<?= $this->e($this->t('confirm_discard')) ?>"><?= $this->icon('trash-2') ?> <?= $this->e($this->t('discard')) ?></button>
        </form>
        <form method="post" action="<?= $this->e($module->url(['a' => 'create'])) ?>" data-busy="<?= $this->e($this->t('create_working')) ?>">
          <?= $this->csrfField() ?>
          <button type="submit" class="qs-btn qs-btn-primary"<?= $errors || $notWritable ? ' disabled' : '' ?>
                  data-confirm="<?= $this->e($this->t(!empty($draft['file']) ? 'confirm_create_import' : 'confirm_create', ['name' => $spec['name'], 'rows' => (string) ($draft['file']['rows'] ?? 0)])) ?>">
            <?= $this->icon('blocks') ?> <?= $this->e($this->t(!empty($draft['file']) ? 'create_import' : 'create')) ?>
          </button>
        </form>
      </div>
    </section>
  <?php endif ?>
</div>
