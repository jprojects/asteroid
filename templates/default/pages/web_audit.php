<?php
/**
 * Asteroid · página "web_audit" (Quasar\Pages\WebAuditPage): salud SEO/GEO de las webs de los proyectos.
 * Las URL llegan sin escapar. Los estilos van aquí y no en style.css, que es del núcleo.
 *
 * @var \Quasar\Core\View $this
 * @var string $mode            list | detail
 * @var bool $isClient
 * @var bool $canRequest
 * list:   @var list<array> $projects  @var ?array $offer  @var string $offerUrl  @var bool $enabled
 * detail: @var array $project  @var array $audits  @var ?array $last  @var ?array $request  @var string $requestUrl
 *         @var ?string $refreshUrl  @var ?string $listUrl  @var callable $reportUrl
 */
use Quasar\WebAudit\WebAudit;

$dtFmt = $this->t('format.datetime');
$scoreLabel = fn (?int $s): string => $s === null ? '—' : $this->t($s >= 80 ? 'score_good' : ($s >= 50 ? 'score_mid' : 'score_bad'));
$trend = function (?array $cur, ?array $prev): string {
    if ($cur === null || $prev === null) {
        return '';
    }
    $d = (int) $cur['score'] - (int) $prev['score'];
    $icon = $d > 0 ? 'trending-up' : ($d < 0 ? 'trending-down' : 'minus');
    $tone = $d > 0 ? 'green' : ($d < 0 ? 'red' : 'gray');
    return '<span class="ast-wa-trend qs-tone-' . $tone . '" title="' . $this->e($this->t('trend_help', ['date' => date($this->t('format.date'), (int) strtotime((string) $prev['created_at']))])) . '">'
        . $this->icon($icon) . ' ' . ($d > 0 ? '+' : '') . $d . '</span>';
};
$ring = fn (?int $s, string $size = ''): string => '<span class="ast-wa-ring ' . $size . '" style="--p: ' . (int) $s . '; --c: var(--tone-' . WebAudit::tone($s) . ')"><span>' . ($s === null ? '—' : (int) $s) . '</span></span>';
?>
<style>
.ast-wa { display: grid; gap: 14px; flex-shrink: 0; max-width: 1100px; }
.ast-wa-box { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); min-width: 0; }
.ast-wa-head { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.ast-wa-head h2 { margin: 0; font-size: 15px; flex: 1; min-width: 180px; }
.ast-wa-head .qs-icon { color: var(--primary); }
.ast-wa-sub { color: var(--text-muted); font-size: 12.5px; }
.ast-wa-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); gap: 14px; }
.ast-wa-card { display: flex; gap: 14px; align-items: center; padding: 14px; color: var(--text); text-decoration: none; }
a.ast-wa-card:hover { background: var(--row-hover); }
.ast-wa-card-main { flex: 1; min-width: 0; }
.ast-wa-card-main strong { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ast-wa-pair { display: flex; gap: 14px; flex-shrink: 0; }
.ast-wa-mini { display: grid; justify-items: center; gap: 2px; font-size: 11px; color: var(--text-muted); font-weight: 600; }
.ast-wa-ring { --p: 0; --c: var(--tone-gray); position: relative; display: grid; place-items: center; width: 52px; height: 52px; border-radius: 50%; flex-shrink: 0;
  background: conic-gradient(var(--c) calc(var(--p) * 1%), var(--surface-alt) 0); }
.ast-wa-ring::before { content: ""; position: absolute; inset: 6px; border-radius: 50%; background: var(--surface); }
.ast-wa-ring span { position: relative; font-weight: 700; font-size: 15px; }
.ast-wa-ring.is-big { width: 86px; height: 86px; }
.ast-wa-ring.is-big::before { inset: 9px; }
.ast-wa-ring.is-big span { font-size: 24px; }
.ast-wa-kinds { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(420px, 100%), 1fr)); gap: 14px; align-items: start; }
.ast-wa-score { display: flex; gap: 16px; align-items: center; padding: 14px; }
.ast-wa-score h3 { margin: 0 0 2px; font-size: 15px; }
.ast-wa-score p { margin: 0; color: var(--text-muted); font-size: 12.5px; }
.ast-wa-trend { display: inline-flex; align-items: center; gap: 3px; font-weight: 700; font-size: 12.5px; margin-left: 6px; color: var(--c, inherit); }
.ast-wa-trend.qs-tone-green { color: var(--tone-green); } .ast-wa-trend.qs-tone-red { color: var(--tone-red); } .ast-wa-trend.qs-tone-gray { color: var(--text-muted); }
.ast-wa-trend .qs-icon { width: 15px; height: 15px; }
.ast-wa-cats { display: grid; gap: 6px; padding: 0 14px 14px; }
.ast-wa-cat { display: grid; grid-template-columns: minmax(120px, 1fr) 2fr 34px; gap: 10px; align-items: center; font-size: 12.5px; }
.ast-wa-bar { height: 7px; border-radius: 4px; background: var(--surface-alt); overflow: hidden; }
.ast-wa-bar i { display: block; height: 100%; border-radius: 4px; }
.ast-wa-issues { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--border); }
.ast-wa-issues li + li { border-top: 1px solid var(--border); }
.ast-wa-issue { display: flex; gap: 10px; align-items: flex-start; padding: 9px 14px; cursor: pointer; }
.ast-wa-issue:hover { background: var(--row-hover); }
.ast-wa-issue input { margin-top: 3px; flex-shrink: 0; }
.ast-wa-issue-main { flex: 1; min-width: 0; }
.ast-wa-issue-main strong { font-weight: 600; }
.ast-wa-issue-main small { display: block; color: var(--text-muted); font-size: 12px; margin-top: 2px; }
.ast-wa-solution { display: block; margin-top: 4px; padding: 6px 8px; border-left: 3px solid var(--primary); background: var(--primary-soft); border-radius: 4px; font-size: 12px; }
.ast-wa-empty { padding: 22px 14px; text-align: center; color: var(--text-muted); font-size: 13px; }
.ast-wa-foot { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 12px 14px; border-top: 1px solid var(--border); }
.ast-wa-foot p { margin: 0; flex: 1; min-width: 200px; color: var(--text-muted); font-size: 12.5px; }
.ast-wa-links { display: flex; gap: 8px; padding: 0 14px 12px; flex-wrap: wrap; }
@media (max-width: 640px) { .ast-wa-cat { grid-template-columns: 1fr 1fr 30px; } .ast-wa-card { flex-wrap: wrap; } }
</style>

