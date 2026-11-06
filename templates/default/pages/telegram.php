<?php
/**
 * Asteroid · página "telegram" (Quasar\Pages\TelegramPage). Las URL llegan sin escapar.
 *
 * @var \Quasar\Core\View $this
 * @var bool $enabled
 * @var ?string $botName
 * @var ?array $link
 * @var ?string $code
 * @var bool $admin
 * @var ?array $webhook
 * @var string $hookUrl
 * @var bool $groupOk
 * @var list<string> $events
 * @var list<array> $linked
 * @var array<string,string> $urls
 * @var string $mode       webhook | poll
 * @var int $polledAt      última consulta del cron (modo poll)
 * @var string $pollCmd    línea del cron
 */
$dtFmt = $this->t('format.datetime');
$dt = static fn (?string $d): string => $d ? date($dtFmt, (int) strtotime($d)) : '—';
?>
<style>
.ast-tg { display: grid; gap: 14px; max-width: 900px; flex-shrink: 0; }
.ast-tg-code { font: 600 22px/1.2 ui-monospace, SFMono-Regular, Menlo, monospace; letter-spacing: 2px; }
.ast-tg-kv { display: grid; grid-template-columns: max-content 1fr; gap: 6px 14px; margin: 0; }
.ast-tg-kv dt { color: var(--text-muted); }
.ast-tg-kv dd { margin: 0; overflow-wrap: anywhere; }
.ast-tg-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.ast-tg-actions form { margin: 0; }
.ast-tg-gap { margin-top: 12px; }
.ast-tg-ok { color: var(--success); }
.ast-tg-bad { color: var(--danger); }
.ast-tg pre { margin: 0; padding: 10px 12px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius); white-space: pre-wrap; font-size: 12px; }
</style>

