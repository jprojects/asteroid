<?php
declare(strict_types=1);

namespace Quasar\Core;

use Quasar\Mail\Mail;

/**
 * Recuperar la contraseña desde el login: enlace de un solo uso enviado al email del usuario.
 *
 * También lo envía un administrador desde la ficha del usuario, o como invitación al darlo de alta (el usuario
 * elige su contraseña): PasswordReset::service()->sendLink($row, 'invite' | 'reset', $nombreDelAdmin).
 *
 * config 'password_reset': enabled, ttl (segundos de validez del enlace pedido desde el login), invite_ttl (de
 * los que envía un administrador), max_per_hour (solicitudes por usuario y hora desde el login), min_length.
 * Necesita 'mail' configurado.
 *
 * Protecciones: la respuesta es la misma exista o no el usuario (y el correo se envía después de
 * responder, para no delatarlo por el tiempo); del enlace solo se guarda el hash (tabla password_resets);
 * caduca, sirve una vez y pedir otro anula el anterior. Al cambiar la contraseña se desbloquea la cuenta,
 * se revocan los dispositivos de confianza (2FA) y se avisa al usuario por correo. No inicia sesión.
 */
final class PasswordReset
{
    private static ?\Closure $factory = null;

    /** La registra App: así los hooks (p. ej. los de usuarios) pueden enviar enlaces. */
    public static function setFactory(\Closure $factory): void
    {
        self::$factory = $factory;
    }

    public static function service(): self
    {
        return (self::$factory ?? throw new \LogicException('PasswordReset sin inicializar'))();
    }

    public function __construct(
        private readonly Database $db,
        private readonly Lang $lang,
        private readonly array $config,
        private readonly string $appName,
        /** @var \Closure(string):string ruta relativa → URL completa */
        private readonly \Closure $absoluteUrl,
    ) {
    }

    public function enabled(): bool
    {
        return ($this->config['enabled'] ?? true) !== false;
    }

    /** Texto en el idioma de la pantalla (para los avisos de los hooks de usuarios). */
    public function text(string $key, array $vars = []): string
    {
        return $this->lang->get($key, $vars);
    }

    public function minLength(): int
    {
        return max(8, (int) ($this->config['min_length'] ?? 8));
    }

    private function ttl(): int
    {
        return max(300, (int) ($this->config['ttl'] ?? 3600));
    }

    private function inviteTtl(): int
    {
        return max(3600, (int) ($this->config['invite_ttl'] ?? 7 * 86400));
    }

    /**
     * Prepara el enlace para el usuario de $login (usuario o email, según 'login_field').
     * @return \Closure():void|null envío del correo (se llama después de responder), o null si no hay a quién enviarlo
     */
    public function request(string $login, string $loginField): ?\Closure
    {
        $row = $this->findUser($login, $loginField);
        if ($row === null) {
            Log::notice('Recuperar contraseña: no hay ningún usuario activo con email para "{login}"', ['login' => $login]);
            return null;
        }
        $recent = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?',
            [(int) $row['id'], date('Y-m-d H:i:s', time() - 3600)]
        );
        if ($recent >= max(1, (int) ($this->config['max_per_hour'] ?? 3))) {
            Log::warning('Recuperar contraseña: demasiadas solicitudes para {username}', ['username' => $row['username']]);
            return null;
        }

