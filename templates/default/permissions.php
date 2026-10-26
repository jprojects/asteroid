<?php
/**
 * Matriz de permisos módulo × acción de un usuario o de un grupo.
 *
 * @var \Quasar\Core\View $this
 * @var 'user'|'group' $subject
 * @var string $formUrl
 * @var string $backUrl
 * @var bool $isAdmin
 * @var array<string,\Quasar\Core\Module> $modules
 * @var array<string,string> $actions acción => etiqueta
 * @var array<string,array<string,bool>> $granted
 * @var array<string,array<string,list<string>>> $inherited [módulo => [acción => grupos que lo conceden]]
 */
?>
<form method="post" action="<?= $formUrl ?>" class="qs-form qs-permissions" data-dirty-check>
  <?= $this->csrfField() ?>

  <div class="qs-toolbar">
    <a class="qs-btn" href="<?= $backUrl ?>" data-role="back"><?= $this->icon('arrow-left') ?> <?= $this->e($this->t($subject === 'group' ? 'permissions.back_groups' : 'permissions.back')) ?></a>
    <button type="submit" class="qs-btn qs-btn-primary" data-shortcut="save" title="Ctrl+S"><?= $this->e($this->t('permissions.save')) ?></button>
  </div>

  <?php if ($isAdmin): ?>
    <div class="qs-flash qs-flash-info"><?= $this->e($this->t('permissions.is_admin')) ?></div>
  <?php endif ?>
  <p class="qs-help">
    <?= $this->e($this->t('permissions.hint')) ?>
    <?php if ($inherited): ?>
      <br><?= $this->icon('users-round', 'qs-perm-inherited-icon') ?> <?= $this->e($this->t('permissions.inherited_hint')) ?>
    <?php elseif ($subject === 'group'): ?>
      <br><?= $this->e($this->t('permissions.group_hint')) ?>
    <?php endif ?>
  </p>

  <div class="qs-table-wrap">
    <table class="qs-table qs-perm-table">
      <thead>
        <tr>
          <th scope="col"><?= $this->e($this->t('permissions.module')) ?></th>
          <?php foreach ($actions as $action => $label): ?>
            <th scope="col" class="qs-align-center">
              <button type="button" class="qs-linkbtn" data-toggle-col="<?= $this->e($action) ?>"><?= $this->e($label) ?></button>
            </th>
          <?php endforeach ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($modules as $m): ?>
          <tr data-module="<?= $this->e($m->name) ?>">
            <th scope="row">
              <button type="button" class="qs-linkbtn" data-toggle-row>
                <?= $this->icon($m->icon()) ?> <?= $this->e($m->title()) ?>
              </button>
            </th>
            <?php foreach ($actions as $action => $label): ?>
              <td class="qs-align-center">
                <?php if (in_array($action, $m->actions(), true)): ?>
                  <input type="checkbox" class="qs-checkbox" value="1"
                         name="perm[<?= $this->e($m->name) ?>][<?= $this->e($action) ?>]"
                         data-action="<?= $this->e($action) ?>"
                         aria-label="<?= $this->e($m->title() . ' · ' . $label) ?>"
                         <?= !empty($granted[$m->name][$action]) ? 'checked' : '' ?>>
                  <?php if ($groups = $inherited[$m->name][$action] ?? null): ?>
                    <?php $by = $this->t('permissions.inherited', ['groups' => implode(', ', $groups)]) ?>
                    <span class="qs-perm-inherited" title="<?= $this->e($by) ?>"><?= $this->icon('users-round', '', $by) ?></span>
                  <?php endif ?>
                <?php else: ?>
                  <span class="qs-muted">—</span>
                <?php endif ?>
              </td>
            <?php endforeach ?>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
</form>
