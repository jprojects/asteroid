<?php
/**
 * Perfil del usuario conectado.
 *
 * @var \Quasar\Core\View $this
 * @var array{personal:list<\Quasar\Fields\Field>,security:list<\Quasar\Fields\Field>,password:list<\Quasar\Fields\Field>} $sections
 * @var array $values
 * @var array<string,string> $errors
 * @var string $mode            off | optional | required
 * @var bool $twoFactorActive
 * @var int $trustedDevices
 * @var ?string $lastLogin
 * @var string $username
 * @var ?array{tokens:list<array>,new:?string,url:string} $api   null si la API no está activada
 */
$renderField = function (\Quasar\Fields\Field $field) use ($values, $errors): string {
    $error = $errors[$field->name] ?? null;
    $classes = ['qs-field', 'qs-w-' . $field->width(), 'qs-type-' . $field->type()];
    if ($error) {
        $classes[] = 'has-error';
    }
    if ($field->isRequired(true)) {
        $classes[] = 'is-required';
    }
    ob_start(); ?>
    <div class="<?= $this->e(implode(' ', $classes)) ?>">
      <label for="<?= $this->e($field->inputId()) ?>"><?= $this->e($field->label()) ?></label>
      <?= $field->render($values[$field->name] ?? null, ['isNew' => true]) ?>
      <?php if ($error): ?>
        <div class="qs-error"><?= $this->e($error) ?></div>
      <?php elseif ($help = $field->help()): ?>
        <div class="qs-help"><?= $this->e($help) ?></div>
      <?php endif ?>
    </div>
    <?php return (string) ob_get_clean();
};
?>
<form method="post" action="<?= $this->url('profile') ?>" class="qs-form" data-dirty-check autocomplete="off">
  <?= $this->csrfField() ?>

  <div class="qs-toolbar">
    <a class="qs-btn" href="<?= $this->url('home') ?>" data-role="back" title="Esc"><?= $this->icon('arrow-left') ?> <?= $this->e($this->t('actions.back')) ?></a>
    <button type="submit" class="qs-btn qs-btn-primary" data-shortcut="save" title="Ctrl+S"><?= $this->e($this->t('actions.save')) ?></button>
    <span class="qs-toolbar-spacer"></span>
    <span class="qs-muted">
      <?= $this->e($this->t('info.username', ['username' => $username])) ?>
      <?php if ($lastLogin): ?> · <?= $this->e($this->t('info.last_login', ['date' => date($this->t('format.datetime'), (int) strtotime($lastLogin))])) ?><?php endif ?>
    </span>
  </div>

  <?php if ($errors): ?>
    <div class="qs-flash qs-flash-error" role="alert"><?= $this->e($this->t('form.has_errors')) ?></div>
  <?php endif ?>

  <fieldset class="qs-section">
    <legend><?= $this->e($this->t('sections.personal')) ?></legend>
    <div class="qs-grid">
      <?php foreach ($sections['personal'] as $field): ?><?= $renderField($field) ?><?php endforeach ?>
    </div>
  </fieldset>

  <?php if ($mode !== 'off'): ?>
    <fieldset class="qs-section">
      <legend><?= $this->e($this->t('sections.security')) ?></legend>
      <div class="qs-grid">
        <?php if ($mode === 'required'): ?>
          <p class="qs-field qs-w-full"><?= $this->e($this->t('two_factor_status.required')) ?></p>
        <?php endif ?>
        <?php foreach ($sections['security'] as $field): ?><?= $renderField($field) ?><?php endforeach ?>
        <?php if ($twoFactorActive): ?>
          <div class="qs-field qs-w-full qs-trusted">
            <span><?= $this->e($this->t('two_factor_status.devices', ['n' => $trustedDevices])) ?></span>
            <?php if ($trustedDevices > 0): ?>
              <button type="submit" name="_forget" value="1" class="qs-btn qs-btn-sm" formnovalidate
                      data-confirm="<?= $this->e($this->t('two_factor_status.forget_confirm')) ?>" data-confirm-variant="warning">
                <?= $this->e($this->t('two_factor_status.forget')) ?>
              </button>
            <?php endif ?>
          </div>
        <?php endif ?>
      </div>
    </fieldset>
  <?php endif ?>

  <fieldset class="qs-section">
    <legend><?= $this->e($this->t('sections.password')) ?></legend>
    <p class="qs-help"><?= $this->e($this->t('help.password_section')) ?></p>
    <div class="qs-grid">
      <?php foreach ($sections['password'] as $field): ?><?= $renderField($field) ?><?php endforeach ?>
    </div>
  </fieldset>
