<?php
declare(strict_types=1);

namespace Quasar\Core;

use Quasar\Mail\Mail;

/**
 * Verificación en dos pasos con un código enviado al email del usuario.
 *
 * config 'two_factor.mode':
 *   off       desactivada
 *   optional  la activa cada usuario en su perfil (o un administrador en Usuarios)
 *   required  obligatoria para todos los usuarios
 *
 * Protecciones: el código caduca (code_ttl), cada código admite max_attempts
 * intentos, los reenvíos están limitados (resend_wait, max_sends) y cada código
 * erróneo cuenta como intento fallido de login (bloqueo de cuenta incluido).
 * Lo pendiente vive en la sesión del servidor; el código solo se guarda como hash.
 */
final class TwoFactor
{
    private const SESSION = '_2fa';
    private const COOKIE = 'qs_trusted';
    /** Tiempo máximo para completar el segundo paso desde que se validó la contraseña */
    private const PENDING_TTL = 900;

    public function __construct(
        private readonly Database $db,
        private readonly Lang $lang,
        private readonly array $config,
        private readonly string $appName,
    ) {
    }

    public function mode(): string
    {
        $mode = $this->config['mode'] ?? 'off';
        return in_array($mode, ['off', 'optional', 'required'], true) ? $mode : 'off';
    }

    public function appliesTo(array $row): bool
    {
        return match ($this->mode()) {
            'required' => true,
            'optional' => (bool) ($row['two_factor'] ?? false),
            default => false,
        };
    }

    public function rememberDays(): int
    {
        return max(0, (int) ($this->config['remember_days'] ?? 30));
    }

    private function codeTtl(): int
    {
        return max(60, (int) ($this->config['code_ttl'] ?? 600));
    }

    // ---------------------------------------------------------------- flujo

    /**
     * Genera y envía un código. Deja el login "pendiente" en la sesión.
     * @return string|null clave de idioma de error
     */
    public function start(array $row): ?string
    {
        if (trim((string) ($row['email'] ?? '')) === '') {
            Log::warning('2FA: el usuario {username} no tiene email; no puede iniciar sesión', ['username' => $row['username']]);
            return 'two_factor.no_email';
        }
        Session::regenerate();
        Session::set(self::SESSION, [
            'user' => (int) $row['id'],
            'started' => time(),
            'sends' => 0,
        ]);
        return $this->sendNewCode($row);
    }

    /** Login pendiente de verificar (null si no hay o ha caducado). */
    public function pending(): ?array
    {
        $p = Session::get(self::SESSION);
        if (!is_array($p) || time() - (int) ($p['started'] ?? 0) > self::PENDING_TTL) {
            Session::remove(self::SESSION);
            return null;
        }
        return $p;
    }

    public function pendingUser(): ?array
    {
        $p = $this->pending();
        return $p ? $this->db->fetch('SELECT * FROM users WHERE id = ? AND active = 1', [$p['user']]) : null;
    }

    /**
     * @return string 'ok' | clave de idioma del error
     */
    public function verify(string $code, Auth $auth): string
    {
        $p = $this->pending();
        $row = $this->pendingUser();
        if ($p === null || $row === null) {
            $this->clear();
            return 'two_factor.expired';
        }
        if ($auth->isLocked($row)) {
            $this->clear();
            return 'login.locked';
        }
        if (time() > (int) ($p['expires'] ?? 0)) {
            return 'two_factor.code_expired';
        }

        $code = (string) preg_replace('/\D/', '', $code);
        if ($code !== '' && hash_equals((string) $p['hash'], self::hashCode($code, (string) $p['salt']))) {
            $this->clear();
            return 'ok';
        }

        $p['attempts'] = (int) ($p['attempts'] ?? 0) + 1;
        Session::set(self::SESSION, $p);
        Log::warning('2FA: código incorrecto para {username} (intento {n})', ['username' => $row['username'], 'n' => $p['attempts']]);
        if ($auth->registerFailure($row) === 'login.locked') {
            $this->clear();
            return 'login.locked';
        }
        if ($p['attempts'] >= max(1, (int) ($this->config['max_attempts'] ?? 5))) {
            // Código agotado: hay que pedir otro
            $p['expires'] = 0;
            Session::set(self::SESSION, $p);
            return 'two_factor.too_many';
        }
        return 'two_factor.invalid';
    }

