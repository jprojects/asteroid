<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use DateTimeImmutable;
use Quasar\Core\App;
use Quasar\Core\CalendarSource;
use Quasar\Core\Log;
use Quasar\Core\Session;
use Quasar\Core\User;
use Quasar\Google\GoogleClient;
use Quasar\Google\GoogleException;

/**
 * Asteroid · Agenda (modules/agenda.json, "extra"): el Google Calendar de las personas del equipo junto a las fechas
 * límite de las incidencias ('google' => ['calendar' => true]).
 *
 * Cada persona conecta **su propio** Google Calendar desde su perfil (Quasar 0.24.0: se lee con su token, sin
 * compartir calendarios). Por defecto, cada uno ve el suyo; «Tot l'equip» muestra también el de los compañeros que se
 * han conectado (sus eventos privados, como «Ocupat»). Quien no se ha conectado pero se puede leer con la cuenta de la
 * instalación (Integracions) también sale, como antes.
 *
 * Otra lista de calendarios (p. ej. una sala): 'agenda' => ['calendars' => ['sala@aficat.com' => '#2563eb']], leídos
 * con la cuenta de la instalación. Caché de 5 minutos por calendario (storage/cache/agenda).
 */
final class AgendaGoogle implements CalendarSource
{
    private const TTL = 300;
    private const ERROR_TTL = 900;
    private const SESSION = 'agenda.who';
    private const PALETTE = ['#2563eb', '#16a34a', '#db2777', '#ea580c', '#7c3aed', '#0891b2', '#ca8a04', '#dc2626', '#4f46e5', '#059669'];

    private ?array $people = null;

    /** Selector «El meu calendari · Tot l'equip» y, si no se ha conectado, el enlace al perfil. */
    public function notice(App $app, User $user): ?array
    {
        $google = $app->google();
        if ($google === null || !$google->calendarEnabled()) {
            return null;
        }
        $who = $this->who();
        $mine = $google->userConnection($user->id()) !== null || $this->viaInstallation($app, $google, (string) $user->email());
        $base = $app->url('page', ['m' => 'agenda']);
        $links = [
            ['label' => $app->lang->get('agenda:google_who_me'), 'url' => $base . '&who=me', 'active' => $who === 'me'],
            ['label' => $app->lang->get('agenda:google_who_all'), 'url' => $base . '&who=all', 'active' => $who === 'all'],
        ];
        if (!$mine) {
            $links[] = ['label' => $app->lang->get('agenda:google_connect'), 'url' => $app->url('profile') . '#google'];
        }
        return ['text' => $mine ? '' : $app->lang->get('agenda:google_not_connected'), 'links' => $links];
    }

    public function events(App $app, User $user, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $google = $app->google();
        if ($google === null || !$google->calendarEnabled()) {
            return [];
        }
        $busy = $app->lang->get('agenda:google_busy');
        $out = [];
        foreach ($this->people($app, $google, $user) as $p) {
            foreach ($this->calendar($app, $google, $p, $from, $to) as $e) {
                // Lo privado de otra persona: solo que está ocupada
                $hidden = $e['busyOnly'] || $e['title'] === '' || (!empty($e['private']) && $p['user'] !== $user->id());
                $title = $hidden ? $busy : $e['title'];
                $out[] = [
                    'start' => $e['start'],
                    'end' => $e['end'],
                    'allDay' => $e['allDay'],
                    'title' => $title . (!$hidden && $e['meet'] !== null && stripos($title, 'meet') === false ? ' · Meet' : ''),
                    'url' => $p['user'] === $user->id() || !$hidden ? ($e['url'] ?? $e['meet']) : null,
                    'tone' => $p['color'],
                    'label' => $p['name'],
                ];
            }
        }
        return $out;
    }

    public function legend(App $app, User $user): array
    {
        $google = $app->google();
        if ($google === null || !$google->calendarEnabled()) {
            return [];
        }
        $noAccess = (array) $app->settings()->get('agenda.no_access', []);
        $label = $app->lang->get('agenda:google_no_access');
        return array_map(static fn (array $p) => [
            'label' => $p['name'] . ((int) ($noAccess[$p['key']] ?? 0) > time() - 86400 ? ' (' . $label . ')' : ''),
            'tone' => $p['color'],
        ], $this->people($app, $google, $user));
    }

    /** «me» (por defecto) o «all»: el enlace ?who= lo cambia y se recuerda en la sesión. */
    private function who(): string
    {
        $who = $_GET['who'] ?? null;
        if (in_array($who, ['me', 'all'], true)) {
            Session::set(self::SESSION, $who);
            return $who;
        }
        $saved = Session::get(self::SESSION);
        return in_array($saved, ['me', 'all'], true) ? $saved : 'me';
    }

