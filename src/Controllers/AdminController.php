<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\Module;
use Quasar\Core\Permissions;
use Quasar\Core\Session;

/**
 * Gestión de permisos de usuarios y de grupos (solo administradores).
 * La gestión de usuarios y grupos en sí son módulos JSON normales (modules/users.json, modules/groups.json).
 */
final class AdminController extends Controller
{
    /** index.php?r=permissions&id={usuario}  o  index.php?r=permissions&group={grupo} */
    public function permissions(): void
    {
        if (!$this->user()->isAdmin()) {
            throw new HttpException(403);
        }
        $this->app->view->setDomain('admin');

        $subject = isset($_GET['group']) ? 'group' : 'user';
        $id = $subject === 'group' ? $_GET['group'] : ($_GET['id'] ?? null);
        if (!is_string($id) || !ctype_digit($id)) {
            throw new HttpException(400);
        }
        $target = $subject === 'group'
            ? $this->app->db->fetch('SELECT id, code, name FROM user_groups WHERE id = ?', [(int) $id])
            : $this->app->db->fetch('SELECT id, username, name, is_admin, `groups` FROM users WHERE id = ?', [(int) $id]);
        if ($target === null) {
            throw new HttpException(404);
        }
        $paramName = $subject === 'group' ? 'group' : 'id';

        /** @var array<string,Module> $modules */
        $modules = array_filter($this->app->modules()->all(), static fn (Module $m) => !$m->isAdminOnly());
        $permissions = new Permissions($this->app->db);

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->requirePost();
            $allowed = array_map(static fn (Module $m) => $m->actions(), $modules);
            $matrix = is_array($_POST['perm'] ?? null) ? $_POST['perm'] : [];
            $permissions->save($subject, (int) $target['id'], $matrix, $allowed);
            Log::info($subject === 'group' ? 'Permisos modificados del grupo {target}' : 'Permisos modificados del usuario {target}', [
                'target' => $target[$subject === 'group' ? 'code' : 'username'],
                'permisos' => $permissions->own($subject, (int) $target['id']),
            ]);
            Session::flash('success', $this->t('flash.permissions_saved'));
            $this->app->redirect($this->app->url('permissions', [$paramName => $target['id']]));
        }

        // Acciones de todos los módulos; las propias de un módulo se traducen con su fichero de idioma
        $actions = [];
        foreach ($modules as $m) {
            foreach ($m->actions() as $a) {
                $actions[$a] ??= $this->app->lang->has('perm.' . $a) ? $this->t('perm.' . $a) : $m->t('perm.' . $a);
            }
        }

        $listModule = $this->app->modules()->get($subject === 'group' ? 'groups' : 'users');
        $name = $target['name'] ?: ($target['username'] ?? '');
        $title = $this->app->lang->get($subject === 'group' ? 'permissions.title_group' : 'permissions.title', ['user' => $name, 'group' => $name], 'admin');
        $this->render('permissions', [
            'title' => $title,
            'icon' => 'key-round',
            'subtitle' => $subject === 'group'
                ? $target['code']
                : $target['username'] . ((int) $target['is_admin'] ? ' · ' . $this->app->lang->get('fields.is_admin', [], 'users') : ''),
            'breadcrumbs' => $this->crumbs($listModule, [[$title, null]]),
            'subject' => $subject,
            'formUrl' => $this->app->url('permissions', [$paramName => $target['id']]),
            'backUrl' => $this->app->url('list', ['m' => $subject === 'group' ? 'groups' : 'users']),
            'isAdmin' => $subject === 'user' && (int) $target['is_admin'],
            'modules' => $modules,
            'actions' => $actions,
            'granted' => $permissions->own($subject, (int) $target['id']),
            // Lo que el usuario ya tiene por sus grupos (se marca en la tabla)
            'inherited' => $subject === 'user' ? $permissions->fromGroups(Permissions::groupIds($target['groups'])) : [],
        ]);
    }
}
