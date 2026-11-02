<?php
/**
 * Asteroid · página pública "survey" (Quasar\Pages\SurveyPage): encuesta de satisfacción de una incidencia.
 * Estrellas sin JavaScript (radios en orden inverso). Los estilos van aquí y no en style.css, que es del núcleo.
 *
 * @var \Quasar\Core\View $this
 * @var string $state      open | answered | expired | invalid
 * @var ?array $survey
 * @var array $values
 * @var ?string $error
 * @var string $action     URL del formulario (sin escapar)
 */
$questions = \Quasar\Pages\SurveyPage::QUESTIONS;
?>
<style>
.ast-survey { max-width: 480px; }
.ast-survey .qs-login-card { gap: 18px; }
.ast-survey-issue { color: var(--text-muted); }
.ast-q { display: flex; flex-direction: column; gap: 6px; margin: 0; padding: 0; border: 0; }
.ast-q legend { padding: 0; margin-bottom: 6px; font-weight: 600; }
.ast-stars { display: inline-flex; flex-direction: row-reverse; justify-content: flex-end; gap: 2px; }
.ast-stars input { position: absolute; opacity: 0; width: 1px; height: 1px; }
.ast-stars label { font-size: 34px; line-height: 1; cursor: pointer; color: var(--border-strong); transition: color .1s; }
.ast-stars input:checked ~ label,
.ast-stars label:hover, .ast-stars label:hover ~ label { color: var(--tone-amber); }
.ast-stars input:focus-visible + label { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 4px; }
.ast-survey-form { display: flex; flex-direction: column; gap: 18px; }
.ast-survey textarea { min-height: 90px; resize: vertical; }
.ast-survey-done { text-align: center; }
.ast-survey-done .qs-icon { width: 40px; height: 40px; color: var(--success); }
</style>

<div class="qs-login ast-survey">
  <div class="qs-login-card">
    <div class="qs-login-brand">
      <?php if ($this->logoUrl()): ?>
        <img class="qs-login-logo" src="<?= $this->logoUrl() ?>" alt="<?= $this->e($this->appName()) ?>">
      <?php else: ?>
        <img class="qs-brand-mark" src="<?= $this->asset('logo.svg') ?>" alt="" width="28" height="28"><?= $this->e($this->appName()) ?>
      <?php endif ?>
    </div>

    <?php if ($state === 'open'): ?>
      <div>
        <h1><?= $this->e($this->t('heading')) ?></h1>
        <p class="ast-survey-issue">#<?= (int) $survey['issue_id'] ?> · <?= $this->e($survey['issue_title']) ?></p>
      </div>
      <p><?= $this->e($this->t('intro')) ?></p>
      <?php if ($error): ?>
        <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($error) ?></div>
      <?php endif ?>

      <form method="post" action="<?= $this->e($action) ?>" class="ast-survey-form">
        <?= $this->csrfField() ?>
        <?php foreach ($questions as $q): ?>
          <fieldset class="ast-q">
            <legend><?= $this->e($this->t('questions.' . $q)) ?></legend>
            <div class="ast-stars">
              <?php for ($n = 5; $n >= 1; $n--): ?>
                <input type="radio" name="<?= $q ?>" id="<?= $q . $n ?>" value="<?= $n ?>"<?= ($values[$q] ?? null) === $n ? ' checked' : '' ?> required>
                <label for="<?= $q . $n ?>" title="<?= $this->e($this->t('scale.' . $n)) ?>">★<span class="qs-visually-hidden"><?= $this->e($this->t('scale.' . $n)) ?></span></label>
              <?php endfor ?>
            </div>
          </fieldset>
        <?php endforeach ?>
        <label class="qs-field">
          <span><?= $this->e($this->t('questions.comment')) ?></span>
          <textarea class="qs-input" name="comment" maxlength="2000" placeholder="<?= $this->e($this->t('comment_placeholder')) ?>"><?= $this->e($values['comment']) ?></textarea>
        </label>
        <button type="submit" class="qs-btn qs-btn-primary qs-btn-block"><?= $this->e($this->t('submit')) ?></button>
      </form>
    <?php else: ?>
      <div class="ast-survey-done">
        <?= $this->icon($state === 'answered' ? 'circle-check' : 'circle-alert') ?>
        <h1><?= $this->e($this->t('states.' . $state . '.title')) ?></h1>
        <p><?= $this->e($this->t('states.' . $state . '.text')) ?></p>
      </div>
    <?php endif ?>
  </div>
</div>