<div class="ast-tg qs-form">
  <?php if (!$enabled): ?>
    <fieldset class="qs-section">
      <legend><?= $this->e($this->t('setup_title')) ?></legend>
      <p><?= $this->e($this->t('setup_intro')) ?></p>
      <pre>'telegram' => [
    'token'     => '123456:ABC…',
    'chat_id'   => -1001234567890,
    'thread_id' => 42,
    'secret'    => '<?= $this->e(bin2hex(random_bytes(16))) ?>',
    'events'    => ['issue.new', 'issue.message'],
],</pre>
      <p class="qs-help"><?= $this->e($this->t('setup_help')) ?></p>
    </fieldset>
  <?php else: ?>
    <fieldset class="qs-section">
      <legend><?= $this->e($this->t('my_account')) ?></legend>
      <?php if ($link): ?>
        <p><?= $this->icon('circle-check', 'ast-tg-ok') ?> <?= $this->e($this->t('linked_as', ['name' => (string) $link['telegram_name'], 'date' => $dt($link['linked_at'])])) ?></p>
        <p class="qs-help"><?= $this->e($this->t('commands')) ?></p>
        <div class="ast-tg-actions">
          <form method="post" action="<?= $this->e($urls['unlink']) ?>"><?= $this->csrfField() ?>
            <button type="submit" class="qs-btn qs-btn-sm" data-confirm="<?= $this->e($this->t('unlink_confirm')) ?>"><?= $this->e($this->t('unlink')) ?></button>
          </form>
        </div>
      <?php elseif ($code): ?>
        <p><?= $this->e($this->t('code_intro')) ?></p>
        <p class="ast-tg-code"><?= $this->e($code) ?></p>
        <?php if ($botName): ?>
          <p><a class="qs-btn qs-btn-primary" href="https://t.me/<?= $this->e($botName) ?>?start=<?= $this->e($code) ?>" target="_blank" rel="noopener"><?= $this->icon('send') ?> <?= $this->e($this->t('open_bot', ['bot' => '@' . $botName])) ?></a></p>
        <?php endif ?>
        <p class="qs-help"><?= $this->e($this->t('code_help', ['code' => $code])) ?></p>
      <?php else: ?>
        <p><?= $this->e($this->t('link_intro')) ?></p>
        <form method="post" action="<?= $this->e($urls['code']) ?>"><?= $this->csrfField() ?>
          <button type="submit" class="qs-btn qs-btn-primary"><?= $this->icon('link') ?> <?= $this->e($this->t('link')) ?></button>
        </form>
      <?php endif ?>
    </fieldset>

    <?php if ($admin): ?>
      <fieldset class="qs-section">
        <legend><?= $this->e($this->t('bot_title')) ?></legend>
        <dl class="ast-tg-kv">
          <dt><?= $this->e($this->t('bot_label')) ?></dt>
          <dd><?= $botName ? $this->icon('circle-check', 'ast-tg-ok') . ' @' . $this->e($botName) : $this->icon('circle-x', 'ast-tg-bad') . ' ' . $this->e($this->t('bot_bad')) ?></dd>
          <dt><?= $this->e($this->t('group')) ?></dt>
          <dd><?= $groupOk ? $this->e(implode(', ', array_map(fn ($e) => $this->t('events.' . $e), $events))) : $this->e($this->t('group_missing')) ?></dd>
          <?php if ($mode === 'poll'): ?>
          <dt><?= $this->e($this->t('mode_poll')) ?></dt>
          <dd>
            <?php $fresh = $polledAt > time() - 180; ?>
            <?= $fresh ? $this->icon('circle-check', 'ast-tg-ok') : $this->icon('circle-x', 'ast-tg-bad') ?>
            <?= $this->e($polledAt ? $this->t('polled_at', ['date' => date($dtFmt, $polledAt)]) : $this->t('never_polled')) ?>
            <br><?php $cronOk = $cronAt > time() - 180; ?>
            <?= $cronOk ? $this->icon('circle-check', 'ast-tg-ok') : $this->icon('circle-x', 'ast-tg-bad') ?>
            <?= $this->e($cronAt ? $this->t('cron_at', ['date' => date($dtFmt, $cronAt)]) : $this->t('cron_never')) ?>
            <?php if ($cronBusyAt > $cronAt && $cronBusyAt > time() - 180): ?>
              <br><?= $this->icon('triangle-alert', 'ast-tg-bad') ?> <?= $this->e($this->t('cron_busy', ['date' => date($dtFmt, $cronBusyAt)])) ?>
            <?php endif ?>
            <?php if (is_array($lastUpdate ?? null)): ?>
              <br><span class="qs-muted"><?= $this->e($this->t('last_update', [
                  'date' => date($dtFmt, (int) $lastUpdate['at']),
                  'type' => $this->t('update_types.' . ($lastUpdate['type'] === 'callback_query' ? 'button' : ($lastUpdate['type'] === 'message' ? 'message' : 'other'))),
                  'via' => $this->t('update_via.' . ($lastUpdate['via'] === 'cron' ? 'cron' : 'web')),
              ])) ?></span>
            <?php endif ?>
            <br><span class="qs-muted"><?= $this->e($this->t('poll_help')) ?></span>
            <pre><?= $this->e($pollCmd) ?></pre>
          </dd>
          <?php else: ?>
          <dt><?= $this->e($this->t('webhook')) ?></dt>
          <dd>
            <?php $active = ($webhook['url'] ?? '') === $hookUrl; ?>
            <?= $active ? $this->icon('circle-check', 'ast-tg-ok') : $this->icon('circle-x', 'ast-tg-bad') ?>
            <?= $this->e($active ? $this->t('webhook_active') : $this->t('webhook_inactive')) ?>
            <br><span class="qs-muted"><?= $this->e($hookUrl) ?></span>
            <?php if (!empty($webhook['last_error_message'])): ?>
              <br><span class="ast-tg-bad"><?= $this->e($this->t('webhook_error', ['error' => (string) $webhook['last_error_message'], 'date' => date($dtFmt, (int) ($webhook['last_error_date'] ?? 0))])) ?></span>
            <?php endif ?>
            <?php if (!empty($webhook['pending_update_count'])): ?>
              <br><span class="qs-muted"><?= $this->e($this->t('webhook_pending', ['n' => (int) $webhook['pending_update_count']])) ?></span>
            <?php endif ?>
          </dd>
          <?php endif ?>
        </dl>
        <div class="ast-tg-actions ast-tg-gap">
          <?php if ($mode === 'webhook'): ?>
            <form method="post" action="<?= $this->e($urls['webhook']) ?>"><?= $this->csrfField() ?>
              <button type="submit" class="qs-btn qs-btn-sm"><?= $this->icon('refresh-cw') ?> <?= $this->e($this->t($active ? 'webhook_redo' : 'webhook_set')) ?></button>
            </form>
          <?php endif ?>
          <?php if ($groupOk): ?>
            <form method="post" action="<?= $this->e($urls['test']) ?>"><?= $this->csrfField() ?>
              <button type="submit" class="qs-btn qs-btn-sm"><?= $this->icon('send') ?> <?= $this->e($this->t('test_send')) ?></button>
            </form>
          <?php endif ?>
          <form method="post" action="<?= $this->e($urls['commands']) ?>"><?= $this->csrfField() ?>
            <button type="submit" class="qs-btn qs-btn-sm" title="<?= $this->e($this->t('commands_help')) ?>"><?= $this->icon('list') ?> <?= $this->e($this->t('commands_sync')) ?></button>
          </form>
        </div>
        <?php if ($linked): ?>
          <h3 class="qs-subtitle"><?= $this->e($this->t('linked_users')) ?></h3>
          <dl class="ast-tg-kv">
            <?php foreach ($linked as $l): ?>
              <dt><?= $this->e((string) $l['name']) ?></dt><dd><?= $this->e((string) $l['telegram_name']) ?> <span class="qs-muted">· <?= $this->e($dt($l['linked_at'])) ?></span></dd>
            <?php endforeach ?>
          </dl>
        <?php endif ?>
      </fieldset>
    <?php endif ?>
  <?php endif ?>
</div>
