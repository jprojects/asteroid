<?php
/**
 * Campo media (dropzone). Cada archivo es un elemento con un input oculto
 * "{name}[]" (múltiple) o "{name}" (único) que lleva el id de la tabla media.
 * El input vacío inicial permite enviar "sin archivos".
 *
 * @var \Quasar\Core\View $this
 * @var string $id
 * @var string $name
 * @var bool $multiple
 * @var bool $readonly
 * @var list<array> $files
 * @var array $config
 * @var string $acceptAttr
 * @var string $hint
 */
$inputName = $multiple ? $name . '[]' : $name;
?>
<div class="qs-media<?= $multiple ? ' is-multiple' : ' is-single' ?><?= $readonly ? ' is-readonly' : '' ?>" id="<?= $this->e($id) ?>"
     <?= $readonly ? '' : 'data-media="' . $this->json($config) . '"' ?>>
  <input type="hidden" name="<?= $this->e($name) ?>" value="">

  <ul class="qs-media-list" data-list>
    <?php foreach ($files as $file): ?>
      <li class="qs-media-item" data-item>
        <input type="hidden" name="<?= $this->e($inputName) ?>" value="<?= (int) $file['id'] ?>">
        <?php if (!$readonly && $multiple): ?>
          <span class="qs-repeater-handle" data-drag-handle aria-hidden="true"><?= $this->icon('grip-vertical') ?></span>
        <?php endif ?>
        <a class="qs-media-thumb" href="<?= $this->e($file['url']) ?>" target="_blank" rel="noopener">
          <?php if ($file['isImage']): ?>
            <img src="<?= $this->e($file['thumb']) ?>" alt="" loading="lazy">
          <?php else: ?>
            <span class="qs-media-ext"><?= $this->e(strtoupper($file['ext'])) ?></span>
          <?php endif ?>
        </a>
        <span class="qs-media-meta">
          <a class="qs-media-name" href="<?= $this->e($file['download']) ?>" title="<?= $this->e($this->t('media.download')) ?>"><?= $this->e($file['name']) ?></a>
          <span class="qs-muted"><?= $this->e($file['sizeText']) ?></span>
        </span>
        <?php if (!$readonly): ?>
          <button type="button" class="qs-icon-btn qs-danger" data-remove title="<?= $this->e($this->t('media.remove')) ?>"><?= $this->icon('x') ?></button>
        <?php endif ?>
      </li>
    <?php endforeach ?>
  </ul>

  <?php if ($readonly): ?>
    <?php if (!$files): ?><span class="qs-muted">—</span><?php endif ?>
  <?php else: ?>
    <label class="qs-dropzone" data-dropzone tabindex="0">
      <input type="file" class="qs-visually-hidden" data-file-input
             <?= $multiple ? 'multiple' : '' ?> <?= $acceptAttr !== '' ? 'accept="' . $this->e($acceptAttr) . '"' : '' ?>>
      <span class="qs-dropzone-icon"><?= $this->icon('cloud-upload') ?></span>
      <span><?= $this->e($this->t($multiple ? 'media.drop_many' : 'media.drop_one')) ?></span>
      <span class="qs-muted"><?= $this->e($hint) ?></span>
    </label>
  <?php endif ?>
</div>
