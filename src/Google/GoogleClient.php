<?php
declare(strict_types=1);

namespace Quasar\Google;

use Quasar\Core\Log;
use Quasar\Core\Settings;

/**
 * Conexión de la instalación con una cuenta de Google (OAuth 2.0), creación de salas de Google Meet con la Meet
 * REST API y, opcionalmente, lectura de calendarios (Google Calendar API, solo lectura). Sin librerías: cURL.
 *
 *   'google' => [
 *       'client_id' => '….apps.googleusercontent.com',
 *       'client_secret' => '…',
 *       'meet_access' => 'OPEN',   // OPEN: entra cualquiera con el enlace · TRUSTED: los de la organización
 *                                  // y los invitados · RESTRICTED: solo invitados · null: lo que diga la cuenta
 *       'calendar' => true,        // opcional: permiso de lectura de Google Calendar (calendarEvents()); o una lista
 *                                  // de grupos (['equip']): solo ellos (y los administradores) conectan el suyo
 *   ],
 *
 * Un administrador conecta la cuenta una sola vez (Administración → Integraciones). El token de refresco
 * se guarda cifrado en la tabla settings con una clave derivada de client_secret: una copia de la BD sola
 * no sirve para usarlo.
 *
 * Con 'calendar', además, cada usuario puede conectar **su propio** Google Calendar desde su perfil (solo lectura;
 * connectUser(), userConnection(), calendarEvents(…, $userId)): se lee con su token, sin compartir calendarios.
 */
