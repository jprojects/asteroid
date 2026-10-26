<?php
/**
 * Página "integrations" (Quasar\Pages\IntegrationsPage): conexión con Google para Google Meet.
 *
 * @var \Quasar\Core\View $this
 * @var ?array{email:string,connected_at:string} $connection
 * @var string $redirectUri  dirección que hay que dar de alta en Google Cloud
 * @var string $connectUrl
 * @var string $disconnectUrl
 */
?>
<section class="qs-section qs-integration">
  <h2 class="qs-integration-title"><?= $this->icon('video') ?> <?= $this->e($this->t('google.title')) ?></h2>
  <p class="qs-muted"><?= $this->e($this->t('google.intro')) ?></p>

  <?php if ($connection !== null): ?>
    <p class="qs-integration-state is-on">
      <?= $this->icon('circle-check') ?>
      <?= $this->e($this->t('google.connected_as', [
          'email' => $connection['email'] ?: '—',
          'date' => $connection['connected_at'] !== '' ? date($this->t('format.datetime'), strtotime($connection['connected_at'])) : '—',
      ])) ?>
    </p>
    <form method="post" action="<?= $this->e($disconnectUrl) ?>">
      <?= $this->csrfField() ?>
      <button type="submit" class="qs-btn qs-btn-danger" data-confirm="<?= $this->e($this->t('google.disconnect_confirm')) ?>">
        <?= $this->icon('unplug') ?> <?= $this->e($this->t('google.disconnect')) ?>
      </button>
    </form>
  <?php else: ?>
    <p class="qs-integration-state"><?= $this->icon('circle-alert') ?> <?= $this->e($this->t('google.not_connected')) ?></p>
    <a class="qs-btn qs-btn-primary" href="<?= $this->e($connectUrl) ?>"><?= $this->icon('plug') ?> <?= $this->e($this->t('google.connect')) ?></a>
  <?php endif ?>

  <details class="qs-integration-help">
    <summary><?= $this->e($this->t('google.setup_title')) ?></summary>
    <ol>
      <li><?= $this->e($this->t('google.setup_1')) ?></li>
      <li><?= $this->e($this->t('google.setup_2')) ?></li>
      <li><?= $this->e($this->t('google.setup_3')) ?> <code><?= $this->e($redirectUri) ?></code></li>
      <li><?= $this->e($this->t('google.setup_4')) ?></li>
    </ol>
  </details>
</section>
