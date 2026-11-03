<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\Log;
use Quasar\Core\ModuleRegistry;

final class HomeController extends Controller
{
    public function index(): void
    {
        // 'home' => 'tpv': ese módulo es la página de inicio (también después del login) para quien puede verlo;
        // index.php?r=home&menu=1 muestra el inicio de siempre
        $home = $this->app->config['home'] ?? null;
        if (is_string($home) && $home !== '' && !isset($_GET['menu'])) {
            $module = $this->app->modules()->get($home);
            if ($module === null) {
                Log::warning('config home: no existe el módulo {module}', ['module' => $home]);
            } elseif (ModuleRegistry::canAccess($this->user(), $module)) {
                $this->app->redirect($module->url());
            }
        }

        $this->render('home', [
            'title' => $this->t('home.title'),
            'subtitle' => $this->t('home.welcome', ['name' => $this->user()->name()]),
            'icon' => 'house',
            'breadcrumbs' => $this->crumbs(),
            'groups' => $this->app->modules()->menuFor($this->user()),
        ]);
    }
}
