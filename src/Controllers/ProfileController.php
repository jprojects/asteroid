<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\Log;
use Quasar\Core\Session;
use Quasar\Fields\Field;
use Quasar\Fields\FieldFactory;

/**
 * Perfil del usuario conectado: datos personales, idioma, contraseña y
 * verificación en dos pasos. Para cambiar email, contraseña o desactivar
 * el 2FA se exige la contraseña actual.
 */
final class ProfileController extends Controller
{
    public function index(): void
    {
        $this->app->view->setDomain('profile');
        $user = $this->user();
        $row = $this->app->db->fetch('SELECT * FROM users WHERE id = ?', [$user->id()]) ?? throw new \RuntimeException('Usuario no encontrado');
        $twoFactor = $this->app->twoFactor();
        $fields = $this->fields();

        $values = [
            'name' => $row['name'],
            'email' => $row['email'],
            'locale' => $row['locale'],
            'notify_email' => (string) ($row['notify_email'] ?? 'instant'),
            'two_factor' => (int) $row['two_factor'],
        ];
        $errors = [];

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->requirePost();
            if (isset($_POST['_forget'])) {
                $twoFactor->forgetDevices($user->id());
                Log::info('Perfil: dispositivos de confianza olvidados');
                Session::flash('success', $this->t('profile:flash.devices_forgotten'));
                $this->app->redirect($this->app->url('profile'));
            }
            [$values, $errors] = $this->save($row, $fields, $values);
        }

