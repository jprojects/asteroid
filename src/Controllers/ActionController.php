<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\Action;
use Quasar\Core\ActionResult;
use Quasar\Core\FormHandler;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\Session;
use Quasar\Core\ShowOn;

/**
 * Acciones propias de un módulo (botones con "action" en el JSON):
 *   POST index.php?r=action&m={módulo}&a={acción}   (_id = registro, en filas y fichas)
 *
 * Desde app.js (fetch) responde JSON: {ok, message?, type?, redirect?, reload?} o {error} con 422.
 * Los botones con "download" envían un formulario normal: la respuesta es el fichero o, si la
 * acción no devuelve una descarga, un aviso y la vuelta a la página de origen (_back).
 */
final class ActionController extends Controller
{
    public function run(): void
    {
        $this->requirePost();
        $m = $this->module('view');
        $name = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';
        $button = $m->action($name) ?? throw new HttpException(404);

        $id = null;
        $row = null;
        if ($button['place'] !== 'toolbar') {
            $id = $this->idParam() ?? throw new HttpException(400);
            $row = (new FormHandler($m, $this->app->db))->load($id) ?? throw new HttpException(404);
            // El botón no se ve en este registro: tampoco se puede ejecutar
            if ($button['showon'] !== null && !ShowOn::evaluate($button['showon'], static fn (string $f) => $row[$f] ?? null)) {
                throw new HttpException(403);
            }
        }

        $hooks = $m->hooks($this->app->db) ?? throw new HttpException(404);
        $action = new Action($name, $m, $this->user(), $this->app->db, $id, $row,
            fn (string $route, array $params) => $this->app->url($route, $params), $this->app->config);
        $result = ActionResult::from($hooks->{$name}($action));

        Log::info('{module}: acción {action}{on}', [
            'module' => $m->name,
            'action' => $name,
            'on' => $id !== null ? " en el registro $id" : '',
            'result' => $result->kind,
        ]);
        $this->app->wantsJson() ? $this->respondJson($result, $button['place']) : $this->respondPage($result);
    }

    private function respondJson(ActionResult $r, string $place): void
    {
        if ($r->kind === 'error') {
            $this->app->json(['error' => $r->message], 422);
            return;
        }
        if ($r->kind === 'download') {
            throw new \RuntimeException('La acción devuelve una descarga: añade "download": true a su botón en el JSON del módulo');
        }
        if ($r->kind === 'redirect' || $place === 'form') {
            // Se cambia de página (o se recarga la ficha): el aviso se muestra al llegar
            if ($r->message !== null) {
                Session::flash('success', $r->message);
            }
            $this->app->json(['ok' => true] + ($r->kind === 'redirect' ? ['redirect' => $r->url] : ['reload' => true]));
            return;
        }
        $this->app->json(['ok' => true] + ($r->message !== null ? ['message' => $r->message, 'type' => 'success'] : []));
    }

    private function respondPage(ActionResult $r): void
    {
        if ($r->kind === 'download') {
            $this->send($r);
            return;
        }
        if ($r->message !== null) {
            Session::flash($r->kind === 'error' ? 'error' : 'success', $r->message);
        }
        $this->app->redirect($r->kind === 'redirect' ? (string) $r->url : $this->back());
    }

    /** Página de origen (solo rutas internas) o la portada. */
    private function back(): string
    {
        $back = $_POST['_back'] ?? '';
        return is_string($back) && preg_match('/^index\.php\?[^\s\\\\]*$/', $back) ? $back : $this->app->url('home');
    }

    private function send(ActionResult $r): void
    {
        $name = str_replace(['"', "\r", "\n"], '', (string) $r->filename);
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'download';
        header('Content-Type: ' . $r->mime);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . ($r->file !== null ? (string) filesize($r->file) : (string) strlen((string) $r->data)));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $r->file !== null ? readfile($r->file) : print($r->data);
    }
}
