<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Ai\LlmFactory;
use Quasar\Ai\Usage;
use Quasar\Core\DataQuery;
use Quasar\Core\FormHandler;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\Module;
use Quasar\Core\Session;
use Quasar\Core\ShowOn;

/**
 * Listado, datos (JSON), edición, guardado y borrado de cualquier módulo JSON.
 */
final class ModuleController extends Controller
{
    public function list(): void
    {
        $m = $this->module('view');
        $sort = $m->defaultSort();
        $columns = $m->listColumns();
        $ai = $this->aiBar($m);

        $config = [
            'module' => $m->name,
            'dataUrl' => $this->app->url('data', ['m' => $m->name]),
            'editUrl' => $this->app->url('edit', ['m' => $m->name]),
            'deleteUrl' => $this->app->url('delete', ['m' => $m->name]),
            'exportUrl' => $this->app->url('export', ['m' => $m->name]),
            'canEdit' => $this->can($m, 'edit'),
            'chat' => $m->chatAllows(),
            'canDelete' => $this->can($m, 'delete'),
            'columns' => array_map(static fn ($c) => array_filter([
                'field' => $c['field']->name,
                'align' => $c['align'],
                'overflow' => $c['overflow'],
                'lines' => $c['lines'],
                'minWidth' => $c['minWidth'],
                'maxWidth' => $c['maxWidth'],
            ], static fn ($v) => $v !== null), $columns),
            'expand' => array_map(static fn ($e) => ['field' => $e['field']->name, 'label' => $e['label']], $m->expandFields()),
            'rowActions' => array_map(fn ($b) => $this->buttonConfig($m, $b), $m->rowActions()),
            'sort' => $sort['field'],
            'dir' => $sort['dir'],
            'size' => $m->pageSize(),
            'filtersOpen' => $m->filtersOpen(),
            'ai' => $ai !== null ? ['url' => $ai['url']] : null,
            'i18n' => [
                'loading' => $this->t('table.loading'),
                'empty' => $this->t('table.empty'),
                'error' => $this->t('table.error'),
                'info' => $this->t('table.info'),
                'edit' => $this->t('actions.edit'),
                'view' => $this->t('actions.view'),
                'delete' => $this->t('actions.delete'),
                'confirmDelete' => $this->t('confirm.delete'),
                'deleteTitle' => $this->t('dialog.deleteTitle'),
                'deleted' => $this->t('flash.deleted'),
                'expand' => $this->t('table.expand'),
                'collapse' => $this->t('table.collapse'),
                'expandAll' => $this->t('table.expand_all'),
                'search' => $this->t('filter.search'),
                'removeFilter' => $this->t('filter.remove'),
                'aiNotUnderstood' => $this->t('ai.not_understood'),
                'collapseAll' => $this->t('table.collapse_all'),
                'total' => $this->t('table.total'),
                'chat' => $this->t('chat.messages_count'),
                'chatUnread' => $this->t('chat.unread_count'),
            ],
        ];

        $this->render($this->app->view->resolve("modules/{$m->name}/list", 'list'), [
            'title' => $m->title(),
            'icon' => $m->icon(),
            'subtitle' => $m->description(),
            'breadcrumbs' => $this->crumbs($m),
            'module' => $m,
            'columns' => $columns,
            'filters' => $m->filters(),
            'hasSearch' => (bool) $m->searchFields(),
            'pageSizes' => $m->pageSizes(),
            'canCreate' => $this->can($m, 'create'),
            'toolbarButtons' => array_map(fn ($b) => $this->buttonConfig($m, $b), $m->toolbarButtons()),
            'ai' => $ai,
            'exportFormats' => $this->can($m, 'export') ? ($m->exportConfig()['formats'] ?? []) : [],
            'config' => $config,
        ]);
    }

    public function data(): void
    {
        $m = $this->module('view');
        $result = (new DataQuery($m, $this->app->db))->run($_GET);
        if ($m->chatConfig() !== null) {
            // Mensajes y no leídos de la conversación de cada fila
            $counts = $this->app->chat()->counts($m, array_column($result['rows'], 'id'), $this->user());
            foreach ($result['rows'] as &$row) {
                if (isset($counts[$row['id']])) {
                    $row['chat'] = $counts[$row['id']];
                }
            }
            unset($row);
        }
        $this->app->json($result);
    }

    public function edit(): void
    {
        $m = $this->module('view');
        $id = $this->idParam();
        if ($id === null) {
            $this->authorize($m, 'create');
        }
        $form = new FormHandler($m, $this->app->db);
        $values = $id === null ? $this->prefill($m, $form->defaults()) : ($form->load($id) ?? throw new HttpException(404));
        $this->renderForm($m, $id, $values, []);
    }

