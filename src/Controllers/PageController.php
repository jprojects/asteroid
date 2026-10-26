<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\HttpException;

/**
 * Despacha index.php?r=page&m={módulo}[&a={acción}] al controlador de la página:
 * sin "a" → index(); con "a=events" → actionEvents().
 */
final class PageController extends Controller
{
    public function dispatch(): void
    {
        $module = $this->module('view');
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
