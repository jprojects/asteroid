<?php
declare(strict_types=1);

namespace Quasar\Core;

final class Session
{
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name($config['session_name'] ?? 'QUASARSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Nuevo identificador de sesión (contra fijación de sesión). */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Vacía la sesión y la reemplaza por una nueva vacía. */
    public static function reset(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function checkCsrf(mixed $token): bool
    {
        return is_string($token) && hash_equals(self::csrfToken(), $token);
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][] = [$type, $message];
    }

    /** @return list<array{0:string,1:string}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }
}
