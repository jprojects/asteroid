<?php
/**
 * Campo tags. Cada etiqueta lleva un input oculto "{name}[]"; el input vacío inicial
 * permite enviar "sin etiquetas". Sin JavaScript, el campo de texto admite valores
 * separados por comas.
 *
 * @var \Quasar\Core\View $this
 * @var string $id
 * @var string $name
 * @var list<array{0:string,1:string}> $tags  [texto, color]
 * @var bool $readonly
 * @var array $config
 * @var string $placeholder
 */
$toneAttr = static fn (string $tone): string => str_starts_with($tone, '#')
    ? 'class="qs-tag" style="--t:' . $tone . '"'
    : 'class="qs-tag qs-tone-' . $tone . '"';
?>
<div class="qs-tags<?= $readonly ? ' is-readonly' : '' ?>" <?= $readonly ? '' : 'data-tags="' . $this->json($config) . '"' ?>>
  <input type="hidden" name="<?= $this->e($name) ?>" value="">
  <?php foreach ($tags as [$text, $tone]): ?>
    <span <?= $toneAttr($tone) ?> data-tag>
      <input type="hidden" name="<?= $this->e($name) ?>[]" value="<?= $this->e($text) ?>">
      <span class="qs-tag-text"><?= $this->e($text) ?></span>
      <?php if (!$readonly): ?>
        <button type="button" class="qs-tag-remove" data-remove title="<?= $this->e($config['i18n']['remove']) ?>"
                aria-label="<?= $this->e($config['i18n']['remove'] . ': ' . $text) ?>"><?= $this->icon('x') ?></button>
      <?php endif ?>
    </span>
  <?php endforeach ?>
  <?php if ($readonly): ?>
    <?php if (!$tags): ?><span class="qs-muted">—</span><?php endif ?>
  <?php else: ?>
    <input type="text" class="qs-tags-input" id="<?= $this->e($id) ?>" data-tags-input autocomplete="off"
           placeholder="<?= $this->e($placeholder) ?>" maxlength="<?= (int) $config['maxLength'] ?>"
           aria-autocomplete="list" role="combobox" aria-expanded="false">
  <?php endif ?>
</div>
