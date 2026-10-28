<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Autenticación en dos pasos posibles:
 *   1. verifyCredentials(): usuario y contraseña (con bloqueo por intentos fallidos)
 *   2. completeLogin(): inicia la sesión
 * Entre ambos puede intercalarse la verificación en dos pasos (TwoFactor).
 */
final class Auth
{
    private ?User $user = null;
    private bool $resolved = false;

    public function __construct(
        private readonly Database $db,
        private readonly array $config,
    ) {
    }

    /** Usuario de la sesión actual, recargado de BD en cada petición (permisos siempre frescos). */
    public function user(): ?User
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $id = Session::get('user_id');
        if (!is_int($id)) {
            return null;
        }
        $ttl = (int) ($this->config['session_lifetime'] ?? 0);
        if ($ttl > 0 && time() - (int) Session::get('last_activity', 0) > $ttl) {
            $this->logout();
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);
        if ($row === null) {
            $this->logout();
            return null;
        }
        Session::set('last_activity', time());
        return $this->user = $this->build($row);
    }

    /**
     * Otro usuario activo con sus permisos y grupos (p. ej. para saber si puede ver un registro
     * antes de avisarle), o null si no existe o está desactivado.
     */
    public function userById(int $id): ?User
    {
        $row = $this->db->fetch('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);
        return $row !== null ? $this->build($row) : null;
    }

    private function build(array $row): User
    {
        $permissions = new Permissions($this->db);
        $groups = $permissions->groupsOf($row['groups'] ?? null);
        return new User($row, $permissions->forUser((int) $row['id'], array_keys($groups)), array_values($groups));
    }

    /** Fuerza recargar el usuario (p. ej. tras editar el perfil). */
    public function refresh(): void
    {
        $this->resolved = false;
        $this->user = null;
    }

    /** Con qué se identifica el usuario en el login: username | email | both (config 'login_field'). */
    public function loginField(): string
    {
        $field = $this->config['login_field'] ?? 'username';
        return in_array($field, ['username', 'email', 'both'], true) ? $field : 'username';
    }

    /**
     * Comprueba usuario (o email, según 'login_field') y contraseña SIN iniciar sesión.
     * @return array{error:?string,row:?array} error = clave de idioma
     */
    public function verifyCredentials(string $login, string $password): array
    {
        $row = $this->findForLogin($login);
        if ($row === null) {
            // Igualamos el tiempo de respuesta para no revelar qué usuarios existen
            password_verify($password, password_hash('quasar-dummy', PASSWORD_DEFAULT));
            return ['error' => 'login.invalid', 'row' => null];
        }
        if ($this->isLocked($row)) {
            return ['error' => 'login.locked', 'row' => null];
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            return ['error' => $this->registerFailure($row), 'row' => null];
        }
        if (!(int) $row['active']) {
            return ['error' => 'login.inactive', 'row' => null];
        }
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id', $row['id']);
        }
        return ['error' => null, 'row' => $row];
    }

    /** Usuario por nombre o por email. Un email repetido en varios usuarios no sirve para entrar. */
    private function findForLogin(string $login): ?array
    {
        $field = $this->loginField();
        if ($field !== 'email' && ($row = $this->db->fetch('SELECT * FROM users WHERE username = ?', [$login])) !== null) {
            return $row;
        }
        if ($field === 'username' || !str_contains($login, '@')) {
            return null;
        }
        $rows = $this->db->fetchAll('SELECT * FROM users WHERE email = ? LIMIT 2', [$login]);
        if (count($rows) > 1) {
            Log::warning('Login con el email {email}: lo tienen varios usuarios', ['email' => $login]);
            return null;
        }
        return $rows[0] ?? null;
    }

    /**
     * Suma un intento fallido (contraseña o código 2FA) y bloquea la cuenta al llegar al máximo.
     * @return string clave de idioma: login.invalid o login.locked
     */
    public function registerFailure(array $row): string
    {
        $fails = (int) $this->db->fetchValue('SELECT failed_logins FROM users WHERE id = ?', [$row['id']]) + 1;
        $lockedUntil = null;
        if ($fails >= (int) ($this->config['login_max_attempts'] ?? 5)) {
            $lockedUntil = date('Y-m-d H:i:s', time() + (int) ($this->config['login_lockout'] ?? 300));
            $fails = 0;
            Log::warning('Cuenta {username} bloqueada temporalmente por intentos fallidos', ['username' => $row['username']]);
        }
        $this->db->update('users', ['failed_logins' => $fails, 'locked_until' => $lockedUntil], 'id', $row['id']);
        return $lockedUntil ? 'login.locked' : 'login.invalid';
    }

    public function isLocked(array $row): bool
    {
        $until = $this->db->fetchValue('SELECT locked_until FROM users WHERE id = ?', [$row['id']]);
        return $until !== null && strtotime((string) $until) > time();
    }

    /** Inicia la sesión del usuario (credenciales y, en su caso, 2FA ya verificados). */
    public function completeLogin(array $row): void
    {
        $this->db->update('users', ['failed_logins' => 0, 'locked_until' => null, 'last_login' => date('Y-m-d H:i:s')], 'id', $row['id']);
        Session::regenerate();
        Session::set('user_id', (int) $row['id']);
        Session::set('last_activity', time());
        $this->refresh();
    }

    /** Login en un paso (sin 2FA). @return string|null clave de error */
    public function attempt(string $username, string $password): ?string
    {
        $result = $this->verifyCredentials($username, $password);
        if ($result['error'] === null) {
            $this->completeLogin($result['row']);
        }
        return $result['error'];
    }

    public function logout(): void
    {
        $this->user = null;
        $this->resolved = true;
        Session::reset();
    }
}
