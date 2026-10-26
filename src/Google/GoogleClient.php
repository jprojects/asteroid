<?php
declare(strict_types=1);

namespace Quasar\Google;

use Quasar\Core\Log;
use Quasar\Core\Settings;

/**
 * Conexión de la instalación con una cuenta de Google (OAuth 2.0) y creación de salas de Google Meet
 * con la Meet REST API. Sin librerías: HTTP con cURL.
 *
 *   'google' => [
 *       'client_id' => '….apps.googleusercontent.com',
 *       'client_secret' => '…',
 *       'meet_access' => 'OPEN',   // OPEN: entra cualquiera con el enlace · TRUSTED: los de la organización
 *                                  // y los invitados · RESTRICTED: solo invitados · null: lo que diga la cuenta
 *   ],
 *
 * Un administrador conecta la cuenta una sola vez (Administración → Integraciones). El token de refresco
 * se guarda cifrado en la tabla settings con una clave derivada de client_secret: una copia de la BD sola
 * no sirve para usarlo.
 */
final class GoogleClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const MEET_URL = 'https://meet.googleapis.com/v2/spaces';
    private const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/meetings.space.created'];
    private const ACCESS_TYPES = ['OPEN', 'TRUSTED', 'RESTRICTED'];

    public function __construct(
        private readonly array $config,
        private readonly Settings $settings,
        private readonly string $redirectUri,
    ) {
    }

    public static function configured(array $config): bool
    {
        return !empty($config['client_id']) && !empty($config['client_secret']);
    }

    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /** URL de la pantalla de consentimiento de Google. */
    public function authUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',            // así Google devuelve siempre el token de refresco
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Cambia el código de la vuelta de Google por los tokens y guarda la conexión.
     * @return array{email:string,connected_at:string}
     */
    public function connect(string $code): array
    {
        $token = $this->request('POST', self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        if (empty($token['refresh_token'])) {
            throw new GoogleException('Google no ha devuelto un token de refresco: quita el acceso de la aplicación en tu cuenta de Google y vuelve a conectar');
        }
        $scopes = explode(' ', (string) ($token['scope'] ?? ''));
        if (!in_array('https://www.googleapis.com/auth/meetings.space.created', $scopes, true)) {
            throw new GoogleException('No se ha concedido el permiso para crear reuniones de Google Meet');
        }
        $info = $this->request('GET', self::USERINFO_URL, null, (string) $token['access_token']);
        $connection = [
            'email' => (string) ($info['email'] ?? ''),
            'connected_at' => date('c'),
        ];
        $this->settings->set('google.oauth', $connection + ['refresh_token' => $this->encrypt((string) $token['refresh_token'])]);
        $this->cacheToken($token);
        Log::info('Google: cuenta {email} conectada', ['email' => $connection['email']]);
        return $connection;
    }

    /** @return array{email:string,connected_at:string}|null */
    public function connection(): ?array
    {
        $c = $this->settings->get('google.oauth');
        return is_array($c) && !empty($c['refresh_token'])
            ? ['email' => (string) ($c['email'] ?? ''), 'connected_at' => (string) ($c['connected_at'] ?? '')]
            : null;
    }

    public function disconnect(): void
    {
        $c = $this->settings->get('google.oauth');
        if (is_array($c) && !empty($c['refresh_token'])) {
            try {
                $this->request('POST', self::REVOKE_URL, ['token' => $this->decrypt((string) $c['refresh_token'])]);
            } catch (GoogleException $e) {
                Log::notice('Google: no se ha podido revocar el token al desconectar', ['exception' => $e]);
            }
        }
        $this->settings->delete('google.oauth');
        $this->settings->delete('google.token');
        Log::info('Google: cuenta desconectada');
    }

    /**
     * Crea una sala de Google Meet.
     * @return array{uri:string,code:?string}
     */
    public function createMeeting(): array
    {
        $access = $this->config['meet_access'] ?? 'OPEN';
        $body = in_array($access, self::ACCESS_TYPES, true) ? ['config' => ['accessType' => $access]] : new \stdClass();
        try {
            $space = $this->request('POST', self::MEET_URL, $body, $this->accessToken());
        } catch (GoogleException $e) {
            if ($e->status !== 400 || $body instanceof \stdClass) {
                throw $e;
            }
            // Algunas cuentas (Gmail personal) no admiten cambiar el acceso: sala con la configuración por defecto
            $space = $this->request('POST', self::MEET_URL, new \stdClass(), $this->accessToken());
        }
        if (empty($space['meetingUri'])) {
            throw new GoogleException('Google Meet no ha devuelto el enlace de la reunión');
        }
        return ['uri' => (string) $space['meetingUri'], 'code' => isset($space['meetingCode']) ? (string) $space['meetingCode'] : null];
    }

    // ---------------------------------------------------------------- tokens

    private function accessToken(): string
    {
        $cached = $this->settings->get('google.token');
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60 && !empty($cached['token'])) {
            return $this->decrypt((string) $cached['token']);
        }
        $c = $this->settings->get('google.oauth');
        if (!is_array($c) || empty($c['refresh_token'])) {
            throw new GoogleException('La instalación no está conectada con Google');
        }
        $token = $this->request('POST', self::TOKEN_URL, [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $this->decrypt((string) $c['refresh_token']),
            'grant_type' => 'refresh_token',
        ]);
        $this->cacheToken($token);
        return (string) $token['access_token'];
    }

    private function cacheToken(array $token): void
    {
        if (!empty($token['access_token'])) {
            $this->settings->set('google.token', [
                'token' => $this->encrypt((string) $token['access_token']),
                'expires' => time() + (int) ($token['expires_in'] ?? 3600),
            ]);
        }
    }

    private function key(): string
    {
        return hash('sha256', 'quasar-google|' . $this->config['client_secret'], true);
    }

    private function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('No se ha podido cifrar el token de Google');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    private function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        $plain = $raw !== false && strlen($raw) > 28
            ? openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16))
            : false;
        if ($plain === false) {
            throw new GoogleException('El token guardado de Google no es válido (¿ha cambiado client_secret?): vuelve a conectar la cuenta');
        }
        return $plain;
    }

    // ---------------------------------------------------------------- HTTP

    /** @param array|\stdClass|null $body array = formulario (OAuth) o JSON (APIs con token) */
    private function request(string $method, string $url, array|\stdClass|null $body, ?string $bearer = null): array
    {
        $headers = ['Accept: application/json'];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        if ($body !== null) {
            if ($bearer !== null) {
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query((array) $body));
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false) {
            throw new GoogleException("No se ha podido conectar con Google: $error");
        }
        $data = json_decode((string) $response, true);
        if ($status >= 400 || !is_array($data)) {
            $message = is_array($data)
                ? (string) ($data['error']['message'] ?? $data['error_description'] ?? $data['error'] ?? "HTTP $status")
                : "HTTP $status";
            Log::warning('Google: {method} {url} → {status}: {message}', ['method' => $method, 'url' => $url, 'status' => $status, 'message' => $message]);
            throw new GoogleException("Google: $message", $status);
        }
        return $data;
    }
}
