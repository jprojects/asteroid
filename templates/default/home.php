<?php
/** @var \Quasar\Core\View $this */
/** @var array<string,list<\Quasar\Core\Module>> $groups */
?>
<div class="qs-home">
  <p class="qs-lead"><?= $this->e($this->t($groups ? 'home.intro' : 'home.empty')) ?></p>

  <?php foreach ($groups as $group => $modules): ?>
    <h2 class="qs-subtitle"><?= $this->e($this->t('menu.' . $group)) ?></h2>
    <div class="qs-tiles">
      <?php foreach ($modules as $m): ?>
        <a class="qs-tile" href="<?= $this->e($m->url()) ?>">
          <span class="qs-tile-icon"><?= $this->icon($m->icon()) ?></span>
          <span class="qs-tile-title"><?= $this->e($m->title()) ?></span>
        </a>
      <?php endforeach ?>
    </div>
  <?php endforeach ?>
</div>