        $url = $this->createLink($row, $this->ttl());
        Log::info('Recuperar contraseña: enlace para {username}', ['username' => $row['username']]);
        return function () use ($row, $url): void {
            $this->send($row, 'emails/password_reset', 'reset.mail_subject', $this->linkVars($url, $this->ttl(), 'self', ''));
        };
    }

    /**
     * Enlace que envía un administrador: 'invite' (usuario nuevo o que aún no ha entrado: elige su contraseña)
     * o 'reset' (cambiarla). Caduca en 'invite_ttl'. @return bool si se ha podido enviar el correo
     */
    public function sendLink(array $row, string $kind, string $byName): bool
    {
        if (trim((string) ($row['email'] ?? '')) === '' || !(int) ($row['active'] ?? 0)) {
            return false;
        }
        $url = $this->createLink($row, $this->inviteTtl());
        Log::info('Recuperar contraseña: {kind} enviada a {username} por {by}', ['kind' => $kind, 'username' => $row['username'], 'by' => $byName]);
        return $this->send($row, 'emails/password_reset', $kind === 'invite' ? 'reset.invite_subject' : 'reset.mail_subject',
            $this->linkVars($url, $this->inviteTtl(), $kind, $byName));
    }

    /** Guarda el hash de un enlace nuevo (anula los anteriores) y devuelve su URL. */
    private function createLink(array $row, int $ttl): string
    {
        $token = bin2hex(random_bytes(32));
        // Pedir otro enlace anula los anteriores; se guardan las filas usadas para contar las solicitudes
        $this->db->query('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [date('Y-m-d H:i:s'), (int) $row['id']]);
        $this->db->query('DELETE FROM password_resets WHERE created_at < ? AND (used_at IS NOT NULL OR expires_at < ?)', [date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s')]);
        $this->db->insert('password_resets', [
            'user_id' => (int) $row['id'],
            'token_hash' => hash('sha256', $token),
            'ip' => mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);
        return ($this->absoluteUrl)('index.php?' . http_build_query(['r' => 'reset', 'token' => $token]));
    }

    /** Variables del correo del enlace. $kind: self (pedido desde el login), reset o invite (de un administrador). */
    private function linkVars(string $url, int $ttl, string $kind, string $byName): array
    {
        $expires = $ttl >= 2 * 86400
            ? $this->lang->get('reset.mail_expires_days', ['days' => intdiv($ttl, 86400)])
            : $this->lang->get('reset.mail_expires', ['minutes' => (int) ceil($ttl / 60)]);
        return [
            'url' => $url,
            'kind' => $kind,
            'by' => $byName,
            'expires' => $expires,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'preheader' => $expires,
        ];
    }

    /** Usuario de un enlace válido (sin usar, sin caducar, usuario activo), o null. */
    public function userForToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return $this->db->fetch(
            'SELECT u.* FROM password_resets r JOIN users u ON u.id = r.user_id
             WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > ? AND u.active = 1',
            [hash('sha256', $token), date('Y-m-d H:i:s')]
        );
    }

    /** Cambia la contraseña con un enlace válido. @return bool false si el enlace ya no sirve */
    public function complete(string $token, string $password, TwoFactor $twoFactor): bool
    {
        $row = $this->userForToken($token);
        if ($row === null) {
            return false;
        }
        $this->db->transaction(function () use ($row, $password): void {
            $this->db->update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'failed_logins' => 0,
                'locked_until' => null,
            ], 'id', $row['id']);
            $this->db->query('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [date('Y-m-d H:i:s'), (int) $row['id']]);
        });
        $twoFactor->forgetDevices((int) $row['id']);
        Log::info('Recuperar contraseña: {username} ha cambiado la contraseña', ['username' => $row['username']]);
        // Aviso de seguridad, salvo al aceptar una invitación (aún no había entrado nunca)
        if ($row['last_login'] !== null) {
            $this->send($row, 'emails/password_changed', 'reset.changed_subject', [
                'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'preheader' => $this->lang->get('reset.changed_preheader'),
            ]);
        }
        return true;
    }

    /**
     * Usuario activo con email por nombre o por email (como en el login). Un email que tienen varios
     * usuarios no sirve: no se sabría a cuál cambiar la contraseña.
     */
    private function findUser(string $login, string $loginField): ?array
    {
        if ($loginField !== 'email') {
            $row = $this->db->fetch('SELECT * FROM users WHERE username = ?', [$login]);
            if ($row !== null) {
                return (int) $row['active'] && trim((string) $row['email']) !== '' ? $row : null;
            }
        }
        if ($loginField === 'username' || !str_contains($login, '@')) {
            return null;
        }
        $rows = $this->db->fetchAll('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 2', [$login]);
        return count($rows) === 1 ? $rows[0] : null;
    }

    /** Correo al usuario, en su idioma (no en el de la pantalla). @return bool si se ha enviado */
    private function send(array $row, string $template, string $subjectKey, array $vars): bool
    {
        $previous = $this->lang->locale();
        $this->lang->setLocale((string) ($row['locale'] ?? $previous));
        try {
            if (isset($vars['kind'])) {
                // Los textos de caducidad se calculan en el idioma del usuario
                $vars = $this->linkVars($vars['url'], $vars['kind'] === 'self' ? $this->ttl() : $this->inviteTtl(), $vars['kind'], $vars['by']);
            }
            $message = Mail::compose($template, ['name' => (string) ($row['name'] ?: $row['username']), 'username' => (string) $row['username']] + $vars)
                ->to((string) $row['email'], (string) $row['name'])
                ->subject($this->lang->get($subjectKey, ['app' => $this->appName]));
        } finally {
            $this->lang->setLocale($previous);
        }
        if (!Mail::trySend($message)) {
            Log::error('Recuperar contraseña: no se ha podido enviar el correo a {username}', ['username' => $row['username']]);
            return false;
        }
        return true;
    }
}