<div class="ast-wa">
<?php if ($mode === 'list'): ?>
  <?php if (!$isClient && !$enabled): ?>
    <div class="qs-flash qs-flash-error" role="status"><?= $this->icon('triangle-alert') ?> <?= $this->e($this->t('not_configured')) ?></div>
  <?php endif ?>

  <?php if ($projects): ?>
    <div class="ast-wa-cards">
      <?php foreach ($projects as $p): ?>
        <?php $seo = $p['audits']['seo']['current']; $geo = $p['audits']['geo']['current']; ?>
        <a class="ast-wa-box ast-wa-card" href="<?= $this->e($p['url']) ?>">
          <span class="ast-wa-card-main">
            <strong><?= $this->e((string) $p['title']) ?></strong>
            <span class="ast-wa-sub"><?= !$isClient ? $this->e((string) $p['client']) . ' · ' : '' ?><?= $this->e(WebAudit::label((string) $p['website'])) ?></span>
            <?php if ($seo === null && $geo === null): ?><span class="ast-wa-sub"><br><?= $this->e($this->t('no_audit')) ?></span><?php endif ?>
          </span>
          <span class="ast-wa-pair">
            <span class="ast-wa-mini"><?= $ring($seo !== null ? (int) $seo['score'] : null) ?><?= $this->e($this->t('kinds.seo')) ?></span>
            <span class="ast-wa-mini"><?= $ring($geo !== null ? (int) $geo['score'] : null) ?><?= $this->e($this->t('kinds.geo')) ?></span>
          </span>
        </a>
      <?php endforeach ?>
    </div>
  <?php elseif ($isClient): ?>
    <section class="ast-wa-box">
      <div class="ast-wa-head"><?= $this->icon('gauge') ?><h2><?= $this->e($this->t('offer_heading')) ?></h2></div>
      <div class="ast-wa-empty" style="text-align: left"><?= $this->e($this->t('offer_text')) ?></div>
      <div class="ast-wa-foot">
        <?php if ($offer): ?>
          <p><?= $this->icon('clock') ?> <?= $this->e($this->t('requested_already', ['id' => $offer['id']])) ?></p>
          <a class="qs-btn" href="<?= $this->url('edit', ['m' => 'issues', 'id' => $offer['id']]) ?>"><?= $this->e($this->t('see_request')) ?></a>
        <?php elseif ($canRequest): ?>
          <form method="post" action="<?= $this->e($offerUrl) ?>"><?= $this->csrfField() ?>
            <button type="submit" class="qs-btn qs-btn-primary"><?= $this->icon('send') ?> <?= $this->e($this->t('offer_button')) ?></button>
          </form>
        <?php endif ?>
      </div>
    </section>
  <?php else: ?>
    <div class="ast-wa-box ast-wa-empty"><?= $this->e($this->t('team_empty')) ?></div>
  <?php endif ?>

