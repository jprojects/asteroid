<?php
/**
 * Asteroid · página "wiki" (Quasar\Pages\WikiPage): lectura de la documentación de los proyectos.
 * Las URL llegan sin escapar. El cuerpo de la página es HTML ya limpio (richtext, HtmlSanitizer al guardar).
 * Los estilos van aquí y no en style.css, que es del núcleo.
 *
 * @var \Quasar\Core\View $this
 * @var list<array> $projects
 * @var ?array $project
 * @var array<string,list<array>> $nav      sección => páginas
 * @var ?array $current
 * @var string $q
 * @var ?list<array> $results
 * @var bool $isClient
 * @var string $searchAction
 * @var ?string $newUrl
 * @var ?string $editUrl
 * @var ?string $manageUrl
 * @var string $homeUrl
 */
$dtFmt = $this->t('format.datetime');
?>
<style>
.ast-wiki { display: grid; grid-template-columns: 270px minmax(0, 1fr); gap: 16px; align-items: start; flex-shrink: 0; max-width: 1200px; }
.ast-wiki-side, .ast-wiki-main { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); min-width: 0; }
.ast-wiki-side { position: sticky; top: 12px; max-height: calc(100vh - 140px); overflow-y: auto; padding: 10px 0; }
.ast-wiki-side h3 { margin: 12px 14px 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); }
.ast-wiki-side ul { list-style: none; margin: 0; padding: 0; }
.ast-wiki-side a { display: flex; gap: 8px; align-items: center; padding: 6px 14px; color: var(--text); text-decoration: none; font-size: 13.5px; border-left: 3px solid transparent; }
.ast-wiki-side a:hover { background: var(--row-hover); }
.ast-wiki-side a.is-active { background: var(--primary-soft); border-left-color: var(--primary); font-weight: 600; }
.ast-wiki-side a .qs-icon { width: 15px; height: 15px; flex-shrink: 0; color: var(--text-muted); }
.ast-wiki-side small { color: var(--text-muted); margin-left: auto; font-size: 11.5px; }
.ast-wiki-search { display: flex; gap: 6px; padding: 0 12px 6px; }
.ast-wiki-main { padding: 22px 28px 28px; }
.ast-wiki-head { display: flex; gap: 10px; align-items: flex-start; flex-wrap: wrap; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border); }
.ast-wiki-head h1 { margin: 0; font-size: 22px; flex: 1; min-width: 200px; }
.ast-wiki-meta { width: 100%; color: var(--text-muted); font-size: 12.5px; }
.ast-wiki-body { line-height: 1.65; font-size: 14.5px; overflow-wrap: anywhere; }
.ast-wiki-body h1, .ast-wiki-body h2, .ast-wiki-body h3 { margin: 1.4em 0 .5em; line-height: 1.3; }
.ast-wiki-body h2 { font-size: 18px; } .ast-wiki-body h3 { font-size: 16px; }
.ast-wiki-body p { margin: 0 0 .9em; }
.ast-wiki-body img { max-width: 100%; height: auto; border-radius: 6px; border: 1px solid var(--border); }
.ast-wiki-body pre, .ast-wiki-body code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; background: var(--surface-alt); border-radius: 4px; }
.ast-wiki-body code { padding: 1px 5px; } .ast-wiki-body pre { padding: 10px 12px; overflow-x: auto; }
.ast-wiki-body table { border-collapse: collapse; width: 100%; margin: 0 0 1em; }
.ast-wiki-body th, .ast-wiki-body td { border: 1px solid var(--border); padding: 6px 8px; text-align: left; }
.ast-wiki-body blockquote { margin: 0 0 1em; padding: 6px 12px; border-left: 3px solid var(--primary); background: var(--primary-soft); }
.ast-wiki-empty { padding: 30px 10px; text-align: center; color: var(--text-muted); }
.ast-wiki-results { list-style: none; margin: 0; padding: 0; }
.ast-wiki-results li + li { border-top: 1px solid var(--border); }
.ast-wiki-results a { display: block; padding: 10px 0; color: var(--text); text-decoration: none; }
.ast-wiki-results a:hover strong { color: var(--primary); }
.ast-wiki-results small { display: block; color: var(--text-muted); }
@media (max-width: 820px) { .ast-wiki { grid-template-columns: 1fr; } .ast-wiki-side { position: static; max-height: none; } .ast-wiki-main { padding: 16px; } }
</style>

<?php if ($newUrl || $manageUrl): ?>
  <div class="qs-toolbar">
    <?php if ($newUrl): ?><a class="qs-btn qs-btn-primary" href="<?= $this->e($newUrl) ?>"><?= $this->icon('plus') ?> <?= $this->e($this->t('new_page')) ?></a><?php endif ?>
    <?php if ($manageUrl): ?><a class="qs-btn" href="<?= $this->e($manageUrl) ?>"><?= $this->icon('file-text') ?> <?= $this->e($this->t('manage')) ?></a><?php endif ?>
  </div>