final class GoogleClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const MEET_URL = 'https://meet.googleapis.com/v2/spaces';
    private const CALENDAR_URL = 'https://www.googleapis.com/calendar/v3/calendars/';
    private const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/meetings.space.created'];
    public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.readonly';
    private const CALENDAR_PAGES = 5;
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

    /** URL de la pantalla de consentimiento de Google ($forUser: la conexión personal de un usuario, solo calendario). */
    public function authUrl(string $state, bool $forUser = false): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $forUser ? ['openid', 'email', self::CALENDAR_SCOPE] : $this->scopes()),
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
            'scopes' => array_values(array_filter($scopes)),
        ];
        $this->settings->set('google.oauth', $connection + ['refresh_token' => $this->encrypt((string) $token['refresh_token'])]);
        $this->cacheToken($token);
        Log::info('Google: cuenta {email} conectada', ['email' => $connection['email']]);
        return $connection;
    }

    /** @return array{email:string,connected_at:string,calendar:bool}|null */
    public function connection(): ?array
    {
        $c = $this->settings->get('google.oauth');
        return is_array($c) && !empty($c['refresh_token'])
            ? ['email' => (string) ($c['email'] ?? ''), 'connected_at' => (string) ($c['connected_at'] ?? ''),
               'calendar' => in_array(self::CALENDAR_SCOPE, (array) ($c['scopes'] ?? []), true)]
            : null;
    }

    // ---------------------------------------------------------------- conexión personal (cada usuario, su calendario)

    /**
     * Guarda la conexión personal de un usuario (vuelta de Google de authUrl(…, true)).
     * @return array{email:string,connected_at:string}
     */
    public function connectUser(int $userId, string $code): array
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
        if (!in_array(self::CALENDAR_SCOPE, explode(' ', (string) ($token['scope'] ?? '')), true)) {
            throw new GoogleException('No se ha concedido el permiso para leer el calendario');
        }
        $info = $this->request('GET', self::USERINFO_URL, null, (string) $token['access_token']);
        $connection = ['email' => (string) ($info['email'] ?? ''), 'connected_at' => date('c')];
        $this->settings->set('google.user.' . $userId, $connection + ['refresh_token' => $this->encrypt((string) $token['refresh_token'])]);
        $this->cacheToken($token, $userId);
        Log::info('Google: el usuario {user} ha conectado su calendario ({email})', ['user' => $userId, 'email' => $connection['email']]);
        return $connection;
    }

    /** @return array{email:string,connected_at:string}|null la conexión personal del usuario */
    public function userConnection(int $userId): ?array
    {
        $c = $this->settings->get('google.user.' . $userId);
        return is_array($c) && !empty($c['refresh_token'])
            ? ['email' => (string) ($c['email'] ?? ''), 'connected_at' => (string) ($c['connected_at'] ?? '')]
            : null;
    }

    public function disconnectUser(int $userId): void
    {
        $c = $this->settings->get('google.user.' . $userId);
        if (is_array($c) && !empty($c['refresh_token'])) {
            try {
                $this->request('POST', self::REVOKE_URL, ['token' => $this->decrypt((string) $c['refresh_token'])]);
            } catch (GoogleException $e) {
                Log::notice('Google: no se ha podido revocar el token personal', ['exception' => $e]);
            }
        }
        $this->settings->delete('google.user.' . $userId);
        $this->settings->delete('google.user_token.' . $userId);
        Log::info('Google: el usuario {user} ha desconectado su calendario', ['user' => $userId]);
    }

    /** ¿Puede este usuario conectar su propio Google Calendar? ('calendar' => true, o es de uno de sus grupos) */
    public function calendarFor(\Quasar\Core\User $user): bool
    {
        $cfg = $this->config['calendar'] ?? null;
        if (empty($cfg)) {
            return false;
        }
        return !is_array($cfg) || $user->isAdmin() || $user->inGroup(array_map('strval', $cfg));
    }

    /** ¿Se pide el permiso de Google Calendar ('calendar' => true en la configuración)? */
    public function calendarEnabled(): bool
    {
        return !empty($this->config['calendar']);
    }

    /** ¿Se puede leer Google Calendar? (activado y concedido al conectar; si no, hay que volver a conectar) */
    public function calendarReady(): bool
    {
        return $this->calendarEnabled() && ($this->connection()['calendar'] ?? false);
    }

    /**
     * Eventos de un calendario (el id es la dirección de la persona para su calendario principal; "primary", el de
     * la cuenta) que se solapan con [$from, $to). Con $userId se lee con la conexión personal de ese usuario; si no,
     * con la de la instalación, que tiene que poder ver ese calendario (en Workspace, compartido dentro de la
     * organización). Las horas, en la zona horaria de PHP.
     * @return list<array{id:string,title:string,start:string,end:?string,allDay:bool,url:?string,meet:?string,location:?string,busyOnly:bool,private:bool}>
     *   start/end "Y-m-d H:i", o "Y-m-d" si es de todo el día (fin exclusivo)
     * @throws GoogleException (status 403/404 si no se tiene acceso a ese calendario)
     */
    public function calendarEvents(string $calendarId, \DateTimeInterface $from, \DateTimeInterface $to, ?int $userId = null): array
    {
        if ($userId !== null ? !$this->calendarEnabled() || $this->userConnection($userId) === null : !$this->calendarReady()) {
            throw new GoogleException('Google Calendar no está activado o falta el permiso: vuelve a conectar la cuenta');
        }
        $bearer = $this->accessToken($userId);
        $tz = new \DateTimeZone(date_default_timezone_get());
        $out = [];
        $pageToken = null;
        for ($page = 0; $page < self::CALENDAR_PAGES; $page++) {
            $query = [
                'timeMin' => $from->format(DATE_RFC3339),
                'timeMax' => $to->format(DATE_RFC3339),
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'maxResults' => 250,
                'fields' => 'nextPageToken,items(id,status,summary,start,end,htmlLink,hangoutLink,location,visibility,conferenceData/entryPoints)',
            ] + ($pageToken !== null ? ['pageToken' => $pageToken] : []);
            $data = $this->request('GET', self::CALENDAR_URL . rawurlencode($calendarId) . '/events?' . http_build_query($query), null, $bearer);
            foreach ((array) ($data['items'] ?? []) as $e) {
                if (($e['status'] ?? '') === 'cancelled') {
                    continue;
                }
                $allDay = isset($e['start']['date']);
                $when = static fn (array $t): ?string => isset($t['date']) ? (string) $t['date']
                    : (isset($t['dateTime']) ? (new \DateTimeImmutable((string) $t['dateTime']))->setTimezone($tz)->format('Y-m-d H:i') : null);
                $start = $when((array) ($e['start'] ?? []));
                if ($start === null) {
                    continue;
                }
                $meet = isset($e['hangoutLink']) ? (string) $e['hangoutLink'] : null;
                foreach ((array) ($e['conferenceData']['entryPoints'] ?? []) as $p) {
                    if ($meet === null && ($p['entryPointType'] ?? '') === 'video' && !empty($p['uri'])) {
                        $meet = (string) $p['uri'];
                    }
                }
                $out[] = [
                    'id' => (string) ($e['id'] ?? ''),
                    'title' => trim((string) ($e['summary'] ?? '')),
                    'start' => $start,
                    'end' => $when((array) ($e['end'] ?? [])),
                    'allDay' => $allDay,
                    'url' => isset($e['htmlLink']) ? (string) $e['htmlLink'] : null,
                    'meet' => $meet,
                    'location' => isset($e['location']) ? (string) $e['location'] : null,
                    // Con permiso solo de disponibilidad, Google da el evento sin título
                    'busyOnly' => !isset($e['summary']),
                    // Privado: quien no es el propietario solo debería ver que está ocupado
                    'private' => in_array($e['visibility'] ?? '', ['private', 'confidential'], true),
                ];
            }
            $pageToken = isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null;
            if ($pageToken === null) {
                break;
            }
        }
        return $out;
    }

    /** @return list<string> permisos que se piden al conectar */
    private function scopes(): array
    {
        return $this->calendarEnabled() ? [...self::SCOPES, self::CALENDAR_SCOPE] : self::SCOPES;
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

    /** Token de acceso de la instalación (o de la conexión personal de $userId), renovado si ha caducado. */
    private function accessToken(?int $userId = null): string
    {
        $cached = $this->settings->get($userId !== null ? 'google.user_token.' . $userId : 'google.token');
        if (is_array($cached) && ($cached['expires'] ?? 0) > time() + 60 && !empty($cached['token'])) {
            return $this->decrypt((string) $cached['token']);
        }
        $c = $this->settings->get($userId !== null ? 'google.user.' . $userId : 'google.oauth');
        if (!is_array($c) || empty($c['refresh_token'])) {
            throw new GoogleException($userId !== null ? 'El usuario no ha conectado su Google Calendar' : 'La instalación no está conectada con Google');
        }
        $token = $this->request('POST', self::TOKEN_URL, [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $this->decrypt((string) $c['refresh_token']),
            'grant_type' => 'refresh_token',
        ]);
        $this->cacheToken($token, $userId);
        return (string) $token['access_token'];
    }

    private function cacheToken(array $token, ?int $userId = null): void
    {
        if (!empty($token['access_token'])) {
            $this->settings->set($userId !== null ? 'google.user_token.' . $userId : 'google.token', [
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