    public function save(): void
    {
        $this->requirePost();
        $m = $this->module('view');
        $id = $this->idParam();
        $this->authorize($m, $id === null ? 'create' : 'edit');

        $form = new FormHandler($m, $this->app->db);
        if ($id !== null && $form->load($id) === null) {
            throw new HttpException(404);
        }
        $result = $form->process($_POST, $id, $this->user());
        if (!$result['ok']) {
            $this->renderForm($m, $id, $result['values'], $result['errors'], 422);
            return;
        }

        Log::info('{module}: registro {id} {action}', [
            'module' => $m->name,
            'id' => $result['id'],
            'action' => $id === null ? 'creado' : 'modificado',
        ]);
        if (!empty($_POST['_modal'])) {
            // Alta rápida en una ventana: se avisa a la página que la abrió (app.js) en lugar de redirigir
            $this->render('modal_done', [
                'done' => [
                    'module' => $m->name,
                    'id' => (string) $result['id'],
                    'message' => $this->t('flash.saved'),
                    'url' => $this->app->url('edit', ['m' => $m->name, 'id' => $result['id']]),
                ],
            ], 'blank');
            return;
        }
        Session::flash('success', $this->t('flash.saved'));
        $this->app->redirect(isset($_POST['_close'])
            ? $this->app->url('list', ['m' => $m->name])
            : $this->app->url('edit', ['m' => $m->name, 'id' => $result['id']]));
    }

    public function delete(): void
    {
        $this->requirePost();
        $m = $this->module('delete');
        $id = $this->idParam() ?? throw new HttpException(400);
        $user = $this->user();

        if (!$m->isVisible($id)) {
            throw new HttpException(404); // no existe o el usuario no puede verlo ("scope")
        }
        $hooks = $m->hooks($this->app->db);
        $error = $hooks?->beforeDelete($id, $user);
        if ($error === null) {
            try {
                if ($this->app->db->delete($m->table(), $m->primaryKey(), $id) === 0) {
                    throw new HttpException(404);
                }
                $hooks?->afterDelete($id, $user);
                Log::info('{module}: registro {id} eliminado', ['module' => $m->name, 'id' => $id]);
            } catch (\PDOException $e) {
                // 23000: violación de integridad (el registro está referenciado)
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $error = 'errors.in_use';
            }
        }

        if ($this->app->wantsJson()) {
            $error === null
                ? $this->app->json(['ok' => true])
                : $this->app->json(['error' => $m->t($error)], 409);
            return;
        }
        if ($error !== null) {
            Session::flash('error', $m->t($error));
            $this->app->redirect($this->app->url('edit', ['m' => $m->name, 'id' => $id]));
        }
        Session::flash('success', $this->t('flash.deleted'));
        $this->app->redirect($this->app->url('list', ['m' => $m->name]));
    }

    /**
     * Valores iniciales de un alta desde la URL: edit&m=…&v[campo]=valor (p. ej. la fecha
     * del día pulsado en un calendario). Cada valor pasa por su tipo de campo y solo se
     * acepta si es válido; el usuario lo ve y lo puede cambiar antes de guardar.
     */
    private function prefill(Module $m, array $values): array
    {
        $input = is_array($_GET['v'] ?? null) ? $_GET['v'] : [];
        foreach ($m->formFields() as $f) {
            if (!array_key_exists($f->name, $input) || $f->isReadonly()) {
                continue;
            }
            $value = $f->fromRequest($input);
            if ($f->validate($value, true) === []) {
                $values[$f->name] = $value;
            }
        }
        return $values;
    }

    /**
     * Barra de "filtrar con lenguaje natural" si la instalación tiene la IA y el módulo la usa.
     * Con el límite mensual agotado se muestra desactivada; al acercarse, avisa a los administradores.
     * @return array{url:string,blocked:bool,message:?string}|null
     */
    private function aiBar(Module $m): ?array
    {
        $config = (array) ($this->app->config['ai'] ?? []);
        if (!LlmFactory::enabled($config) || $m->aiConfig() === null) {
            return null;
        }
        $state = (new Usage($this->app->db, $config))->state();
        // Cuatro decimales por debajo de 10 céntimos, para no mostrar "0,00 €"
        $eur = fn (float $n) => number_format($n, $n > 0 && $n < 0.1 ? 4 : 2, $this->t('format.decimal_point'), $this->t('format.thousands_sep'));
        $message = match (true) {
            $state['state'] === 'blocked' => $this->t('ai.limit_reached'),
            $state['state'] === 'warn' && $this->user()->isAdmin() => $this->t('ai.limit_warning', [
                'percent' => (int) floor(($state['ratio'] ?? 0) * 100),
                'used' => $eur($state['used']),
                'limit' => $eur($state['limit']),
            ]),
            default => null,
        };
        return [
            'url' => $this->app->url('ask', ['m' => $m->name]),
            'blocked' => $state['state'] === 'blocked',
            'message' => $message,
        ];
    }

