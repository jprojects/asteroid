<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\HttpException;
use Quasar\Core\Module;

/**
 * Despacha index.php?r=page&m={módulo}[&a={acción}] al controlador de la página:
 * sin "a" → index(); con "a=events" → actionEvents().
 * Las páginas con "public": true se abren sin iniciar sesión en index.php?r=public&m={módulo}.
 */
final class PageController extends Controller
{
    public function dispatch(): void
    {
        // Acciones abiertas a cualquier usuario con sesión (Page::USER_ACTIONS): la página comprueba lo suyo
        $name = $_GET['m'] ?? '';
        $candidate = is_string($name) ? $this->app->modules()->get($name) : null;
        $class = $candidate !== null && $candidate->isPage() ? $candidate->pageController() : null;
        if ($class !== null && in_array((string) ($_GET['a'] ?? 'index'), $class::USER_ACTIONS, true)) {
            $this->user();
            $this->app->view->setDomain($candidate->name);
            $this->run($candidate);
            return;
        }
        $module = $this->module('view');
        if ($module->isPublic()) {
            $this->app->redirect($module->url($_GET['a'] ?? null ? ['a' => (string) $_GET['a']] : []));
        }
        $this->run($module);
    }

    /** Página pública: sin login ni permisos; la propia página decide quién puede usarla (p. ej. con un token). */
    public function dispatchPublic(): void
    {
        $name = $_GET['m'] ?? '';
        $module = is_string($name) ? $this->app->modules()->get($name) : null;
        if ($module === null || !$module->isPage() || !$module->isPublic()) {
            throw new HttpException(404);
        }
        $this->app->view->setDomain($module->name);
        $this->run($module);
    }

    private function run(Module $module): void
    {
        $class = $module->pageController() ?? throw new HttpException(404);

        $action = $this->param('a') ?? 'index';
        if (!preg_match('/^[a-z][a-zA-Z0-9]{0,40}$/', $action)) {
            throw new HttpException(404);
        }
        $method = $action === 'index' ? 'index' : 'action' . ucfirst($action);

        $page = new $class($this->app, $module);
        if (!method_exists($page, $method) || !(new \ReflectionMethod($page, $method))->isPublic()) {
            throw new HttpException(404);
        }
        $page->$method();
    }

    private function param(string $name): ?string
    {
        $v = $_GET[$name] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }
}
