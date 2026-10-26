<?php
/**
 * Botones propios de un módulo ("list.toolbar" y "form.buttons"): enlaces o acciones que app.js
 * ejecuta por POST (data-qs-action). Ver ModuleController::buttonConfig().
 *
 * @var \Quasar\Core\View $this
 * @var list<array<string,mixed>> $buttons
 */
foreach ($buttons as $b):
    $class = 'qs-btn' . (['primary' => ' qs-btn-primary', 'danger' => ' qs-btn-danger'][$b['variant']] ?? '');
    $inner = ($b['icon'] !== '' ? $this->icon($b['icon']) . ' ' : '') . $this->e($b['label']);
    if (isset($b['url'])): ?>
  <a class="<?= $class ?>" href="<?= $this->e($b['url']) ?>"><?= $inner ?></a>
<?php else: ?>
  <button type="button" class="<?= $class ?>" data-qs-action="<?= $this->json($b) ?>"><?= $inner ?></button>
<?php endif;
endforeach;
