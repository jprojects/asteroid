#!/usr/bin/env php
<?php
/**
 * Asteroid · servidor MCP para Claude Code (local, stdio, sin dependencias): Claude lee las incidencias de Asteroid
 * (ficha, conversación, adjuntos y horas) y, con tu confirmación, responde, imputa horas o cambia el estado; también lee
 * y escribe la wiki (documentación de los proyectos).
 * Usa la API de Asteroid con TU token personal (Perfil → API): ve y hace lo mismo que tú, nada más.
 *
 * Alta en Claude Code (una vez; disponible en todos los proyectos):
 *
 *   claude mcp add asteroid --scope user \
 *     -e ASTEROID_URL=https://sat.aficat.com -e ASTEROID_TOKEN=qs_… \
 *     -- php /home/…/asteroid-quasar/tools/mcp.php
 *
 * Sin ASTEROID_TOKEN, el token se lee de ~/.config/asteroid-mcp/token (o de ASTEROID_TOKEN_FILE), con permisos 600.
 * Opcional: ASTEROID_FILES (carpeta de los adjuntos descargados; por defecto ~/.cache/asteroid-mcp).
 * Protocolo: JSON-RPC 2.0, un mensaje por línea en stdin/stdout (los avisos van a stderr).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const MCP_VERSION = '2025-06-18';
const MAX_MESSAGES = 200;
const MAX_INLINE_IMAGES = 4;
const MAX_INLINE_BYTES = 1_500_000;
const STATUSES = ['pendent' => 1, 'en_curs' => 2, 'resolta' => 3, 'descartada' => 4];

$base = rtrim((string) getenv('ASTEROID_URL'), '/');
// Carpeta del usuario: HOME en Linux/macOS, USERPROFILE en Windows (allí HOME no suele existir).
$home = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');
$token = (string) getenv('ASTEROID_TOKEN');
if ($token === '') {
    // O en un fichero privado (así no queda en la configuración de Claude Code): ~/.config/asteroid-mcp/token
    $tokenFile = (string) (getenv('ASTEROID_TOKEN_FILE') ?: ($home . '/.config/asteroid-mcp/token'));
    $token = is_readable($tokenFile) ? trim((string) file_get_contents($tokenFile)) : '';
}
$filesDir = (string) (getenv('ASTEROID_FILES') ?: (($home ?: sys_get_temp_dir()) . '/.cache/asteroid-mcp'));

// ------------------------------------------------------------------------------------------------- API de Asteroid

final class AsteroidApi
{
    public function __construct(private readonly string $base, private readonly string $token)
    {
    }

    /** URL de la API (también sin reescritura de URL: index.php?r=api&p=v1/…). */
    public function url(string $path, array $query = []): string
    {
        $url = $this->base . '/index.php?r=api&p=' . rawurlencode('v1/' . ltrim($path, '/'));
        return $query ? $url . '&' . http_build_query($query) : $url;
    }

    /** @return array<string,mixed> */
    public function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        [$status, $raw] = $this->request($method, $this->url($path, $query), $body);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Asteroid ha respost $status sense JSON (revisa ASTEROID_URL)");
        }
        if ($status >= 400) {
            $fields = isset($data['fields']) && is_array($data['fields']) ? ' (' . implode('; ', array_map(
                static fn ($k, $v) => "$k: $v", array_keys($data['fields']), $data['fields'])) . ')' : '';
            throw new RuntimeException('Asteroid: ' . ($data['error'] ?? "error $status") . $fields);
        }
        return $data;
    }

    /** Descarga un archivo (URL absoluta de la API). @return string el contenido */
    public function download(string $url): string
    {
        [$status, $raw] = $this->request('GET', $url, null);
        if ($status >= 400) {
            throw new RuntimeException("No s'ha pogut baixar el fitxer ($status)");
        }
        return $raw;
    }

    /** @return array{0:int,1:string} */
    private function request(string $method, string $url, ?array $body): array
    {
        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->token, 'X-Api-Key: ' . $this->token];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Asteroid-MCP/1.0',
        ] + (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')
            // El PHP de Windows no trae certificados: se usan los del sistema (si no, falla el HTTPS).
            ? [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA] : []
        ) + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)] : []));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) {
            throw new RuntimeException('No es pot connectar amb Asteroid: ' . curl_error($ch));
        }
        return [$status, (string) $raw];
    }
}