<?php endif ?>

<div class="ast-wiki">
  <nav class="ast-wiki-side" aria-label="<?= $this->e($this->t('title')) ?>">
    <form class="ast-wiki-search" method="get" action="index.php" role="search">
      <input type="hidden" name="r" value="page"><input type="hidden" name="m" value="wiki">
      <?php if ($project): ?><input type="hidden" name="project" value="<?= (int) $project['id'] ?>"><?php endif ?>
      <input class="qs-input" type="search" name="q" value="<?= $this->e($q) ?>" placeholder="<?= $this->e($this->t('search')) ?>" aria-label="<?= $this->e($this->t('search')) ?>">
    </form>

    <?php if ($project && $nav): ?>
      <?php foreach ($nav as $section => $items): ?>
        <h3><?= $this->e($section !== '' ? $section : $this->t('general')) ?></h3>
        <ul>
          <?php foreach ($items as $i): ?>
            <li><a href="<?= $this->e($i['url']) ?>"<?= $current && (int) $current['id'] === (int) $i['id'] ? ' class="is-active" aria-current="page"' : '' ?>>
              <?= $this->icon($i['audience'] === 'team' ? 'eye-off' : 'file-text', '', $i['audience'] === 'team' ? $this->t('audience.team') : null) ?>
              <span><?= $this->e((string) $i['title']) ?></span>
            </a></li>
          <?php endforeach ?>
        </ul>
      <?php endforeach ?>
    <?php endif ?>

    <?php if (count($projects) > 1 || !$project): ?>
      <h3><?= $this->e($this->t('projects')) ?></h3>
      <ul>
        <?php foreach ($projects as $p): ?>
          <li><a href="<?= $this->e($p['url']) ?>"<?= $project && (int) $project['id'] === (int) $p['id'] ? ' class="is-active"' : '' ?>>
            <?= $this->icon('book-open') ?>
            <span><?= $this->e((string) $p['title']) ?><?php if (!$isClient && $p['client']): ?><br><small style="margin: 0"><?= $this->e((string) $p['client']) ?></small><?php endif ?></span>
            <small><?= (int) $p['pages'] ?></small>
          </a></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </nav>

  <article class="ast-wiki-main">
    <?php if ($results !== null): ?>
      <div class="ast-wiki-head"><h1><?= $this->e($this->t('results', ['q' => $q])) ?></h1></div>
      <?php if ($results): ?>
        <ul class="ast-wiki-results">
          <?php foreach ($results as $r): ?>
            <li><a href="<?= $this->e($r['url']) ?>"><strong><?= $this->e($r['title']) ?></strong>
              <small><?= $this->e($r['project']) ?></small><small><?= $this->e($r['excerpt']) ?></small></a></li>
          <?php endforeach ?>
        </ul>
      <?php else: ?>
        <div class="ast-wiki-empty"><?= $this->e($this->t('no_results')) ?></div>
      <?php endif ?>
    <?php elseif ($current): ?>
      <div class="ast-wiki-head">
        <h1><?= $this->e((string) $current['title']) ?></h1>
        <?php if (!$isClient): ?>
          <span class="qs-tag qs-tone-<?= $current['audience'] === 'client' ? 'teal' : 'purple' ?>"><?= $this->e($this->t('audience.' . ($current['audience'] === 'client' ? 'client' : 'team'))) ?></span>
        <?php endif ?>
        <?php if ($editUrl): ?><a class="qs-btn qs-btn-sm" href="<?= $this->e($editUrl) ?>"><?= $this->icon('pencil') ?> <?= $this->e($this->t('edit')) ?></a><?php endif ?>
        <div class="ast-wiki-meta">
          <?= $this->e((string) ($project['title'] ?? '')) ?>
          <?php $at = $current['updated_at'] ?? $current['created_at']; ?>
          · <?= $this->e($this->t('updated', ['date' => date($dtFmt, (int) strtotime((string) $at))])) ?><?= !$isClient && $current['author'] ? ' · ' . $this->e((string) $current['author']) : '' ?>
        </div>
      </div>
      <div class="ast-wiki-body"><?= (string) $current['body'] ?></div>
    <?php elseif ($project): ?>
      <div class="ast-wiki-empty"><?= $this->e($this->t($isClient ? 'empty_client' : 'empty_project')) ?></div>
    <?php else: ?>
      <div class="ast-wiki-empty"><?= $this->e($this->t($projects ? 'choose' : ($isClient ? 'empty_client' : 'empty_team'))) ?></div>
    <?php endif ?>
  </article>
</div>
