<?php
/**
 * Asteroid · página "client_home" (Quasar\Pages\ClientHomePage): inicio de los usuarios de los clientes.
 * Las URL llegan sin escapar. Los estilos van aquí y no en style.css, que es del núcleo.
 *
 * @var \Quasar\Core\View $this
 * @var list<array> $open
 * @var int $openTotal
 * @var list<array> $unread
 * @var list<array> $resolved
 * @var list<array> $files
 * @var ?string $newIssueUrl
 * @var string $issuesUrl
 * @var ?string $filesUrl
 * @var list<array> $web    webs con informe SEO/GEO (Salut de la web)
 */
$dateFmt = $this->t('format.date');
$date = static fn (string $d): string => $d !== '' ? date($dateFmt, (int) strtotime($d)) : '';
$statusTone = [1 => 'red', 2 => 'blue', 3 => 'green', 4 => 'gray'];
$statusKey = [1 => 'pending', 2 => 'in_progress', 3 => 'resolved', 4 => 'discarded'];
?>
<style>
.ast-ch { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(330px, 100%), 1fr)); gap: 14px; flex-shrink: 0; }
.ast-ch-box { display: flex; flex-direction: column; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); min-width: 0; }
.ast-ch-head { display: flex; align-items: center; gap: 8px; padding: 12px 14px; border-bottom: 1px solid var(--border); }
.ast-ch-head h2 { margin: 0; font-size: 14px; flex: 1; }
.ast-ch-head .qs-icon { width: 18px; height: 18px; color: var(--primary); }
.ast-ch-count { min-width: 22px; padding: 1px 7px; border-radius: 11px; background: var(--primary-soft); color: var(--primary); font-weight: 700; font-size: 12px; text-align: center; }
.ast-ch-count.is-alert { background: color-mix(in srgb, var(--tone-red) 14%, var(--surface)); color: var(--tone-red); }
.ast-ch-list { list-style: none; margin: 0; padding: 0; }
.ast-ch-list li + li { border-top: 1px solid var(--border); }
.ast-ch-list a.ast-ch-item { display: flex; gap: 10px; align-items: flex-start; padding: 9px 14px; color: var(--text); text-decoration: none; }
.ast-ch-list a.ast-ch-item:hover { background: var(--row-hover); }
.ast-ch-main { flex: 1; min-width: 0; }
.ast-ch-title { display: block; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ast-ch-sub { display: block; color: var(--text-muted); font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ast-ch-excerpt { display: block; color: var(--text-muted); font-size: 12.5px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.ast-ch-side { flex-shrink: 0; text-align: right; font-size: 12px; color: var(--text-muted); }
.ast-ch-empty { padding: 22px 14px; text-align: center; color: var(--text-muted); font-size: 13px; }
.ast-ch-foot { margin-top: auto; padding: 10px 14px; border-top: 1px solid var(--border); }
.ast-ch-file { flex-shrink: 0; display: grid; place-items: center; width: 34px; height: 34px; border-radius: 6px; background: var(--surface-alt); border: 1px solid var(--border); font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); }
.ast-ch-resolved { margin-top: 14px; flex-shrink: 0; }
.ast-ch-web { margin-bottom: 14px; flex-shrink: 0; }
.ast-ch-web .ast-ch-item { align-items: center; }
.ast-ch-scores { display: flex; gap: 12px; flex-shrink: 0; }
.ast-ch-score { display: grid; justify-items: center; gap: 2px; font-size: 11px; font-weight: 600; color: var(--text-muted); }
.ast-ch-ring { --p: 0; --c: var(--tone-gray); position: relative; display: grid; place-items: center; width: 44px; height: 44px; border-radius: 50%;
  background: conic-gradient(var(--c) calc(var(--p) * 1%), var(--surface-alt) 0); }
.ast-ch-ring::before { content: ""; position: absolute; inset: 5px; border-radius: 50%; background: var(--surface); }
.ast-ch-ring b { position: relative; font-size: 13px; color: var(--text); }
</style>

<div class="qs-toolbar">
  <?php if ($newIssueUrl): ?>
    <a class="qs-btn qs-btn-primary" href="<?= $this->e($newIssueUrl) ?>" data-shortcut="new" title="Alt+N"><?= $this->icon('plus') ?> <?= $this->e($this->t('new_issue')) ?></a>
  <?php endif ?>
</div>

<?php if (!empty($web)): ?>
  <section class="ast-ch-box ast-ch-web" aria-labelledby="ch-web">
    <div class="ast-ch-head"><?= $this->icon('gauge') ?><h2 id="ch-web"><?= $this->e($this->t('web_audit:home_title')) ?></h2></div>
    <ul class="ast-ch-list">
      <?php foreach ($web as $w): ?>
        <li><a class="ast-ch-item" href="<?= $this->e($w['url']) ?>">
          <span class="ast-ch-main">
            <span class="ast-ch-title"><?= $this->e($w['site']) ?></span>
            <span class="ast-ch-sub"><?= $this->e($w['title']) ?> · <?= $this->e($this->t('web_audit:home_link')) ?> <?= $this->icon('arrow-right') ?></span>
          </span>
          <span class="ast-ch-scores">
            <?php foreach (['seo', 'geo'] as $k): ?>
              <span class="ast-ch-score"><span class="ast-ch-ring" style="--p: <?= (int) $w[$k] ?>; --c: var(--tone-<?= \Quasar\WebAudit\WebAudit::tone($w[$k]) ?>)"><b><?= $w[$k] === null ? '—' : (int) $w[$k] ?></b></span><?= $this->e($this->t('web_audit:kinds.' . $k)) ?></span>
            <?php endforeach ?>
          </span>
        </a></li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endif ?>

<div class="ast-ch">
  <section class="ast-ch-box" aria-labelledby="ch-open">
    <div class="ast-ch-head">
      <?= $this->icon('bug') ?><h2 id="ch-open"><?= $this->e($this->t('open_title')) ?></h2>
      <span class="ast-ch-count"><?= (int) $openTotal ?></span>
    </div>
    <?php if ($open): ?>
      <ul class="ast-ch-list">
        <?php foreach ($open as $i): ?>
          <li><a class="ast-ch-item" href="<?= $this->e($i['url']) ?>">
            <span class="ast-ch-main">
              <span class="ast-ch-title">#<?= (int) $i['id'] ?> · <?= $this->e($i['title']) ?></span>
              <span class="ast-ch-sub"><?= $this->e($i['project']) ?><?= $i['project'] !== '' ? ' · ' : '' ?><?= $this->e($date($i['at'])) ?></span>
            </span>
            <span class="ast-ch-side">
              <span class="qs-tag qs-tone-<?= $statusTone[$i['status']] ?? 'gray' ?>"><?= $this->e($this->t('issues:status.' . ($statusKey[$i['status']] ?? 'pending'))) ?></span>
              <?php if ($i['priority'] > 0): ?><br><span class="qs-tag qs-tone-<?= $i['priority'] === 2 ? 'red' : 'orange' ?>"><?= $this->e($this->t('issues:priority.' . ($i['priority'] === 2 ? 'panic' : 'urgent'))) ?></span><?php endif ?>
            </span>
          </a></li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <div class="ast-ch-empty"><?= $this->e($this->t('open_empty')) ?></div>
    <?php endif ?>
    <div class="ast-ch-foot"><a class="qs-btn qs-btn-sm" href="<?= $this->e($issuesUrl) ?>"><?= $this->e($this->t('all_issues')) ?> <?= $this->icon('arrow-right') ?></a></div>
  </section>

  <section class="ast-ch-box" aria-labelledby="ch-unread">
    <div class="ast-ch-head">
      <?= $this->icon('message-circle') ?><h2 id="ch-unread"><?= $this->e($this->t('unread_title')) ?></h2>
      <span class="ast-ch-count<?= $unread ? ' is-alert' : '' ?>"><?= array_sum(array_column($unread, 'unread')) ?></span>
    </div>
    <?php if ($unread): ?>
      <ul class="ast-ch-list">
        <?php foreach ($unread as $u): ?>
          <li><a class="ast-ch-item" href="<?= $this->e($u['url']) ?>">
            <span class="ast-ch-main">
              <span class="ast-ch-title">#<?= (int) $u['id'] ?> · <?= $this->e($u['title']) ?></span>
              <span class="ast-ch-excerpt"><?php if ($u['author'] !== ''): ?><strong><?= $this->e($u['author']) ?>:</strong> <?php endif ?><?= $this->e($u['excerpt']) ?></span>
            </span>
            <span class="ast-ch-side"><span class="ast-ch-count is-alert"><?= (int) $u['unread'] ?></span><br><?= $this->e($date($u['at'])) ?></span>
          </a></li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <div class="ast-ch-empty"><?= $this->e($this->t('unread_empty')) ?></div>
    <?php endif ?>
  </section>

  <section class="ast-ch-box" aria-labelledby="ch-files">
    <div class="ast-ch-head">
      <?= $this->icon('files') ?><h2 id="ch-files"><?= $this->e($this->t('files_title')) ?></h2>
    </div>
    <?php if ($files): ?>
      <ul class="ast-ch-list">
        <?php foreach ($files as $f): ?>
          <li><a class="ast-ch-item" href="<?= $this->e($f['download'] ?? $f['url']) ?>"<?= $f['download'] ? ' download' : '' ?>>
            <span class="ast-ch-file" aria-hidden="true"><?= $this->e($f['ext'] !== '' ? mb_substr($f['ext'], 0, 4) : '·') ?></span>
            <span class="ast-ch-main">
              <span class="ast-ch-title"><?= $this->e($f['title']) ?></span>
              <span class="ast-ch-sub"><?= $this->e($f['folder']) ?> · <?= $this->e($date($f['at'])) ?></span>
            </span>
            <span class="ast-ch-side"><?= $this->icon('download', '', $this->t('download')) ?></span>
          </a></li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <div class="ast-ch-empty"><?= $this->e($this->t('files_empty')) ?></div>
    <?php endif ?>
    <?php if ($filesUrl): ?>
      <div class="ast-ch-foot"><a class="qs-btn qs-btn-sm" href="<?= $this->e($filesUrl) ?>"><?= $this->e($this->t('all_files')) ?> <?= $this->icon('arrow-right') ?></a></div>
    <?php endif ?>
  </section>
</div>

<?php if ($resolved): ?>
  <section class="ast-ch-box ast-ch-resolved" aria-labelledby="ch-resolved">
    <div class="ast-ch-head">
      <?= $this->icon('circle-check') ?><h2 id="ch-resolved"><?= $this->e($this->t('resolved_title')) ?></h2>
    </div>
    <ul class="ast-ch-list">
      <?php foreach ($resolved as $r): ?>
        <li><a class="ast-ch-item" href="<?= $this->e($r['url']) ?>">
          <span class="ast-ch-main">
            <span class="ast-ch-title">#<?= (int) $r['id'] ?> · <?= $this->e($r['title']) ?></span>
            <span class="ast-ch-sub"><?= $this->e($r['project']) ?></span>
          </span>
          <span class="ast-ch-side"><?= $this->e($this->t('resolved_on', ['date' => $date($r['at'])])) ?></span>
        </a></li>
      <?php endforeach ?>
    </ul>
  </section>
<?php endif ?>