</form>

<?php if ($api !== null): ?>
  <?php $dtFmt = $this->t('format.datetime'); $dt = static fn (?string $d): string => $d ? date($dtFmt, (int) strtotime($d)) : '—'; ?>
  <div class="qs-form" id="api">
    <fieldset class="qs-section">
      <legend><?= $this->e($this->t('api.title')) ?></legend>
      <p class="qs-help"><?= $this->e($this->t('api.intro', ['url' => $api['url']])) ?></p>

      <?php if ($api['new'] !== null): ?>
        <div class="qs-flash qs-flash-success" role="status">
          <?= $this->e($this->t('api.created')) ?>
          <input class="qs-input qs-api-token" type="text" value="<?= $this->e($api['new']) ?>" readonly aria-label="<?= $this->e($this->t('api.token')) ?>">
        </div>
      <?php endif ?>

      <?php if ($api['tokens']): ?>
        <div class="qs-table-wrap qs-api-tokens">
          <table class="qs-table">
            <thead>
              <tr>
                <th scope="col"><?= $this->e($this->t('api.name')) ?></th>
                <th scope="col"><?= $this->e($this->t('api.created_at')) ?></th>
                <th scope="col"><?= $this->e($this->t('api.last_used')) ?></th>
                <th scope="col"><?= $this->e($this->t('api.expires')) ?></th>
                <th scope="col" class="qs-col-actions"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($api['tokens'] as $tk): ?>
                <tr>
                  <td><?= $this->e($tk['name']) ?> <span class="qs-muted">…<?= $this->e($tk['hint']) ?></span></td>
                  <td><?= $this->e($dt($tk['created_at'])) ?></td>
                  <td><?= $this->e($dt($tk['last_used_at'])) ?><?php if ($tk['last_ip']): ?> <span class="qs-muted">· <?= $this->e($tk['last_ip']) ?></span><?php endif ?></td>
                  <td><?= $this->e($tk['expires_at'] ? $dt($tk['expires_at']) : $this->t('api.never')) ?></td>
                  <td class="qs-col-actions">
                    <form method="post" action="<?= $this->url('profile') ?>">
                      <?= $this->csrfField() ?>
                      <button type="submit" name="_api_revoke" value="<?= (int) $tk['id'] ?>" class="qs-btn qs-btn-sm"
                              data-confirm="<?= $this->e($this->t('api.revoke_confirm', ['name' => $tk['name']])) ?>" data-confirm-variant="danger">
                        <?= $this->e($this->t('api.revoke')) ?>
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>

      <form method="post" action="<?= $this->url('profile') ?>" class="qs-grid">
        <?= $this->csrfField() ?>
        <div class="qs-field qs-w-half">
          <label for="api_name"><?= $this->e($this->t('api.name')) ?></label>
          <input class="qs-input" type="text" id="api_name" name="api_name" maxlength="100" placeholder="<?= $this->e($this->t('api.name_placeholder')) ?>">
        </div>
        <div class="qs-field qs-w-quarter">
          <label for="api_days"><?= $this->e($this->t('api.expires')) ?></label>
          <select class="qs-input" id="api_days" name="api_days">
            <?php foreach (['0' => 'api.never', '30' => 'api.days_30', '90' => 'api.days_90', '365' => 'api.days_365'] as $v => $label): ?>
              <option value="<?= $v ?>"><?= $this->e($this->t($label)) ?></option>
            <?php endforeach ?>
          </select>
        </div>
        <div class="qs-field qs-w-quarter qs-api-create">
          <button type="submit" name="_api_create" value="1" class="qs-btn"><?= $this->icon('plus') ?> <?= $this->e($this->t('api.create')) ?></button>
        </div>
      </form>
    </fieldset>
  </div>
<?php endif ?>