    /**
     * Lo que app.js necesita de un botón propio: enlace ("url", con {id} sustituido si se conoce el
     * registro) o acción ("run": URL que se llama por POST), y su confirmación.
     * @return array<string,mixed>
     */
    private function buttonConfig(Module $m, array $b, ?string $id = null): array
    {
        $out = ['label' => $b['label'], 'icon' => $b['icon'], 'variant' => $b['variant']];
        if ($b['url'] !== null) {
            $out['url'] = $id !== null ? str_replace('{id}', rawurlencode($id), $b['url']) : $b['url'];
        } else {
            $out['run'] = $this->app->url('action', ['m' => $m->name, 'a' => $b['action']]);
        }
        return $out + array_filter(['confirm' => $b['confirm'], 'download' => $b['download'], 'id' => $id]);
    }

    private function renderForm(Module $m, ?string $id, array $values, array $errors, int $status = 200): void
    {
        $isNew = $id === null;
        $readonly = !$isNew && !$this->can($m, 'edit');
        // Título: el propio registro ("Acme Ibérica S.L."), o "Nuevo registro" en un alta
        $heading = $isNew
            ? $this->t('page.new_record')
            : ($m->recordTitle($values) ?? $this->t('page.record', ['id' => $id]));
        $mode = $this->t($isNew ? 'page.creating' : ($readonly ? 'page.viewing' : 'page.editing'));

        // Alta rápida en una ventana (?modal=1, "quickCreate"): sin menú ni cabecera
        $modal = !empty($_GET['modal']) || !empty($_POST['_modal']);
        $this->render($this->app->view->resolve("modules/{$m->name}/form", 'form'), [
            'modal' => $modal,
            'title' => $heading . ' · ' . $m->title(),
            'heading' => $heading,
            'subtitle' => $m->title() . ' · ' . $mode . ($isNew ? '' : ' · #' . $id),
            'icon' => $m->icon(),
            'breadcrumbs' => $this->crumbs($m, [[$heading, null]]),
            'module' => $m,
            'id' => $id,
            'isNew' => $isNew,
            'readonly' => $readonly,
            'canDelete' => !$isNew && $this->can($m, 'delete'),
            // Botones propios: solo en registros guardados y si cumplen su "showon"
            'buttons' => $isNew ? [] : array_values(array_map(
                fn ($b) => $this->buttonConfig($m, $b, $id),
                array_filter($m->formButtons(), static fn ($b) => $b['showon'] === null
                    || ShowOn::evaluate($b['showon'], static fn (string $f) => $values[$f] ?? null))
            )),
            'sections' => $m->formSections(),
            'visibility' => ShowOn::visibility($m->formFields(), $values),
            'values' => $values,
            'errors' => $errors,
            'chat' => !$isNew && !$modal && $m->chatAllows() ? $this->chatConfig($m, (string) $id) : null,
        ], $modal ? 'blank' : 'layout', $status);
    }

    /** Lo que necesita el panel de conversación de la ficha (app.js). */
    private function chatConfig(Module $m, string $id): array
    {
        $cfg = $m->chatConfig();
        $url = fn (string $a) => $this->app->url('chat', ['a' => $a, 'm' => $m->name, 'id' => $id]);
        $google = $cfg['meet'] ? $this->app->google() : null;
        $user = $this->user();
        $keys = ['title', 'placeholder', 'send', 'attach', 'empty', 'older', 'today', 'yesterday', 'seen', 'deleted', 'delete',
            'confirm_delete', 'mute', 'unmute', 'muted_on', 'muted_off', 'meet', 'meet_confirm', 'meet_join', 'meet_started',
            'uploading', 'new_messages', 'mention_hint', 'enter_hint', 'error', 'removed_attachment'];
        return [
            'poll' => $url('poll'),
            'post' => $url('post'),
            'upload' => $cfg['attachments'] ? $url('upload') : null,
            'delete' => $url('delete'),
            'mute' => $url('mute'),
            'people' => $url('people'),
            'meet' => $google !== null && $google->connection() !== null ? $url('meet') : null,
            'me' => ['id' => $user->id(), 'name' => $user->name()],
            'seconds' => max(3, (int) ($this->app->config['chat']['poll_seconds'] ?? 5)),
            'i18n' => array_combine(
                array_map(static fn ($k) => lcfirst(str_replace('_', '', ucwords($k, '_'))), $keys),
                array_map(fn ($k) => $this->t('chat.' . $k), $keys)
            ),
        ];
    }
}
