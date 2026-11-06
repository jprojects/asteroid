<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\FormHandler;
use Quasar\Core\HtmlSanitizer;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\MediaException;
use Quasar\Fields\MediaField;
use Quasar\Hooks\Asteroid;
use Quasar\MailImport\EmlParser;

/**
 * Asteroid · «Des d'un correu» (modules/issue_from_mail.json, botón en la barra de Incidències): crear una incidencia
 * a partir de un correo. Se arrastra el .eml (Gmail: «Descargar mensaje»; Proton: «Exportar») o se pega el texto;
 * se propone el título (el asunto), el cliente y el proyecto (por el remitente) y la descripción (el correo sin el
 * historial citado), y los adjuntos pasan a la incidencia. El texto del correo se queda en el servidor (borrador): al
 * revisar solo viajan título, proyecto y prioridad, así los enlaces del correo no chocan con el cortafuegos.
 */
final class IssueFromMailPage extends Page
{
    private const DRAFT_TTL = 86400;
    /** Dominios de correo genéricos: no sirven para adivinar el cliente */
    private const GENERIC = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.es', 'outlook.com', 'outlook.es', 'live.com',
        'yahoo.com', 'yahoo.es', 'icloud.com', 'me.com', 'proton.me', 'protonmail.com', 'gmx.com', 'gmx.es', 'aol.com', 'msn.com'];

    public function index(): void
    {
        $this->guard();
        $token = (string) $this->param('draft', '');
        $draft = $token !== '' ? $this->draft($token) : null;
        if ($token !== '' && $draft === null) {
            $this->flash('error', $this->text('expired'));
            $this->app->redirect($this->pageUrl());
        }
        $this->view($draft === null ? [
            'mode' => 'upload',
            'readUrl' => $this->pageUrl('read'),
            'maxUpload' => $this->maxUpload(),
            'issuesUrl' => $this->otherModule('issues')->url(),
        ] : [
            'mode' => 'review',
            'draft' => $draft,
            'token' => $token,
            'projects' => $this->projects((int) ($draft['client_id'] ?? 0)),
            'media' => $draft['media'] ? $this->app->media()->find($draft['media']) : [],
            'createUrl' => $this->pageUrl('create'),
            'backUrl' => $this->pageUrl(),
        ]);
    }

    /** POST: lee el .eml (o el texto pegado), guarda los adjuntos y el borrador, y pasa a revisarlo. */
    public function actionRead(): void
    {
        $this->requirePost();
        $user = $this->guard();
        $file = $_FILES['eml'] ?? null;
        $error = is_array($file) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        $pasted = trim((string) ($_POST['text'] ?? ''));
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            $this->flash('error', $this->text('too_big', ['max' => $this->maxUpload()]));
            $this->app->redirect($this->pageUrl());
        }
        if ($error === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name'])) {
            $mail = EmlParser::parse((string) file_get_contents((string) $file['tmp_name']));
        } elseif ($pasted !== '') {
            $mail = EmlParser::fromText($pasted);
        } else {
            $this->flash('error', $this->text('nothing'));
            $this->app->redirect($this->pageUrl());
        }

        // Adjuntos → archivos de la incidencia (los que no se admiten se dicen)
        $media = [];
        $skipped = [];
        $field = $this->otherModule('issues')->field('attachments');
        foreach ($mail['attachments'] as $a) {
            $tmp = tempnam(sys_get_temp_dir(), 'eml');
            try {
                if ($field instanceof MediaField && strlen($a['data']) > $field->maxSize()) {
                    throw new MediaException('media.too_big');
                }
                file_put_contents($tmp, $a['data']);
                $media[] = (int) $this->app->media()->importFile($tmp, $a['name'], 'issues', 'attachments', $user->id())['id'];
            } catch (MediaException $e) {
                $skipped[] = ['name' => $a['name'], 'reason' => $this->text($e->getMessage() === 'media.type' ? 'skip_type' : 'skip_size')];
            } finally {
                @unlink($tmp);
            }
        }

        $client = $this->guessClient($mail['from_email']);
        $draft = [
            'user' => $user->id(),
            'created' => time(),
            'title' => self::cleanSubject($mail['subject']),
            'from_name' => $mail['from_name'],
            'from_email' => $mail['from_email'],
            'date' => $mail['date'],
            'html' => HtmlSanitizer::clean(EmlParser::bodyHtml($mail)),
            'client_id' => $client['id'] ?? null,
            'client' => $client['name'] ?? null,
            'media' => $media,
            'skipped' => $skipped,
        ];
        $token = bin2hex(random_bytes(12));
        $this->saveDraft($token, $draft);
        Log::info('Correu → incidència: esborrany de {user} ({from}, {n} adjunts)', ['user' => $user->id(), 'from' => $mail['from_email'], 'n' => count($media)]);
        $this->app->redirect($this->pageUrl('index', ['draft' => $token]));
    }

    /** POST: crea la incidencia con el borrador y lo que se ha revisado. */
    public function actionCreate(): void
    {
        $this->requirePost();
        $user = $this->guard();
        $token = (string) $this->param('draft', '');
        $draft = $this->draft($token);
        if ($draft === null) {
            $this->flash('error', $this->text('expired'));
            $this->app->redirect($this->pageUrl());
        }
        $from = trim($draft['from_name'] . ($draft['from_email'] !== '' ? ' <' . $draft['from_email'] . '>' : ''));
        $origin = $from !== '' || $draft['date']
            ? '<p><em>' . htmlspecialchars($this->text('origin', ['from' => $from !== '' ? $from : '—', 'date' => $draft['date'] ? date($this->text('format.datetime'), (int) strtotime($draft['date'])) : '—']), ENT_QUOTES) . '</em></p>'
            : '';
        $values = [
            'title' => mb_substr(trim((string) ($_POST['title'] ?? '')) ?: $draft['title'], 0, 150),
            'project_id' => (string) ($_POST['project_id'] ?? ''),
            'priority' => in_array($_POST['priority'] ?? '0', ['0', '1', '2'], true) ? (string) $_POST['priority'] : '0',
            'description' => $origin . $draft['html'],
            'attachments' => array_values(array_map('intval', $draft['media'])),
            'client_visible' => !empty($_POST['client_visible']) ? '1' : '0',
        ];
        if (!empty($_POST['mine'])) {
            $values['worker_id'] = (string) $user->id();
        }
        $result = (new FormHandler($this->otherModule('issues'), $this->app->db))->process($values, null, $user, true);
        if (!$result['ok']) {
            $this->flash('error', $this->text('failed', ['error' => implode(' · ', $result['errors'])]));
            $this->app->redirect($this->pageUrl('index', ['draft' => $token]));
        }
        @unlink($this->draftFile($token));
        Log::info('Correu → incidència {id} ({user})', ['id' => $result['id'], 'user' => $user->id()]);
        $this->flash('success', $this->text('created', ['id' => $result['id']]));
        $this->app->redirect($this->app->url('edit', ['m' => 'issues', 'id' => $result['id']]));
    }

    // ---------------------------------------------------------------- ayudas

    /** Solo el equipo con permiso de crear incidencias. */
    private function guard(): \Quasar\Core\User
    {
        $user = $this->user();
        if (Asteroid::isClient($user) || !$this->allowed('create', $this->otherModule('issues'))) {
            throw new HttpException(403);
        }
        return $user;
    }

    /** «RE: Fwd: Assumpte» → «Assumpte» */
    private static function cleanSubject(string $subject): string
    {
        $s = trim((string) preg_replace('/^\s*((re|fw|fwd|rv|reenv|tr|aw|wg)\s*(\[\d+\])?\s*:\s*)+/iu', '', $subject));
        return $s !== '' ? $s : trim($subject);
    }

    /**
     * Cliente del remitente: un usuario de cliente con ese correo; el correo de la ficha del cliente; o el dominio
     * (si no es genérico, tipo gmail.com) en los usuarios o la ficha de algún cliente.
     * @return array{id:int,name:string}|null
     */
    private function guessClient(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }
        $db = $this->app->db;
        $sql = [
            ['SELECT c.id, c.name FROM users u JOIN clients c ON c.id = u.client_id WHERE LOWER(u.email) = ? AND c.active = 1 LIMIT 1', $email],
            ['SELECT id, name FROM clients WHERE LOWER(email) = ? AND active = 1 LIMIT 1', $email],
        ];
        $domain = substr($email, strrpos($email, '@') + 1);
        if (!in_array($domain, self::GENERIC, true)) {
            $sql[] = ['SELECT c.id, c.name FROM users u JOIN clients c ON c.id = u.client_id WHERE LOWER(u.email) LIKE ? AND c.active = 1 GROUP BY c.id, c.name ORDER BY COUNT(*) DESC LIMIT 1', '%@' . $domain];
            $sql[] = ['SELECT id, name FROM clients WHERE LOWER(email) LIKE ? AND active = 1 LIMIT 1', '%@' . $domain];
        }
        foreach ($sql as [$q, $arg]) {
            if (($row = $db->fetch($q, [$arg])) !== null) {
                return ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }
        return null;
    }

    /** @return list<array{label:string,options:list<array{id:int,title:string}>}> proyectos activos, los del cliente primero */
    private function projects(int $clientId): array
    {
        $groups = [];
        foreach ($this->app->db->fetchAll(
            'SELECT p.id, p.title, p.client_id, COALESCE(c.name, \'\') AS client FROM projects p LEFT JOIN clients c ON c.id = p.client_id
              WHERE p.active = 1 ORDER BY (p.client_id = ?) DESC, c.name, p.title',
            [$clientId]
        ) as $p) {
            $key = (int) $p['client_id'] === $clientId && $clientId > 0 ? 'suggested' : (string) $p['client'];
            $groups[$key] ??= ['label' => $key === 'suggested' ? $this->text('suggested', ['client' => $p['client']]) : ($p['client'] ?: '—'), 'options' => []];
            $groups[$key]['options'][] = ['id' => (int) $p['id'], 'title' => (string) $p['title']];
        }
        return array_values($groups);
    }

    private function draftFile(string $token): string
    {
        return QS_ROOT . '/storage/cache/mail-drafts/' . preg_replace('/[^a-f0-9]/', '', $token) . '.json';
    }

    private function saveDraft(string $token, array $draft): void
    {
        $dir = dirname($this->draftFile($token));
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se puede crear $dir");
        }
        file_put_contents($this->draftFile($token), json_encode($draft, JSON_UNESCAPED_UNICODE), LOCK_EX);
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - self::DRAFT_TTL) {
                @unlink($f); // borradores abandonados
            }
        }
    }

    /** El borrador, si es de este usuario y no ha caducado. */
    private function draft(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token) || !is_file($this->draftFile($token))) {
            return null;
        }
        $d = json_decode((string) file_get_contents($this->draftFile($token)), true);
        return is_array($d) && (int) ($d['user'] ?? 0) === $this->user()->id() && (int) $d['created'] > time() - self::DRAFT_TTL ? $d : null;
    }

    /** Tamaño máximo de subida de PHP, legible («64 MB»). */
    private function maxUpload(): string
    {
        $bytes = static function (string $v): int {
            $n = (int) $v;
            return match (strtolower(substr(trim($v), -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
        };
        $max = min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')));
        return round($max / 1048576) . ' MB';
    }
}