// ------------------------------------------------------------------------------------------------- herramientas

final class Tools
{
    private ?array $me = null;

    public function __construct(private readonly AsteroidApi $api, private readonly string $filesDir)
    {
    }

    /** Definición de las herramientas (tools/list). */
    public static function definitions(): array
    {
        $id = ['type' => 'integer', 'description' => "Número de la incidència (p. ex. 4014)"];
        return [
            [
                'name' => 'asteroid_incidencia',
                'description' => "Llegeix una incidència d'Asteroid sencera: dades (client, projecte, estat, prioritat, tècnic), "
                    . "descripció, tota la conversa, els adjunts (es baixen en local; les imatges també es mostren) i les hores imputades.",
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'asteroid_les_meves',
                'description' => "Les incidències obertes (pendents i en curs) assignades a l'usuari del token, les més prioritàries primer.",
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'asteroid_cerca',
                'description' => "Cerca incidències pel text (títol, descripció o número).",
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'text' => ['type' => 'string'],
                    'nomes_obertes' => ['type' => 'boolean', 'description' => 'Només pendents i en curs (per defecte, sí)'],
                ], 'required' => ['text']],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'asteroid_respon',
                'description' => "Escriu un missatge a la conversa d'una incidència, amb l'usuari del token. Si la incidència és visible "
                    . "per al client, el client el rebrà (i un avís per correu). Ensenya sempre el text a l'usuari i demana-li "
                    . "confirmació abans de fer-ho servir. Escriu en l'idioma de la conversa.",
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $id, 'text' => ['type' => 'string']], 'required' => ['id', 'text']],
                'annotations' => ['destructiveHint' => false, 'openWorldHint' => true],
            ],
            [
                'name' => 'asteroid_imputa',
                'description' => "Imputa hores a una incidència (a l'usuari del token i al projecte de la incidència). Demana confirmació a l'usuari abans.",
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id' => $id,
                    'temps' => ['type' => 'string', 'description' => 'Durada: 1:30, 45m, 2h'],
                    'comentari' => ['type' => 'string', 'description' => 'Què s\'ha fet (es veu a la factura)'],
                    'data' => ['type' => 'string', 'description' => 'AAAA-MM-DD (per defecte, avui)'],
                ], 'required' => ['id', 'temps']],
            ],
            [
                'name' => 'asteroid_estat',
                'description' => "Canvia l'estat d'una incidència (pendent, en_curs, resolta, descartada). Demana confirmació a l'usuari abans.",
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'id' => $id,
                    'estat' => ['type' => 'string', 'enum' => array_keys(STATUSES)],
                ], 'required' => ['id', 'estat']],
            ],
            [
                'name' => 'asteroid_wiki',
                'description' => "Wiki d'Asteroid (documentació dels projectes). Sense «pagina»: la llista de pàgines (d'un projecte, si es diu, "
                    . "i amb un text a cercar), per seccions i amb el número de cada una. Amb «pagina»: la pàgina sencera amb el seu HTML "
                    . "(per editar-la amb asteroid_wiki_desa).",
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'projecte' => ['type' => 'string', 'description' => 'Nom o número del projecte (p. ex. NEUTRON o 268)'],
                    'cerca' => ['type' => 'string', 'description' => 'Text a cercar al títol, la secció o el contingut'],
                    'pagina' => ['type' => 'integer', 'description' => 'Número de la pàgina per llegir-la sencera'],
                ]],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'asteroid_wiki_desa',
                'description' => "Crea o actualitza una pàgina de la wiki d'Asteroid (amb «pagina», aquesta; si no, la del mateix títol al "
                    . "projecte, o una de nova). Escriu en català, en HTML senzill (p, h3, ul, ol, li, strong, em, code, a, table), "
                    . "amb frases curtes i passos numerats; el títol, un nom (no una frase). Mai contrasenyes, tokens ni claus: només on "
                    . "són. «audiencia» equip (per defecte: només l'equip d'AFI) o client (la veu el client del projecte: manuals d'ús). "
                    . "Ensenya a l'usuari el títol, el projecte, l'audiència i un resum del contingut i demana-li confirmació abans.",
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'projecte' => ['type' => 'string', 'description' => 'Nom o número del projecte'],
                    'titol' => ['type' => 'string'],
                    'cos' => ['type' => 'string', 'description' => 'Contingut en HTML'],
                    'seccio' => ['type' => 'string', 'description' => 'Secció del menú de la wiki (agrupa pàgines)'],
                    'ordre' => ['type' => 'integer', 'description' => 'Ordre dins de la secció'],
                    'audiencia' => ['type' => 'string', 'enum' => ['equip', 'client']],
                    'pagina' => ['type' => 'integer', 'description' => 'Número de la pàgina, per actualitzar-ne una de concreta'],
                ], 'required' => ['titol', 'cos']],
                'annotations' => ['destructiveHint' => false],
            ],
        ];
    }

    /** @return array{content:list<array>,isError?:bool} */
    public function call(string $name, array $args): array
    {
        return match ($name) {
            'asteroid_incidencia' => $this->issue(self::id($args)),
            'asteroid_les_meves' => $this->mine(),
            'asteroid_cerca' => $this->search((string) ($args['text'] ?? ''), ($args['nomes_obertes'] ?? true) !== false),
            'asteroid_respon' => $this->reply(self::id($args), (string) ($args['text'] ?? '')),
            'asteroid_imputa' => $this->logHours(self::id($args), (string) ($args['temps'] ?? ''), (string) ($args['comentari'] ?? ''), (string) ($args['data'] ?? '')),
            'asteroid_estat' => $this->setStatus(self::id($args), (string) ($args['estat'] ?? '')),
            'asteroid_wiki' => $this->wiki($args),
            'asteroid_wiki_desa' => $this->wikiSave($args),
            default => throw new InvalidArgumentException("Eina desconeguda: $name"),
        };
    }

    private function issue(int $id): array
    {
        $i = $this->api->call('GET', "issues/$id", ['lang' => 'ca']);
        $d = $i['_display'] ?? [];
        $show = static fn (string $k) => (string) ($d[$k] ?? (is_scalar($i[$k] ?? null) ? $i[$k] : '') ?: '—');
        $out = ["# Incidència #$id · " . ($i['title'] ?? ''), ''];
        foreach (['client_id' => 'Client', 'project_id' => 'Projecte', 'status' => 'Estat', 'priority' => 'Prioritat',
                  'worker_id' => 'Tècnic', 'created_at' => 'Creada', 'due_on' => 'Data límit', 'client_visible' => 'Visible per al client'] as $k => $label) {
            if (array_key_exists($k, $i)) {
                $out[] = "- **$label:** " . $show($k);
            }
        }
        $out[] = '';
        $out[] = '## Descripció';
        $out[] = self::text((string) ($i['description'] ?? '')) ?: '(buida)';

        $files = [];
        foreach ((array) ($i['_files'] ?? []) as $list) {
            foreach ((array) $list as $f) {
                $files[] = $f + ['from' => 'incidència'];
            }
        }

        // Conversa: de la més antiga a la més nova (la API la dona per pàgines, començant per les últimes)
        $messages = [];
        $before = null;
        do {
            $page = $this->api->call('GET', "issues/$id/messages", $before !== null ? ['before' => $before] : []);
            $chunk = (array) ($page['messages'] ?? []);
            $messages = array_merge($chunk, $messages);
            $before = $chunk ? (int) $chunk[0]['id'] : null;
        } while (!empty($page['older']) && $chunk && count($messages) < MAX_MESSAGES);
        $out[] = '';
        $out[] = '## Conversa (' . count($messages) . ' missatges)';
        foreach ($messages as $m) {
            if (!empty($m['deleted'])) {
                continue;
            }
            $who = $m['kind'] === 'system' ? 'Sistema' : (string) ($m['user']['name'] ?? $m['meta']['author'] ?? 'Client');
            $text = self::text((string) ($m['body'] ?? ''));
            foreach ((array) ($m['media'] ?? []) as $f) {
                $files[] = $f + ['from' => 'missatge del ' . substr((string) $m['at'], 0, 10)];
                $text .= ($text !== '' ? ' ' : '') . '[adjunt: ' . ($f['name'] ?? 'fitxer') . ']';
            }
            $out[] = '- **' . $who . '** (' . str_replace('T', ' ', substr((string) $m['at'], 0, 16)) . '): ' . str_replace("\n", "\n  ", $text);
        }

        // Hores imputades
        $hours = $this->api->call('GET', 'hours', ['f' => ['issue_id' => $id], 'fields' => 'all', 'per_page' => 200, 'sort' => 'worked_on', 'dir' => 'asc']);
        $rows = (array) ($hours['data'] ?? []);
        if ($rows) {
            $total = array_sum(array_map(static fn ($h) => (int) ($h['minutes'] ?? 0), $rows));
            $out[] = '';
            $out[] = '## Hores imputades: ' . sprintf('%d:%02d', intdiv($total, 60), $total % 60);
            foreach ($rows as $h) {
                $hd = $h['_display'] ?? [];
                $out[] = '- ' . ($hd['worked_on'] ?? $h['worked_on'] ?? '') . ' · ' . ($hd['user_id'] ?? '') . ' · ' . ($hd['minutes'] ?? '')
                    . (trim((string) ($h['comment'] ?? '')) !== '' ? ' · ' . preg_replace('/\s+/', ' ', (string) $h['comment']) : '');
            }
        }

        // Adjunts: es baixen; les imatges petites també van a la resposta perquè Claude les vegi
        $content = [];
        if ($files) {
            $dir = $this->filesDir . '/incidencia-' . $id;
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new RuntimeException("No es pot crear $dir");
            }
            $out[] = '';
            $out[] = '## Adjunts (baixats a ' . $dir . ')';
            $inline = 0;
            foreach ($files as $n => $f) {
                $name = preg_replace('/[^\pL\pN._ -]+/u', '_', (string) ($f['name'] ?? 'fitxer')) ?: 'fitxer';
                $path = $dir . '/' . ($n + 1) . '-' . $name;
                try {
                    if (!is_file($path)) {
                        file_put_contents($path, $this->api->download((string) ($f['download'] ?? $f['url'])));
                    }
                    $out[] = '- ' . $path . ' (' . ($f['sizeText'] ?? '') . ', ' . $f['from'] . ')';
                    if (!empty($f['isImage']) && $inline < MAX_INLINE_IMAGES && filesize($path) <= MAX_INLINE_BYTES) {
                        $content[] = ['type' => 'image', 'data' => base64_encode((string) file_get_contents($path)), 'mimeType' => (string) $f['mime']];
                        $inline++;
                    }
                } catch (Throwable $e) {
                    $out[] = '- ' . ($f['name'] ?? 'fitxer') . ': ' . $e->getMessage();
                }
            }
        }
        $out[] = '';
        $out[] = 'Enllaç: ' . $this->webUrl($id);
        array_unshift($content, ['type' => 'text', 'text' => implode("\n", $out)]);
        return ['content' => $content];
    }

    private function mine(): array
    {
        $me = $this->me();
        $list = $this->api->call('GET', 'issues', ['f' => ['worker_id' => $me['id'], 'status' => [1, 2]], 'sort' => 'priority', 'dir' => 'desc', 'per_page' => 50]);
        return self::text_(self::issueList((array) ($list['data'] ?? []), 'Incidències obertes de ' . ($me['name'] ?? '')));
    }

    private function search(string $text, bool $open): array
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Falta el text a cercar');
        }
        $query = ['q' => $text, 'sort' => 'id', 'dir' => 'desc', 'per_page' => 25];
        if ($open) {
            $query['f'] = ['status' => [1, 2]];
        }
        $list = $this->api->call('GET', 'issues', $query);
        return self::text_(self::issueList((array) ($list['data'] ?? []), "Resultats per «{$text}»"));
    }

    private function reply(int $id, string $text): array
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('El missatge és buit');
        }
        $this->api->call('POST', "issues/$id/messages", [], ['body' => $text]);
        return self::text_("Missatge enviat a la conversa de la #$id.");
    }

    private function logHours(int $id, string $time, string $comment, string $date): array
    {
        if (trim($time) === '') {
            throw new InvalidArgumentException('Falta el temps (p. ex. 1:30)');
        }
        $body = ['issue_id' => $id, 'minutes' => trim($time), 'worked_on' => $date !== '' ? $date : date('Y-m-d'), 'user_id' => $this->me()['id']];
        if (trim($comment) !== '') {
            $body['comment'] = $comment;
        }
        $h = $this->api->call('POST', 'hours', [], $body);
        return self::text_('Hores imputades a la #' . $id . ': ' . ($h['_display']['minutes'] ?? $time) . ' el ' . ($h['_display']['worked_on'] ?? $body['worked_on']) . '.');
    }

    private function setStatus(int $id, string $status): array
    {
        if (!isset(STATUSES[$status])) {
            throw new InvalidArgumentException('Estat no vàlid: ' . implode(', ', array_keys(STATUSES)));
        }
        $i = $this->api->call('PATCH', "issues/$id", [], ['status' => STATUSES[$status]]);
        return self::text_("#$id ara està: " . ($i['_display']['status'] ?? $status) . '.');
    }

    /** Número de un projecte pel nom (el que coincideix, o l'únic que surt) o pel número */
    private function projectId(string $project): int
    {
        $project = trim($project);
        if (preg_match('/^\d+$/', $project)) {
            return (int) $project;
        }
        $rows = (array) ($this->api->call('GET', 'projects', ['q' => $project, 'per_page' => 20, 'fields' => 'id,title'])['data'] ?? []);
        foreach ($rows as $r) {
            if (mb_strtolower((string) $r['title']) === mb_strtolower($project)) {
                return (int) $r['id'];
            }
        }
        if (count($rows) === 1) {
            return (int) $rows[0]['id'];
        }
        throw new InvalidArgumentException($rows ? "Hi ha diversos projectes per «{$project}»: "
            . implode(', ', array_map(static fn ($r) => $r['title'] . ' (' . $r['id'] . ')', $rows)) : "No hi ha cap projecte «{$project}»");
    }

    private function wikiUrl(int $id): string
    {
        return preg_replace('#/index\.php\?r=api.*$#', '', $this->api->url('x')) . '/index.php?r=page&m=wiki&page=' . $id;
    }

    private function wiki(array $args): array
    {
        if (!empty($args['pagina'])) {
            $p = $this->api->call('GET', 'wiki_pages/' . (int) $args['pagina'], ['lang' => 'ca']);
            $d = $p['_display'] ?? [];
            return self::text_(implode("\n", [
                '# ' . ($p['title'] ?? ''), '',
                '- **Projecte:** ' . ($d['project_id'] ?? $p['project_id'] ?? '—') . ' · **Secció:** ' . (($p['section'] ?? '') ?: '—')
                    . ' · **Ordre:** ' . ($p['ordering'] ?? 0) . ' · **Per a:** ' . ($d['audience'] ?? $p['audience'] ?? ''),
                '- **Actualitzada:** ' . ($d['updated_at'] ?? $p['updated_at'] ?? '') . ' · ' . $this->wikiUrl((int) $p['id']), '',
                '## HTML', '', (string) ($p['body'] ?? ''),
            ]));
        }
        $query = ['sort' => 'ordering', 'dir' => 'asc', 'per_page' => 200, 'fields' => 'id,title,project_id,section,ordering,audience'];
        if (!empty($args['projecte'])) {
            $query['f'] = ['project_id' => $this->projectId((string) $args['projecte'])];
        }
        if (!empty($args['cerca'])) {
            $query['q'] = (string) $args['cerca'];
        }
        $rows = (array) ($this->api->call('GET', 'wiki_pages', $query)['data'] ?? []);
        if (!$rows) {
            return self::text_('Cap pàgina.');
        }
        $groups = [];
        foreach ($rows as $r) {
            $d = $r['_display'] ?? [];
            $groups[($d['project_id'] ?? $r['project_id']) . ' › ' . (($r['section'] ?? '') ?: 'Sense secció')][] =
                '- ' . $r['id'] . ': ' . $r['title'] . ($r['audience'] === 'client' ? ' (client)' : '');
        }
        $out = ['Pàgines de la wiki (' . count($rows) . '):'];
        foreach ($groups as $g => $lines) {
            $out[] = "\n**$g**";
            array_push($out, ...$lines);
        }
        return self::text_(implode("\n", $out));
    }

    private function wikiSave(array $args): array
    {
        $title = trim((string) ($args['titol'] ?? ''));
        $body = trim((string) ($args['cos'] ?? ''));
        if ($title === '' || $body === '') {
            throw new InvalidArgumentException('Falten el títol o el contingut');
        }
        $data = ['title' => $title, 'body' => $body];
        foreach (['seccio' => 'section', 'ordre' => 'ordering'] as $in => $field) {
            if (array_key_exists($in, $args)) {
                $data[$field] = $args[$in];
            }
        }
        if (!empty($args['audiencia'])) {
            $data['audience'] = $args['audiencia'] === 'client' ? 'client' : 'team';
        }
        $id = (int) ($args['pagina'] ?? 0);
        $projectId = !empty($args['projecte']) ? $this->projectId((string) $args['projecte']) : 0;
        if ($id === 0 && $projectId > 0) {
            // La mateixa pàgina (mateix títol al projecte): s'actualitza en lloc de duplicar-la
            foreach ((array) ($this->api->call('GET', 'wiki_pages', ['f' => ['project_id' => $projectId], 'per_page' => 200, 'fields' => 'id,title'])['data'] ?? []) as $r) {
                if (mb_strtolower(trim((string) $r['title'])) === mb_strtolower($title)) {
                    $id = (int) $r['id'];
                    break;
                }
            }
        }
        if ($id > 0) {
            if ($projectId > 0) {
                $data['project_id'] = $projectId;
            }
            $p = $this->api->call('PATCH', 'wiki_pages/' . $id, [], $data);
            return self::text_("Pàgina actualitzada: «{$p['title']}» · " . $this->wikiUrl($id));
        }
        if ($projectId === 0) {
            throw new InvalidArgumentException('Per crear una pàgina cal el projecte');
        }
        $p = $this->api->call('POST', 'wiki_pages', [], $data + ['project_id' => $projectId, 'audience' => $data['audience'] ?? 'team']);
        return self::text_("Pàgina creada: «{$p['title']}» (número {$p['id']}) · " . $this->wikiUrl((int) $p['id']));
    }

    private function me(): array
    {
        return $this->me ??= $this->api->call('GET', 'me');
    }

    private function webUrl(int $id): string
    {
        return preg_replace('#/index\.php\?r=api.*$#', '', $this->api->url('x')) . '/index.php?r=edit&m=issues&id=' . $id;
    }

    private static function issueList(array $rows, string $title): string
    {
        if (!$rows) {
            return "$title: cap.";
        }
        $out = ["$title (" . count($rows) . '):'];
        foreach ($rows as $r) {
            $d = $r['_display'] ?? [];
            $out[] = '- #' . $r['id'] . ' ' . ($r['title'] ?? '') . ' · ' . implode(' · ', array_filter([
                $d['client_id'] ?? null, $d['status'] ?? null, (int) ($r['priority'] ?? 0) > 0 ? ($d['priority'] ?? null) : null,
            ]));
        }
        return implode("\n", $out);
    }

    /** HTML (richtext) → text llegible. */
    private static function text(string $html): string
    {
        $html = preg_replace(['#<br\s*/?>#i', '#</(p|div|li|h[1-6]|tr)>#i', '#<li[^>]*>#i', '#<img[^>]*alt="([^"]*)"[^>]*>#i', '#<img[^>]*>#i'],
            ["\n", "\n", '- ', '[imatge: $1]', '[imatge]'], $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace("/[ \t]+\n/", "\n", $text)));
    }

    private static function text_(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]]];
    }

    private static function id(array $args): int
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException("Falta el número de la incidència");
        }
        return $id;
    }
}