    /**
     * Calendarios que se ven: el propio (o, con «Tot l'equip», los de todas las personas del equipo que se pueden leer).
     * @return list<array{key:string,user:?int,email:string,calendar:string,name:string,color:string}>
     */
    private function people(App $app, GoogleClient $google, User $viewer): array
    {
        if ($this->people !== null) {
            return $this->people;
        }
        $list = [];
        $rows = $app->db->fetchAll(
            "SELECT id, name, email FROM users
              WHERE active = 1 AND client_id IS NULL
                AND (is_admin = 1 OR JSON_CONTAINS(`groups`, (SELECT CONCAT(g.id) FROM user_groups g WHERE g.code = 'equip')))
              ORDER BY name"
        );
        $all = $this->who() === 'all';
        foreach ($rows as $i => $u) {
            $id = (int) $u['id'];
            if (!$all && $id !== $viewer->id()) {
                continue;
            }
            $email = strtolower((string) $u['email']);
            if (($c = $google->userConnection($id)) !== null) {
                $list[] = ['key' => 'user:' . $id, 'user' => $id, 'email' => $c['email'], 'calendar' => 'primary', 'name' => (string) $u['name'], 'color' => self::PALETTE[$i % count(self::PALETTE)]];
            } elseif ($this->viaInstallation($app, $google, $email)) {
                $list[] = ['key' => $email, 'user' => null, 'email' => $email, 'calendar' => $email, 'name' => (string) $u['name'], 'color' => self::PALETTE[$i % count(self::PALETTE)]];
            }
        }
        // Calendarios de la configuración (salas, etc.), con la cuenta de la instalación
        $n = count($rows);
        foreach ((array) ($app->config['agenda']['calendars'] ?? []) as $email => $color) {
            if (!$all || !$google->calendarReady()) {
                break;
            }
            $email = strtolower(trim((string) $email));
            $list[] = ['key' => $email, 'user' => null, 'email' => $email, 'calendar' => $email, 'name' => $email,
                'color' => is_string($color) && preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : self::PALETTE[$n++ % count(self::PALETTE)]];
        }
        return $this->people = $list;
    }

    /** ¿Se lee el calendario de esa dirección con la cuenta de la instalación? (la propia cuenta conectada) */
    private function viaInstallation(App $app, GoogleClient $google, string $email): bool
    {
        $c = $google->connection();
        return $google->calendarReady() && $c !== null && strtolower($c['email']) === strtolower($email);
    }

    /** Eventos de un calendario, con caché; si no hay acceso, nada (y se anota una vez cada tanto). */
    private function calendar(App $app, GoogleClient $google, array $p, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $dir = QS_ROOT . '/storage/cache/agenda';
        $file = $dir . '/' . hash('sha256', $p['key'] . '|' . $from->format('c') . '|' . $to->format('c')) . '.json';
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $noAccess = (array) $app->settings()->get('agenda.no_access', []);
        // Un error guardado deja de valer si ese calendario ya se ha podido leer después (en otra vista o rango)
        $staleError = is_array($cached) && !empty($cached['error']) && (int) ($noAccess[$p['key']] ?? 0) < (int) ($cached['at'] ?? 0);
        if (is_array($cached) && (int) ($cached['until'] ?? 0) > time() && !$staleError) {
            return (array) ($cached['events'] ?? []);
        }
        $error = false;
        try {
            $events = $google->calendarEvents($p['calendar'], $from, $to, $p['user']);
            $until = time() + self::TTL;
            if (isset($noAccess[$p['key']])) {
                unset($noAccess[$p['key']]);
                $app->settings()->set('agenda.no_access', $noAccess);
            }
        } catch (GoogleException $e) {
            if (!isset($noAccess[$p['key']]) || $noAccess[$p['key']] < time() - 3600) {
                Log::warning('Agenda: no se puede leer el calendario de {email}: {error}', ['email' => $p['email'], 'error' => $e->getMessage()]);
            }
            $noAccess[$p['key']] = time();
            $app->settings()->set('agenda.no_access', $noAccess);
            $events = [];
            $until = time() + self::ERROR_TTL;
            $error = true;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, json_encode(['until' => $until, 'events' => $events, 'error' => $error, 'at' => time()], JSON_UNESCAPED_UNICODE), LOCK_EX);
        $this->clean($dir);
        return $events;
    }

    /** Borra de vez en cuando las cachés viejas (rangos que ya no se miran). */
    private function clean(string $dir): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
    }
}
