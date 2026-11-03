<?php
/**
 * Asteroid · página "pending_billing" (Quasar\Pages\PendingBillingPage). Las URL llegan sin escapar.
 * Los estilos van aquí y no en style.css, que es del núcleo.
 *
 * @var \Quasar\Core\View $this
 * @var list<array> $clients
 * @var array $totals
 * @var ?string $until
 * @var string $lastMonthEnd
 * @var array{all:string,lastMonth:string,create:?string,notBillable:?string} $urls
 */
$dec = $this->t('format.decimal_point');
$thou = $this->t('format.thousands_sep');
$money = static fn (float $v): string => number_format($v, 2, $dec, $thou) . ' €';
$dateFmt = $this->t('format.date');
$date = static fn (string $d): string => date($dateFmt, (int) strtotime($d));
?>
<style>
/* El contenido del layout es una columna flexible de altura fija: los bloques no se encogen */
.ast-pb-summary, .ast-pb-client, .ast-pb-empty { flex-shrink: 0; }
.ast-pb-summary { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
.ast-pb-stat { flex: 1 1 160px; padding: 12px 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
.ast-pb-stat strong { display: block; font-size: 22px; font-variant-numeric: tabular-nums; }
.ast-pb-stat span { color: var(--text-muted); font-size: 12px; }
.ast-pb-warn strong { color: var(--tone-amber); }
.ast-pb-client { margin-bottom: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
.ast-pb-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; padding: 10px 14px; background: var(--surface-alt); border-bottom: 1px solid var(--border); }
.ast-pb-head h2 { margin: 0; font-size: 15px; flex: 1; min-width: 160px; }
.ast-pb-head .ast-pb-num { font-variant-numeric: tabular-nums; font-weight: 600; }
.ast-pb-wrap { overflow-x: auto; }
.ast-pb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ast-pb-table th, .ast-pb-table td { padding: 7px 14px; text-align: left; border-bottom: 1px solid var(--border); white-space: nowrap; }
.ast-pb-table th { color: var(--text-muted); font-weight: 600; font-size: 12px; }
.ast-pb-table tr:last-child td { border-bottom: 0; }
.ast-pb-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.ast-pb-table td.ast-pb-project { white-space: normal; min-width: 180px; }
.ast-pb-table a { color: var(--text); }
.ast-pb-actions { display: flex; gap: 6px; justify-content: flex-end; }
.ast-pb-actions form { margin: 0; }
.ast-pb-muted { color: var(--text-muted); }
.ast-pb-norate { color: var(--tone-amber); }
.ast-pb-date { width: auto; }
.ast-pb-empty { padding: 40px 16px; text-align: center; color: var(--text-muted); background: var(--surface); border: 1px dashed var(--border); border-radius: var(--radius); }
</style>

<div class="qs-toolbar">
  <span class="qs-filter-toggle-group" role="group" aria-label="<?= $this->e($this->t('period')) ?>">
    <a class="qs-btn qs-btn-sm<?= $until === null ? ' is-active' : '' ?>" href="<?= $this->e($urls['all']) ?>"><?= $this->e($this->t('all')) ?></a>
    <a class="qs-btn qs-btn-sm<?= $until === $lastMonthEnd ? ' is-active' : '' ?>" href="<?= $this->e($urls['lastMonth']) ?>"><?= $this->e($this->t('until_last_month', ['date' => $date($lastMonthEnd)])) ?></a>
  </span>
  <form method="get" action="index.php" class="qs-filter-toggle-group">
    <input type="hidden" name="r" value="page"><input type="hidden" name="m" value="pending_billing">
    <label class="qs-visually-hidden" for="pb-until"><?= $this->e($this->t('until')) ?></label>
    <input type="date" id="pb-until" name="until" value="<?= $this->e($until ?? '') ?>" class="qs-input ast-pb-date">
    <button type="submit" class="qs-btn qs-btn-sm"><?= $this->icon('calendar') ?> <?= $this->e($this->t('until')) ?></button>
  </form>
</div>

<?php if (!$clients): ?>
  <div class="ast-pb-empty"><?= $this->icon('circle-check') ?> <?= $this->e($this->t('empty')) ?></div>
<?php else: ?>
  <div class="ast-pb-summary">
    <div class="ast-pb-stat"><strong><?= $this->e($totals['hours']) ?></strong><span><?= $this->e($this->t('stat_hours')) ?></span></div>
    <div class="ast-pb-stat"><strong><?= $this->e($money($totals['amount'])) ?></strong><span><?= $this->e($this->t('stat_amount')) ?></span></div>
    <div class="ast-pb-stat"><strong><?= count($clients) ?></strong><span><?= $this->e($this->t('stat_clients')) ?></span></div>
    <?php if ($totals['budget_minutes'] > 0): ?>
      <div class="ast-pb-stat"><strong><?= $this->e($totals['budget_hours']) ?></strong><span><?= $this->e($this->t('stat_budget')) ?></span></div>
    <?php endif ?>
    <?php if ($totals['no_rate'] > 0): ?>
      <div class="ast-pb-stat ast-pb-warn"><strong><?= (int) $totals['no_rate'] ?></strong><span><?= $this->e($this->t('stat_no_rate')) ?></span></div>
    <?php endif ?>
  </div>

  <?php foreach ($clients as $c): ?>
    <section class="ast-pb-client">
      <div class="ast-pb-head">
        <h2><?= $this->e($c['name'] !== '' ? $c['name'] : $this->t('no_client')) ?></h2>
        <span class="ast-pb-num"><?= $this->e($c['hours']) ?> h</span>
        <?php if ($c['amount'] > 0): ?><span class="ast-pb-num"><?= $this->e($money($c['amount'])) ?></span><?php endif ?>
        <?php if ($urls['create'] && $c['id'] !== null && count($c['projects']) > 1): ?>
          <form method="post" action="<?= $this->e($urls['create']) ?>">
            <?= $this->csrfField() ?>
            <input type="hidden" name="client" value="<?= (int) $c['id'] ?>">
            <?php if ($until): ?><input type="hidden" name="until" value="<?= $this->e($until) ?>"><?php endif ?>
            <button type="submit" class="qs-btn qs-btn-sm qs-btn-primary" data-confirm="<?= $this->e($this->t('confirm_client', ['client' => $c['name'], 'hours' => $c['hours']])) ?>">
              <?= $this->icon('receipt-euro') ?> <?= $this->e($this->t('create_client')) ?>
            </button>
          </form>
        <?php endif ?>
      </div>
      <div class="ast-pb-wrap">
        <table class="ast-pb-table">
          <thead>
            <tr>
              <th scope="col"><?= $this->e($this->t('col.project')) ?></th>
              <th scope="col" class="num"><?= $this->e($this->t('col.hours')) ?></th>
              <th scope="col" class="num"><?= $this->e($this->t('col.rate')) ?></th>
              <th scope="col" class="num"><?= $this->e($this->t('col.amount')) ?></th>
              <th scope="col"><?= $this->e($this->t('col.period')) ?></th>
              <th scope="col"><span class="qs-visually-hidden"><?= $this->e($this->t('col.actions')) ?></span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($c['projects'] as $p): ?>
              <tr>
                <td class="ast-pb-project">
                  <?php if ($p['projectUrl']): ?><a href="<?= $this->e($p['projectUrl']) ?>"><?= $this->e($p['title']) ?></a><?php else: ?><span class="ast-pb-muted"><?= $this->e($this->t('no_project')) ?></span><?php endif ?>
                  <?php if ($p['budget'] !== null): ?> <span class="qs-tag qs-tone-purple"><?= $this->e($this->t('budget', ['amount' => $money($p['budget'])])) ?></span><?php endif ?>
                </td>
                <td class="num"><a href="<?= $this->e($p['hoursUrl']) ?>" title="<?= $this->e($this->t('see_hours', ['n' => $p['entries']])) ?>"><?= $this->e($p['hours']) ?></a></td>
                <td class="num">
                  <?php if ($p['rate'] !== null): ?><?= $this->e($money($p['rate'])) ?>
                  <?php elseif ($p['budget'] === null && $p['projectUrl']): ?><a class="ast-pb-norate" href="<?= $this->e($p['projectUrl']) ?>"><?= $this->icon('triangle-alert') ?> <?= $this->e($this->t('no_rate')) ?></a>
                  <?php else: ?><span class="ast-pb-muted">—</span><?php endif ?>
                </td>
                <td class="num"><?= $p['amount'] !== null ? $this->e($money($p['amount'])) : '<span class="ast-pb-muted">—</span>' ?></td>
                <td class="ast-pb-muted"><?= $this->e($date($p['first_on'])) ?><?= $p['first_on'] !== $p['last_on'] ? ' – ' . $this->e($date($p['last_on'])) : '' ?></td>
                <td>
                  <?php if ($urls['create'] && $c['id'] !== null && $p['id'] !== null): ?>
                    <div class="ast-pb-actions">
                      <form method="post" action="<?= $this->e($urls['create']) ?>">
                        <?= $this->csrfField() ?>
                        <input type="hidden" name="client" value="<?= (int) $c['id'] ?>"><input type="hidden" name="project" value="<?= (int) $p['id'] ?>">
                        <?php if ($until): ?><input type="hidden" name="until" value="<?= $this->e($until) ?>"><?php endif ?>
                        <button type="submit" class="qs-btn qs-btn-sm" data-confirm="<?= $this->e($this->t('confirm_project', ['project' => $p['title'], 'hours' => $p['hours']])) ?>">
                          <?= $this->icon('receipt-euro') ?> <?= $this->e($this->t('create')) ?>
                        </button>
                      </form>
                      <form method="post" action="<?= $this->e($urls['notBillable']) ?>">
                        <?= $this->csrfField() ?>
                        <input type="hidden" name="client" value="<?= (int) $c['id'] ?>"><input type="hidden" name="project" value="<?= (int) $p['id'] ?>">
                        <?php if ($until): ?><input type="hidden" name="until" value="<?= $this->e($until) ?>"><?php endif ?>
                        <button type="submit" class="qs-btn qs-btn-sm" title="<?= $this->e($this->t('not_billable')) ?>" aria-label="<?= $this->e($this->t('not_billable')) ?>"
                                data-confirm="<?= $this->e($this->t('confirm_not_billable', ['project' => $p['title'], 'hours' => $p['hours']])) ?>" data-confirm-variant="warning">
                          <?= $this->icon('ban') ?>
                        </button>
                      </form>
                    </div>
                  <?php endif ?>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endforeach ?>
<?php endif ?>
