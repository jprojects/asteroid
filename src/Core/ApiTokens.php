<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Tokens personales de la API (tabla api_tokens). El token ("qs_" + 40 caracteres) solo se devuelve al
 * crearlo; en BD se guarda su SHA-256. Quien lo tiene actúa como su usuario, con sus mismos permisos.
 */
final class ApiTokens
{
    public const PREFIX = 'qs_';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param ?int $days caducidad en días (null o 0 = no caduca)
     * @return array{token:string,id:int,expires_at:?string}
     */
    public function create(int $userId, string $name, ?int $days = null): array
    {
        $token = self::PREFIX . bin2hex(random_bytes(20));
        $expires = $days ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
        $id = (int) $this->db->insert('api_tokens', [
            'user_id' => $userId,
            'name' => mb_substr(trim($name), 0, 100),
            'token_hash' => hash('sha256', $token),
            'hint' => substr($token, -4),
            'expires_at' => $expires,
        ]);
        return ['token' => $token, 'id' => $id, 'expires_at' => $expires];
    }

    /**
     * Usuario activo y token vigente a partir del token en claro; anota el último uso.
     * @return array{user:array,token:array}|null
     */
    public function authenticate(string $token): ?array
    {
        if (!str_starts_with($token, self::PREFIX) || strlen($token) !== strlen(self::PREFIX) + 40) {
            return null;
        }
        $t = $this->db->fetch(
            'SELECT * FROM api_tokens WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > NOW())',
            [hash('sha256', $token)]
        );
        if ($t === null) {
            return null;
        }
        $user = $this->db->fetch('SELECT * FROM users WHERE id = ? AND active = 1', [$t['user_id']]);
        if ($user === null) {
            return null;
        }
        // Una escritura por minuto como mucho: un bot puede hacer muchas peticiones seguidas
        if ($t['last_used_at'] === null || strtotime((string) $t['last_used_at']) < time() - 60) {
            $this->db->update('api_tokens', [
                'last_used_at' => date('Y-m-d H:i:s'),
                'last_ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ], 'id', $t['id']);
        }
        return ['user' => $user, 'token' => $t];
    }

    /** @return list<array<string,mixed>> tokens del usuario, sin el hash */
    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, hint, expires_at, last_used_at, last_ip, created_at FROM api_tokens WHERE user_id = ? ORDER BY id DESC',
            [$userId]
        );
    }

    public function revoke(int $userId, int $id): bool
    {
        return (int) $this->db->query('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [$id, $userId])->rowCount() > 0;
    }

    public function revokeAll(int $userId): int
    {
        return (int) $this->db->query('DELETE FROM api_tokens WHERE user_id = ?', [$userId])->rowCount();
    }
}
