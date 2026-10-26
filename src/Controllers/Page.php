<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\App;
use Quasar\Core\HttpException;
use Quasar\Core\Module;
use Quasar\Core\ModuleRegistry;
use Quasar\Core\Session;

/**
 * Base de las páginas personalizadas (módulos con "type": "page").
 *
 *   modules/agenda.json
 *     { "type": "page", "controller": "Quasar\\Pages\\AgendaPage", "menu": { "icon": "calendar-days" } }
 *
 *   src/Pages/AgendaPage.php
 *     final class AgendaPage extends Page {
 *         public function index(): void { $this->view(['items' => …]); }       // index.php?r=page&m=agenda
 *         public function actionEvents(): void { $this->json([…]); }          // …&a=events
 *     }
 *
 *   templates/default/pages/agenda.php   (plantilla; helpers de siempre: $this->t(), icon(), url()…)
 *
 * El acceso a la página exige el permiso "view" del módulo (o los que declare en "actions",
 * que se comprueban con $this->allowed('accion')). Textos en lang/{idioma}/agenda.json.
 */
abstract class Page extends Controller
{
    public function __construct(App $app, protected readonly Module $module)
    {
        parent::__construct($app);
    }

    /** Acción por defecto. */
    abstract public function index(): void;

    /**
     * Pinta la plantilla de la página (por defecto pages/{módulo}) dentro del layout,
     * con título, icono y ruta de navegación ya rellenos (se pueden sobrescribir en $vars).
     */
    protected function view(array $vars = [], ?string $template = null, int $status = 200): void
    {
        $m = $this->module;
        $this->render($template ?? 'pages/' . $m->name, $vars + [
            'title' => $m->title(),
            'icon' => $m->icon(),
            'subtitle' => $m->description(),
            'breadcrumbs' => $this->crumbs(null, [
                [$this->t('menu.' . $m->menuGroup()), null],
                [$m->title(), null],
            ]),
            'module' => $m,
        ], 'layout', $status);
    }

    protected function json(mixed $data, int $status = 200): void
    {
        $this->app->json($data, $status);
    }

    /** URL de una acción de esta página. Sin escapar (en plantillas usa $this->e()). */
    protected function pageUrl(string $action = 'index', array $params = []): string
    {
        return $this->module->url(($action === 'index' ? [] : ['a' => $action]) + $params);
    }

    /** ¿Tiene el usuario este permiso en esta página (o en otro módulo, si se indica)? */
    protected function allowed(string $action, ?Module $module = null): bool
    {
        return ModuleRegistry::canAccess($this->user(), $module ?? $this->module, $action);
    }

    /** Otro módulo por nombre (p. ej. el que alimenta un calendario); 404 si no existe. */
    protected function otherModule(string $name): Module
    {
        return $this->app->modules()->get($name) ?? throw new HttpException(404);
    }

    protected function param(string $name, ?string $default = null): ?string
    {
        $v = $_GET[$name] ?? $_POST[$name] ?? $default;
        return is_string($v) ? $v : $default;
    }

    protected function flash(string $type, string $message): void
    {
        Session::flash($type, $message);
    }

    /** Texto del fichero de idioma de la página (lang/{idioma}/{módulo}.json), con common de respaldo. */
    protected function text(string $key, array $vars = []): string
    {
        return $this->module->t($key, $vars);
    }
}
