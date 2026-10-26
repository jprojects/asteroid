<?php
declare(strict_types=1);

namespace Quasar\Controllers;

final class HomeController extends Controller
{
    public function index(): void
    {
        $this->render('home', [
            'title' => $this->t('home.title'),
            'subtitle' => $this->t('home.welcome', ['name' => $this->user()->name()]),
            'icon' => 'house',
            'breadcrumbs' => $this->crumbs(),
            'groups' => $this->app->modules()->menuFor($this->user()),
        ]);
    }
}