<?php else: /* detail */ ?>
  <div class="qs-toolbar">
    <?php if ($listUrl): ?>
      <a class="qs-btn" href="<?= $this->e($listUrl) ?>"><?= $this->icon('arrow-left') ?> <?= $this->e($this->t('back')) ?></a>
    <?php endif ?>
    <?php if ($refreshUrl): ?>
      <form method="post" action="<?= $this->e($refreshUrl) ?>" data-loading><?= $this->csrfField() ?>
        <button type="submit" class="qs-btn"><?= $this->icon('refresh-cw') ?> <?= $this->e($this->t('refresh')) ?></button>
      </form>
    <?php endif ?>
  </div>

  <section class="ast-wa-box">
    <div class="ast-wa-head">
      <?= $this->icon('globe') ?>
      <h2><?= $this->e((string) $project['title']) ?>
        <span class="ast-wa-sub"> · <a href="https://<?= $this->e(WebAudit::site((string) $project['website'])) ?>" target="_blank" rel="noopener"><?= $this->e(WebAudit::label((string) $project['website'])) ?></a></span>
      </h2>
      <?php $cur = $audits['seo']['current'] ?? $audits['geo']['current']; ?>
      <?php if ($cur): ?><span class="ast-wa-sub"><?= $this->e($this->t('analysed_on', ['date' => date($dtFmt, (int) strtotime((string) $cur['created_at']))])) ?></span><?php endif ?>
    </div>
    <div class="ast-wa-empty" style="text-align: left"><?= $this->e($this->t('intro')) ?></div>
  </section>

  <?php if (!$isClient && $last && $last['score'] === null): ?>
    <div class="qs-flash qs-flash-error" role="status"><?= $this->icon('triangle-alert') ?> <?= $this->e($this->t('last_failed', ['date' => date($dtFmt, (int) strtotime((string) $last['created_at'])), 'error' => (string) $last['error']])) ?></div>
  <?php endif ?>

  <?php if (!$audits['seo']['current'] && !$audits['geo']['current']): ?>
    <div class="ast-wa-box ast-wa-empty"><?= $this->e($this->t($isClient ? 'no_audit_client' : 'no_audit_team')) ?></div>
  <?php else: ?>
  <form method="post" action="<?= $this->e($requestUrl) ?>">
    <?= $this->csrfField() ?>
    <div class="ast-wa-kinds">
      <?php foreach (WebAudit::KINDS as $kind): ?>
        <?php $a = $audits[$kind]['current']; if ($a === null) { continue; } $score = (int) $a['score']; ?>
        <section class="ast-wa-box" aria-labelledby="wa-<?= $kind ?>">
          <div class="ast-wa-score">
            <?= $ring($score, 'is-big') ?>
            <div>
              <h3 id="wa-<?= $kind ?>"><?= $this->e($this->t('kinds.' . $kind . '_long')) ?></h3>
              <p><strong><?= $this->e($scoreLabel($score)) ?></strong><?= $trend($a, $audits[$kind]['previous']) ?></p>
              <p><?= $this->e($this->t('passed', ['passed' => (int) $a['passed'], 'total' => (int) $a['total']])) ?></p>
            </div>
          </div>
          <div class="ast-wa-cats">
            <?php foreach ($a['categories'] as $cat): ?>
              <?php $cs = (int) ($cat['score'] ?? 0); ?>
              <div class="ast-wa-cat"><span><?= $this->e((string) ($cat['label'] ?? '')) ?></span>
                <span class="ast-wa-bar"><i style="width: <?= $cs ?>%; background: var(--tone-<?= WebAudit::tone($cs) ?>)"></i></span><strong><?= $cs ?></strong></div>
            <?php endforeach ?>
          </div>
          <?php if (!$isClient && ($link = $reportUrl($a))): ?>
            <div class="ast-wa-links">
              <a class="qs-btn qs-btn-sm" href="<?= $this->e($link) ?>" target="_blank" rel="noopener"><?= $this->icon('external-link') ?> <?= $this->e($this->t('open_pulsar')) ?></a>
              <a class="qs-btn qs-btn-sm" href="<?= $this->e((string) $reportUrl($a, true)) ?>" target="_blank" rel="noopener"><?= $this->icon('file-down') ?> PDF</a>
            </div>
          <?php endif ?>
          <?php if ($a['issues']): ?>
            <ul class="ast-wa-issues">
              <?php foreach ($a['issues'] as $issue): ?>
                <li><label class="ast-wa-issue">
                  <?php if ($canRequest && !$request): ?><input type="checkbox" name="issues[]" value="<?= $this->e($kind . ':' . $issue['id']) ?>"<?= $issue['status'] === 'fail' ? ' checked' : '' ?>><?php endif ?>
                  <span class="ast-wa-issue-main">
                    <span class="qs-tag qs-tone-<?= $issue['status'] === 'fail' ? 'red' : 'orange' ?>"><?= $this->e($this->t($issue['status'] === 'fail' ? 'severity_fail' : 'severity_warn')) ?></span>
                    <strong><?= $this->e((string) $issue['title']) ?></strong>
                    <?php if ((string) $issue['detail'] !== ''): ?><small><?= $this->e(mb_strimwidth((string) $issue['detail'], 0, 300, '…')) ?></small><?php endif ?>
                    <?php if (!$isClient && (string) $issue['solution'] !== ''): ?><span class="ast-wa-solution"><?= $this->icon('wrench') ?> <?= $this->e((string) $issue['solution']) ?></span><?php endif ?>
                  </span>
                </label></li>
              <?php endforeach ?>
            </ul>
          <?php else: ?>
            <div class="ast-wa-empty"><?= $this->e($this->t('all_good')) ?></div>
          <?php endif ?>
        </section>
      <?php endforeach ?>
    </div>

    <section class="ast-wa-box" style="margin-top: 14px">
      <div class="ast-wa-foot" style="border-top: 0">
        <?php if ($request): ?>
          <p><?= $this->icon('clock') ?> <?= $this->e($this->t('requested_already', ['id' => $request['id']])) ?></p>
          <a class="qs-btn" href="<?= $this->url('edit', ['m' => 'issues', 'id' => $request['id']]) ?>"><?= $this->e($this->t('see_request')) ?></a>
        <?php elseif ($canRequest): ?>
          <p><?= $this->e($this->t($isClient ? 'request_help' : 'request_help_team')) ?></p>
          <button type="submit" class="qs-btn qs-btn-primary"><?= $this->icon('send') ?> <?= $this->e($this->t($isClient ? 'request' : 'request_team')) ?></button>
        <?php endif ?>
      </div>
    </section>
  </form>
  <?php endif ?>
<?php endif ?>
</div>
