<?php
declare(strict_types=1);

namespace Quasar\Hooks;

use DateTimeImmutable;
use Quasar\Core\App;
use Quasar\Core\CalendarSource;
use Quasar\Core\Log;
use Quasar\Core\User;
use Quasar\Google\GoogleClient;
use Quasar\Google\GoogleException;

/**
 * Asteroid · Agenda (modules/agenda.json, "extra"): el Google Calendar de cada persona del equipo junto a las fechas
 * límite de las incidencias. Lo lee la cuenta de Google conectada en Integraciones ('google' => ['calendar' => true]);
 * los calendarios de los demás se tienen que poder ver desde ella (compartidos dentro de la organización).
 *
 * Personas: los usuarios activos del equipo con correo del mismo dominio que la cuenta conectada, o la lista de
 * config.local.php: 'agenda' => ['calendars' => ['kim@aficat.com' => '#2563eb', 'sala@aficat.com' => null]].
 * Los eventos de cada calendario se guardan 5 minutos (storage/cache/agenda) para no preguntar a Google en cada clic.
 */
final class AgendaGoogle implements CalendarSource
{
    private const TTL = 300;
    private const ERROR_TTL = 900;
    private const PALETTE = ['#2563eb', '#16a34a', '#db2777', '#ea580c', '#7c3aed', '#0891b2', '#ca8a04', '#dc2626', '#4f46e5', '#059669'];

    private ?array $people = null;

    public function events(App $app, User $user, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $google = $app->google();
        if ($google === null || !$google->calendarReady()) {
            return [];
        }
        $busy = $app->lang->get('agenda:google_busy');
        $out = [];
        foreach ($this->people($app, $google) as $p) {
            foreach ($this->calendar($app, $google, $p['email'], $from, $to) as $e) {
                $title = $e['busyOnly'] || $e['title'] === '' ? $busy : $e['title'];
                $out[] = [
                    'start' => $e['start'],
                    'end' => $e['end'],
                    'allDay' => $e['allDay'],
                    'title' => $title . ($e['meet'] !== null && stripos($title, 'meet') === false ? ' · Meet' : ''),
                    'url' => $e['url'] ?? $e['meet'],
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
        if ($google === null || !$google->calendarReady()) {
            return [];
        }
        // Quien no se ha podido leer hace poco: que se vea que tiene que compartir su calendario
        $noAccess = (array) $app->settings()->get('agenda.no_access', []);
        $label = $app->lang->get('agenda:google_no_access');
        return array_map(static fn (array $p) => [
            'label' => $p['name'] . ((int) ($noAccess[$p['email']] ?? 0) > time() - 86400 ? ' (' . $label . ')' : ''),
            'tone' => $p['color'],
        ], $this->people($app, $google));
    }

    /** @return list<array{email:string,name:string,color:string}> */
    private function people(App $app, GoogleClient $google): array
    {
        if ($this->people !== null) {
            return $this->people;
        }
        $list = [];
        $configured = (array) ($app->config['agenda']['calendars'] ?? []);
        if ($configured) {
            $names = [];
            foreach ($app->db->fetchAll('SELECT name, email FROM users WHERE email IS NOT NULL') as $u) {
                $names[strtolower((string) $u['email'])] = (string) $u['name'];
            }
            foreach ($configured as $email => $color) {
                $email = strtolower(trim((string) $email));
                $list[] = ['email' => $email, 'name' => $names[$email] ?? $email, 'color' => is_string($color) ? $color : ''];
            }
        } else {
            // El equipo: administradores y grupo "equip", con correo del dominio de la cuenta conectada
            $domain = strtolower((string) substr(strrchr((string) ($google->connection()['email'] ?? ''), '@') ?: '', 1));
            $rows = $app->db->fetchAll(
                "SELECT name, email FROM users
                  WHERE active = 1 AND email IS NOT NULL AND email <> '' AND client_id IS NULL
                    AND (is_admin = 1 OR JSON_CONTAINS(`groups`, (SELECT CONCAT(g.id) FROM user_groups g WHERE g.code = 'equip')))
                  ORDER BY name"
            );
            foreach ($rows as $u) {
                $email = strtolower((string) $u['email']);
                if ($domain === '' || str_ends_with($email, '@' . $domain)) {
                    $list[] = ['email' => $email, 'name' => (string) $u['name'], 'color' => ''];
                }
            }
        }
        foreach ($list as $i => &$p) {
            if (!preg_match('/^#[0-9a-f]{6}$/i', $p['color'])) {
                $p['color'] = self::PALETTE[$i % count(self::PALETTE)];
            }
        }
        unset($p);
        return $this->people = $list;
    }

    /** Eventos de un calendario, con caché; si no hay acceso, nada (y se anota una vez cada tanto). */
    private function calendar(App $app, GoogleClient $google, string $email, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $dir = QS_ROOT . '/storage/cache/agenda';
        $file = $dir . '/' . hash('sha256', $email . '|' . $from->format('c') . '|' . $to->format('c')) . '.json';
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($cached) && (int) ($cached['until'] ?? 0) > time()) {
            return (array) ($cached['events'] ?? []);
        }
        $noAccess = (array) $app->settings()->get('agenda.no_access', []);
        try {
            $events = $google->calendarEvents($email, $from, $to);
            $until = time() + self::TTL;
            if (isset($noAccess[$email])) {
                unset($noAccess[$email]);
                $app->settings()->set('agenda.no_access', $noAccess);
            }
        } catch (GoogleException $e) {
            if (!isset($noAccess[$email]) || $noAccess[$email] < time() - 3600) {
                Log::warning('Agenda: no se puede leer el calendario de {email}: {error}', ['email' => $email, 'error' => $e->getMessage()]);
            }
            if (in_array($e->status, [403, 404], true)) {
                $noAccess[$email] = time();
                $app->settings()->set('agenda.no_access', $noAccess);
            }
            $events = [];
            $until = time() + self::ERROR_TTL;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, json_encode(['until' => $until, 'events' => $events], JSON_UNESCAPED_UNICODE), LOCK_EX);
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