        $this->render('profile', [
            'title' => $this->t('profile:title'),
            'subtitle' => $row['name'] . ' · ' . $row['username'],
            'icon' => 'circle-user-round',
            'breadcrumbs' => $this->crumbs(null, [[$this->t('profile:title'), null]]),
            'sections' => $this->sections($fields),
            'values' => $values,
            'errors' => $errors,
            'mode' => $twoFactor->mode(),
            'twoFactorActive' => $twoFactor->appliesTo($row),
            'trustedDevices' => $twoFactor->trustedCount($user->id()),
            'lastLogin' => $row['last_login'],
            'username' => $row['username'],
        ], 'layout', $errors ? 422 : 200);
    }

    /** @return array{0:array,1:array<string,string>} valores y errores (si no hay errores, redirige) */
    private function save(array $row, array $fields, array $values): array
    {
        $errors = [];
        $twoFactor = $this->app->twoFactor();
        foreach (['name', 'email', 'locale', 'notify_email', 'two_factor', 'current_password', 'new_password', 'new_password_confirm'] as $name) {
            if (!isset($fields[$name])) {
                continue;
            }
            $values[$name] = $fields[$name]->fromRequest($_POST);
            if ($e = $fields[$name]->validate($values[$name], false)) {
                $errors[$name] = $e[0];
            }
        }

        $email = (string) $values['email'];
        $emailChanged = $email !== (string) $row['email'];
        $passwordChanged = ($values['new_password'] ?? '') !== '';
        $disabling2fa = $twoFactor->mode() === 'optional' && (int) $row['two_factor'] === 1 && (int) $values['two_factor'] === 0;

        if (!isset($errors['email']) && $email !== '' && (int) $this->app->db->fetchValue(
            'SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?',
            [$email, $row['id']]
        ) > 0) {
            $errors['email'] = $this->t('validation.unique');
        }
        $will2fa = $twoFactor->appliesTo(['two_factor' => $values['two_factor']] + $row);
        if (!isset($errors['email']) && $email === '' && $will2fa) {
            $errors['email'] = $this->t('profile:errors.email_2fa');
        }
        if ($passwordChanged && ($values['new_password'] ?? '') !== ($values['new_password_confirm'] ?? '')) {
            $errors['new_password_confirm'] = $this->t('profile:errors.mismatch');
        }
        if ($emailChanged || $passwordChanged || $disabling2fa) {
            $current = (string) ($values['current_password'] ?? '');
            if ($current === '') {
                $errors['current_password'] = $this->t('profile:errors.current_required');
            } elseif (!password_verify($current, (string) $row['password_hash'])) {
                // Cuenta como intento fallido para que no sirva para adivinar la contraseña
                $this->app->auth->registerFailure($row);
                Log::warning('Perfil: contraseña actual incorrecta');
                $errors['current_password'] = $this->t('profile:errors.current_wrong');
            }
        }

        // Nunca se devuelven contraseñas al formulario
        unset($values['current_password'], $values['new_password'], $values['new_password_confirm']);
        if ($errors) {
            return [$values, $errors];
        }

        $data = [
            'name' => $values['name'],
            'email' => $email !== '' ? $email : null,
            'locale' => $values['locale'],
            'notify_email' => $values['notify_email'] === 'off' ? 'off' : 'instant',
        ];
        if ($twoFactor->mode() === 'optional') {
            $data['two_factor'] = (int) $values['two_factor'];
        }
        if ($passwordChanged) {
            $data['password_hash'] = password_hash((string) $_POST['new_password'], PASSWORD_DEFAULT);
        }
        $this->app->db->update('users', $data, 'id', $row['id']);

        $changed = array_keys(array_filter(
            ['name' => $data['name'] !== $row['name'], 'email' => $emailChanged, 'locale' => $data['locale'] !== $row['locale'],
             'two_factor' => isset($data['two_factor']) && $data['two_factor'] !== (int) $row['two_factor'], 'password' => $passwordChanged]
        ));
        if ($passwordChanged || $emailChanged) {
            // Los dispositivos de confianza dejan de serlo y se renueva la sesión
            $twoFactor->forgetDevices((int) $row['id']);
            Session::regenerate();
        }
        Log::info('Perfil actualizado', ['cambios' => $changed]);
        $this->app->auth->refresh();
        $this->app->lang->setLocale((string) $data['locale']);
        Session::flash('success', $this->t('profile:flash.saved'));
        $this->app->redirect($this->app->url('profile'));
    }

    /** @return array<string,Field> */
    private function fields(): array
    {
        $ctx = $this->app->fieldContextFactory()('profile');
        $locales = (array) ($this->app->config['locales'] ?? ['es' => 'Español']);
        $defs = [
            'name' => ['type' => 'text', 'required' => true, 'maxlength' => 100, 'width' => 'half', 'autocomplete' => 'name'],
            'email' => ['type' => 'email', 'maxlength' => 150, 'width' => 'half', 'autocomplete' => 'email'],
            'locale' => ['type' => 'select', 'required' => true, 'options' => $locales, 'width' => 'quarter'],
            'notify_email' => ['type' => 'select', 'required' => true, 'width' => 'half', 'help' => 'help.notify_email',
                               'options' => ['instant' => 'notify.instant', 'off' => 'notify.off']],
            'two_factor' => ['type' => 'checkbox', 'help' => 'help.two_factor'],
            'current_password' => ['type' => 'password', 'minlength' => 1, 'width' => 'third', 'help' => 'help.current_password'],
            'new_password' => ['type' => 'password', 'minlength' => 8, 'width' => 'third'],
            'new_password_confirm' => ['type' => 'password', 'minlength' => 1, 'width' => 'third'],
        ];
        if ($this->app->twoFactor()->mode() !== 'optional') {
            unset($defs['two_factor']);
        }
        $fields = [];
        foreach ($defs as $name => $def) {
            $fields[$name] = FieldFactory::make($name, $def, $ctx);
        }
        return $fields;
    }

    private function sections(array $fields): array
    {
        $pick = static fn (array $names) => array_values(array_intersect_key($fields, array_flip($names)));
        return [
            'personal' => $pick(['name', 'email', 'locale', 'notify_email']),
            'security' => $pick(['two_factor']),
            'password' => $pick(['current_password', 'new_password', 'new_password_confirm']),
        ];
    }
}