// ------------------------------------------------------------------------------------------------- bucle JSON-RPC

function send(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}

$tools = ($base !== '' && $token !== '') ? new Tools(new AsteroidApi($base, $token), $filesDir) : null;
if ($tools === null) {
    fwrite(STDERR, "asteroid-mcp: falten ASTEROID_URL i el token (ASTEROID_TOKEN o ~/.config/asteroid-mcp/token)\n");
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $msg = json_decode($line, true);
    if (!is_array($msg) || !isset($msg['method'])) {
        if (is_array($msg) && array_key_exists('id', $msg)) {
            continue; // respuesta del cliente: no esperamos ninguna
        }
        send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);
        continue;
    }
    $id = $msg['id'] ?? null;
    $method = (string) $msg['method'];
    if ($id === null) {
        continue; // notificaciones (notifications/initialized…)
    }
    try {
        $result = match ($method) {
            'initialize' => [
                'protocolVersion' => (string) ($msg['params']['protocolVersion'] ?? MCP_VERSION),
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'asteroid', 'version' => '1.1.0'],
                'instructions' => "Asteroid és el gestor d'incidències d'AFI. Llegeix una incidència amb asteroid_incidencia abans de "
                    . "treballar-hi. No responguis, imputis hores ni canviïs l'estat sense que l'usuari ho hagi confirmat. "
                    . "La wiki (asteroid_wiki, asteroid_wiki_desa) és la documentació dels projectes: abans d'escriure-hi, mira què "
                    . "hi ha al projecte per no repetir pàgines i segueix l'estil de les que ja hi són; demana confirmació abans de desar.",
            ],
            'ping' => new stdClass(),
            'tools/list' => ['tools' => Tools::definitions()],
            'tools/call' => (function () use ($tools, $msg) {
                if ($tools === null) {
                    return ['content' => [['type' => 'text', 'text' => 'Falta el token: desa\'l a ~/.config/asteroid-mcp/token (Asteroid → Perfil → API) o posa ASTEROID_TOKEN.']], 'isError' => true];
                }
                try {
                    return $tools->call((string) ($msg['params']['name'] ?? ''), (array) ($msg['params']['arguments'] ?? []));
                } catch (Throwable $e) {
                    return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
                }
            })(),
            default => throw new BadMethodCallException($method),
        };
        send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    } catch (BadMethodCallException $e) {
        send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $e->getMessage()]]);
    } catch (Throwable $e) {
        send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => $e->getMessage()]]);
    }
}