    /** @return string|null clave de error */
    public function resend(): ?string
    {
        $p = $this->pending();
        $row = $this->pendingUser();
        if ($p === null || $row === null) {
            return 'two_factor.expired';
        }
        $wait = (int) ($this->config['resend_wait'] ?? 60) - (time() - (int) ($p['last_sent'] ?? 0));
        if ($wait > 0) {
            return 'two_factor.wait';
        }
        if ((int) $p['sends'] >= max(1, (int) ($this->config['max_sends'] ?? 3))) {
            return 'two_factor.max_sends';
        }
        return $this->sendNewCode($row);
    }

    public function secondsToResend(): int
    {
        $p = $this->pending();
        return $p ? max(0, (int) ($this->config['resend_wait'] ?? 60) - (time() - (int) ($p['last_sent'] ?? 0))) : 0;
    }

    public function clear(): void
    {
        Session::remove(self::SESSION);
    }

    private function sendNewCode(array $row): ?string
    {
        $length = min(10, max(4, (int) ($this->config['code_length'] ?? 6)));
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        $salt = bin2hex(random_bytes(16));

        $p = Session::get(self::SESSION);
        $p['hash'] = self::hashCode($code, $salt);
        $p['salt'] = $salt;
        $p['expires'] = time() + $this->codeTtl();
        $p['attempts'] = 0;
        $p['sends'] = (int) ($p['sends'] ?? 0) + 1;
        $p['last_sent'] = time();
        Session::set(self::SESSION, $p);

        $minutes = (int) ceil($this->codeTtl() / 60);
        // El correo va en el idioma del usuario, no en el de la pantalla de login
        $previousLocale = $this->lang->locale();
        $this->lang->setLocale((string) ($row['locale'] ?? $previousLocale));
        try {
            $message = $this->buildMessage($row, $code, $minutes);
        } finally {
            $this->lang->setLocale($previousLocale);
        }

        if (!Mail::trySend($message)) {
            return 'two_factor.send_failed';
        }
        Log::info('2FA: código enviado a {username}', ['username' => $row['username'], 'envio' => $p['sends']]);
        return null;
    }

    private function buildMessage(array $row, string $code, int $minutes): \Quasar\Mail\Message
    {
        return Mail::compose('emails/two_factor', [
            'name' => (string) ($row['name'] ?: $row['username']),
            'code' => $code,
            'minutes' => $minutes,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'preheader' => $this->lang->get('two_factor.mail_preheader', ['code' => $code, 'minutes' => $minutes]),
        ])
            ->to((string) $row['email'], (string) $row['name'])
            ->subject($this->lang->get('two_factor.mail_subject', ['app' => $this->appName]));
    }

    private static function hashCode(string $code, string $salt): string
    {
        return hash_hmac('sha256', $code, $salt);
    }

    // ---------------------------------------------------------------- dispositivos de confianza

    public function isTrusted(array $row): bool
    {
        $token = $_COOKIE[self::COOKIE] ?? '';
        if ($this->rememberDays() === 0 || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM user_trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > ?',
            [(int) $row['id'], hash('sha256', $token), date('Y-m-d H:i:s')]
        );
    }

    public function trustDevice(int $userId): void
    {
        $days = $this->rememberDays();
        if ($days === 0) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $expires = time() + $days * 86400;
        $this->db->query('DELETE FROM user_trusted_devices WHERE user_id = ? AND expires_at < ?', [$userId, date('Y-m-d H:i:s')]);
        $this->db->insert('user_trusted_devices', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'expires_at' => date('Y-m-d H:i:s', $expires),
        ]);
        setcookie(self::COOKIE, $token, [
            'expires' => $expires,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function trustedCount(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM user_trusted_devices WHERE user_id = ? AND expires_at > ?',
            [$userId, date('Y-m-d H:i:s')]
        );
    }

    /** Revoca todos los dispositivos de confianza (cambio de contraseña o de email, o a petición). */
    public function forgetDevices(int $userId): void
    {
        $this->db->query('DELETE FROM user_trusted_devices WHERE user_id = ?', [$userId]);
    }

    /** "ana.perez@cliente.es" -> "an•••••@cliente.es" */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));
        return $visible . str_repeat('•', max(3, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
    }
}
