<?php
declare(strict_types=1);

namespace Quasar\Webchat;

use Quasar\Core\Database;

/**
 * Asteroid · chat web para las webs de los clientes. Cada cliente (con el servicio activado por AFI en su ficha,
 * clients.webchat) configura su widget en «El meu xat web» (webchat_sites) y pega en su web:
 *
 *   <script src="https://…/index.php?r=public&m=webchat_widget&a=loader&k=CLAVE" async></script>
 *
 * El script pinta un botón y abre un iframe servido por Asteroid (WebchatWidgetPage); los mensajes de los visitantes
 * entran en la conversación (módulo "webchat") con ChatService::postExternal() y los usuarios del cliente responden
 * desde Asteroid. El visitante se identifica con un token aleatorio en el localStorage del iframe (aquí, su SHA-256).
 */
final class Webchat
{
    public const COLOR = '#2563eb';
    /** Mensajes de un visitante en 10 minutos y conversaciones nuevas por IP en una hora */
    public const MAX_MESSAGES = 30;
    public const MAX_NEW_PER_IP = 6;
    public const MAX_BODY = 2000;

    /** Configuración activa de un cliente por su clave pública (servicio activado, cliente activo y chat activo). */
    public static function siteByKey(Database $db, string $key): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $key)) {
            return null;
        }
        return $db->fetch(
            'SELECT s.*, c.name AS client_name FROM webchat_sites s JOIN clients c ON c.id = s.client_id
              WHERE s.public_key = ? AND s.active = 1 AND c.webchat = 1 AND c.active = 1',
            [$key]
        );
    }

    /** Configuración de un cliente (la crea vacía, desactivada, si aún no tiene). */
    public static function siteForClient(Database $db, int $clientId): array
    {
        $site = $db->fetch('SELECT * FROM webchat_sites WHERE client_id = ?', [$clientId]);
        if ($site !== null) {
            return $site;
        }
        $name = (string) $db->fetchValue('SELECT name FROM clients WHERE id = ?', [$clientId]);
        $db->insert('webchat_sites', [
            'client_id' => $clientId,
            'public_key' => bin2hex(random_bytes(12)),
            'name' => mb_substr($name, 0, 80),
            'color' => self::COLOR,
        ]);
        return $db->fetch('SELECT * FROM webchat_sites WHERE client_id = ?', [$clientId]) ?? [];
    }

    /** @return list<string> dominios permitidos ("acme.com", "www.acme.com"); vacío = cualquiera */
    public static function domains(array $site): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', (string) ($site['domains'] ?? '')) ?: [] as $d) {
            $d = strtolower(trim((string) preg_replace('#^[a-z]+://#i', '', $d), " /\t"));
            $d = (string) preg_replace('#/.*$#', '', $d);
            if ($d !== '' && preg_match('/^(\*\.)?[a-z0-9.-]+(:\d+)?$/', $d)) {
                $out[] = $d;
            }
        }
        return array_values(array_unique($out));
    }

    /** Valor de frame-ancestors: solo las webs del cliente pueden incrustar el chat. */
    public static function frameAncestors(array $site): string
    {
        $domains = self::domains($site);
        if (!$domains) {
            return 'https: http:';
        }
        return implode(' ', array_merge(...array_map(static fn ($d) => ["https://$d", "http://$d"], $domains)));
    }

    /** ¿La página donde está el chat (su Origin o Referer) es de un dominio permitido? */
    public static function originAllowed(array $site, ?string $url): bool
    {
        $domains = self::domains($site);
        if (!$domains) {
            return true;
        }
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }
        foreach ($domains as $d) {
            $d = (string) preg_replace('/:\d+$/', '', $d);
            if ($host === $d || (str_starts_with($d, '*.') && str_ends_with($host, substr($d, 1)))) {
                return true;
            }
        }
        return false;
    }

    public static function visitorHash(array $site, string $token): ?string
    {
        return preg_match('/^[a-f0-9]{32,64}$/', $token) ? hash('sha256', $site['id'] . '|' . $token) : null;
    }

    /**
     * La página del visitante llega como «dominio/ruta», sin esquema (el WAF del servidor bloquea peticiones con URL
     * en los parámetros); aquí se completa con https:// para guardarla y comprobar el dominio.
     */
    public static function pageUrl(string $page): string
    {
        $page = trim(mb_substr($page, 0, 500));
        if ($page === '' || preg_match('#^https?://#i', $page)) {
            return $page;
        }
        return preg_match('#^[a-z0-9.-]+(:\d+)?(/|$)#i', $page) ? 'https://' . $page : '';
    }

    public static function ipHash(): string
    {
        return hash('sha256', 'webchat|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public static function validColor(string $color): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    }

    /** @return list<int> usuarios activos del cliente: los que reciben los avisos de los visitantes */
    public static function agents(Database $db, int $clientId): array
    {
        return array_map('intval', array_column($db->fetchAll('SELECT id FROM users WHERE client_id = ? AND active = 1', [$clientId]), 'id'));
    }
}
