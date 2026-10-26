<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\App;
use Quasar\Core\HttpException;
use Quasar\Core\Module;
use Quasar\Core\ModuleRegistry;
use Quasar\Core\Session;
use Quasar\Core\User;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    protected function user(): User
    {
        return $this->app->auth->user() ?? throw new HttpException(401);
    }

    protected function t(string $key, array $vars = []): string
    {
        return $this->app->lang->get($key, $vars);
    }

    /**
     * Módulo indicado en ?m= tras comprobar el permiso para la acción.
     * Las rutas de tabla (listado, ficha, datos…) no admiten páginas personalizadas y viceversa.
     */
    protected function module(string $action): Module
    {
        $name = $_GET['m'] ?? $_POST['m'] ?? '';
        $module = is_string($name) ? $this->app->modules()->get($name) : null;
        if ($module === null || $module->isPage() !== ($this instanceof PageController)) {
            throw new HttpException(404);
        }
        $this->authorize($module, $action);
        $this->app->view->setDomain($module->name);
        return $module;
    }

    protected function authorize(Module $module, string $action): void
    {
        if (!ModuleRegistry::canAccess($this->user(), $module, $action)) {
            throw new HttpException(403);
        }
    }

    protected function can(Module $module, string $action): bool
    {
        return ModuleRegistry::canAccess($this->user(), $module, $action);
    }

    protected function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpException(405);
        }
        if (!Session::checkCsrf($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
            throw new HttpException(419);
        }
    }

    /** Identificador de registro (GET id / POST _id); null si no viene. */
    protected function idParam(): ?string
    {
        $id = $_POST['_id'] ?? $_GET['id'] ?? null;
        if ($id === null || $id === '') {
            return null;
        }
        if (!is_string($id) || strlen($id) > 64) {
            throw new HttpException(400);
        }
        return $id;
    }

    /**
     * Ruta de navegación: Inicio › Grupo › Módulo › …extra.
     * Cada elemento es [texto, url|null]; el último se muestra como página actual.
     * @param list<array{0:string,1:?string}> $extra
     */
    protected function crumbs(?Module $module = null, array $extra = []): array
    {
        $crumbs = [[$this->t('home.title'), $this->app->url('home')]];
        if ($module !== null) {
            $crumbs[] = [$this->t('menu.' . $module->menuGroup()), null];
            $crumbs[] = [$module->title(), $module->url()];
        }
        return array_merge($crumbs, $extra);
    }

    protected function render(string $template, array $vars = [], ?string $layout = 'layout', int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->app->view->render($template, $vars, $layout);
    }
}
