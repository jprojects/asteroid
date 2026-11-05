<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Ai\LlmException;
use Quasar\Ai\LlmFactory;
use Quasar\Ai\Usage;
use Quasar\Builder\BuilderAi;
use Quasar\Builder\ColumnProfile;
use Quasar\Builder\Importer;
use Quasar\Builder\ModuleSpec;
use Quasar\Builder\SpreadsheetReader;
use Quasar\Controllers\Page;
use Quasar\Core\Database;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\Module;
use Quasar\Core\Session;

/**
 * Constructor de módulos (solo administradores, modules/builder.json): a partir de una descripción, de un
 * Excel/CSV o de ambas, la IA propone la tabla, el listado y el formulario; el administrador la revisa,
 * pide cambios o edita el JSON, y al confirmar Quasar crea la tabla (CREATE TABLE generado por Quasar,
 * nunca por la IA), escribe modules/{nombre}.json y lang/{idioma}/{nombre}.json e importa las filas.
 *
 * La propuesta en curso vive en la sesión; el fichero subido, en storage/builder/ hasta crear o descartar.
 */
final class BuilderPage extends Page
{
    private const SESSION = 'builder';
    private const MAX_FILE = 20 * 1024 * 1024;
    private const SAMPLE_ROWS = 15;

    public function index(): void
    {
        $this->requireAdmin();
        $draft = Session::get(self::SESSION);
        $spec = $errors = null;
        $sql = null;
        if (is_array($draft)) {
            ['spec' => $spec, 'errors' => $errors] = $this->specs()->normalize($draft['spec']);
            $sql = $errors === [] ? $this->specs()->createSql($spec) : null;
        }
        $this->view([
            'draft' => is_array($draft) ? $draft : null,
            'spec' => $spec,
            'errors' => array_map(fn ($e) => $this->text($e[0], $e[1]), $errors ?? []),
            'sql' => $sql,
            'json' => is_array($draft) ? ModuleSpec::encode($draft['spec']) : '',
            'notWritable' => $this->notWritable(),
            'aiEnabled' => LlmFactory::enabled((array) ($this->app->config['ai'] ?? [])),
        ]);
    }

    /** POST q (descripción) y/o file (xlsx, csv): la IA propone el módulo. */
    public function actionPropose(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $description = trim((string) $this->param('q', ''));
        $file = null;
        try {
            $file = $this->storeUpload();
        } catch (\InvalidArgumentException $e) {
            $this->back('error', $this->text('file.' . $e->getMessage()));
        }
        if ($description === '' && $file === null) {
            $this->back('error', $this->text('ask.empty'));
        }
        $profile = $sample = [];
        $rows = 0;
        if ($file !== null) {
            try {
                $data = SpreadsheetReader::read($file['path'], $file['ext']);
            } catch (\InvalidArgumentException $e) {
                @unlink($file['path']);
                $this->back('error', $this->text('file.' . $e->getMessage()));
            }
            $profile = ColumnProfile::profile($data['headers'], $data['rows'], $file['ext'] === 'xlsx');
            $sample = array_slice($data['rows'], 0, self::SAMPLE_ROWS);
            $rows = count($data['rows']);
            $file += ['headers' => $data['headers'], 'rows' => $rows, 'truncated' => $data['truncated']];
        }
        $this->discardFile();
        $ai = $this->ai();
        $spec = $this->askAi($ai->proposeMessage(mb_substr($description, 0, 4000), $profile, $sample, $rows), $ai, 'Constructor: propuesta');
        if ($spec === null) {
            if ($file !== null) {
                @unlink($file['path']);
            }
            $this->app->redirect($this->pageUrl());
        }
        $note = $spec['note'] ?? null;
        unset($spec['note']);
        Session::set(self::SESSION, ['spec' => $spec, 'note' => $note, 'prompt' => $description, 'file' => $file]);
        $this->app->redirect($this->pageUrl());
    }

    /** POST instruction: la IA corrige la propuesta en curso. */
    public function actionRefine(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $draft = $this->draft();
        $instruction = trim((string) $this->param('instruction', ''));
        if ($instruction === '') {
            $this->back('error', $this->text('refine.empty'));
        }
        $ai = $this->ai();
        $spec = $this->askAi($ai->refineMessage($draft['spec'], mb_substr($instruction, 0, 2000), $draft['file']['headers'] ?? []), $ai, 'Constructor: cambio');
        if ($spec !== null) {
            $draft['note'] = $spec['note'] ?? null;
            unset($spec['note']);
            $draft['spec'] = $spec;
            Session::set(self::SESSION, $draft);
        }
        $this->app->redirect($this->pageUrl());
    }

    /** POST spec (JSON): el administrador edita la propuesta a mano. */
    public function actionEdit(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $draft = $this->draft();
        try {
            $spec = json_decode((string) $this->param('spec', ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->back('error', $this->text('json_invalid', ['error' => $e->getMessage()]));
        }
        if (!is_array($spec)) {
            $this->back('error', $this->text('json_invalid', ['error' => '']));
        }
        $draft['spec'] = $spec;
        Session::set(self::SESSION, $draft);
        $this->back('success', $this->text('flash.edited'));
    }

    /** POST: descarta la propuesta y el fichero subido. */
    public function actionDiscard(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        $this->discardFile();
        Session::remove(self::SESSION);
        $this->back('success', $this->text('flash.discarded'));
    }

    /** POST: crea la tabla, los ficheros del módulo y, si hay fichero, importa las filas. */
    public function actionCreate(): void
    {
        $this->requireAdmin();
        $this->requirePost();
        @set_time_limit(300);
        $draft = $this->draft();
        $specs = $this->specs();
        ['spec' => $spec, 'errors' => $errors] = $specs->normalize($draft['spec']);
        if ($errors) {
            $this->back('error', $this->text('flash.has_errors'));
        }
        if ($this->notWritable()) {
            $this->back('error', $this->text('not_writable', ['dirs' => implode(', ', $this->notWritable())]));
        }
        $name = $spec['name'];
        $moduleFile = QS_ROOT . '/modules/' . $name . '.json';
        $langFiles = [];
        foreach (ModuleSpec::LOCALES as $locale) {
            $langFiles[$locale] = QS_ROOT . '/lang/' . $locale . '/' . $name . '.json';
            if (is_file($langFiles[$locale])) {
                $this->back('error', $this->text('errors.lang_exists', ['file' => "lang/$locale/$name.json"]));
            }
        }

        // El módulo se valida como lo hará Quasar al cargarlo, antes de tocar nada
        $def = $specs->moduleDef($spec);
        try {
            new Module($name, $def, ($this->app->fieldContextFactory())($name));
        } catch (\Throwable $e) {
            Log::error('Constructor: el módulo generado no es válido: {error}', ['error' => $e->getMessage(), 'module' => $name]);
            $this->back('error', $this->text('flash.invalid_module', ['error' => $e->getMessage()]));
        }

        $sql = $specs->createSql($spec);
        $written = [];
        try {
            $this->app->db->pdo()->exec($sql);
        } catch (\PDOException $e) {
            Log::error('Constructor: CREATE TABLE {table}: {error}', ['table' => $name, 'error' => $e->getMessage(), 'sql' => $sql]);
            $this->back('error', $this->text('flash.sql_error', ['error' => $e->getMessage()]));
        }
        try {
            foreach ($langFiles as $locale => $path) {
                $this->writeFile($path, ModuleSpec::encode($specs->langFile($spec, $locale, $this->autoTexts($locale))));
                $written[] = $path;
            }
            // El JSON del módulo, el último: con él el módulo ya aparece
            $this->writeFile($moduleFile, ModuleSpec::encode($def));
            $written[] = $moduleFile;
        } catch (\RuntimeException $e) {
            foreach ($written as $path) {
                @unlink($path);
            }
            $this->app->db->pdo()->exec('DROP TABLE ' . Database::ident($name));
            Log::error('Constructor: no se han podido escribir los ficheros de {module}: {error}', ['module' => $name, 'error' => $e->getMessage()]);
            $this->back('error', $this->text('not_writable', ['dirs' => $e->getMessage()]));
        }
        Log::info('Constructor: módulo {module} creado por {user}', ['module' => $name, 'user' => $this->user()->name(), 'sql' => $sql]);

        $messages = [$this->text('flash.created', ['module' => $spec['title'][$this->app->lang->locale()] ?? $name])];
        $warn = false;
        $file = $draft['file'] ?? null;
        if ($file !== null && is_file($file['path'])) {
            try {
                $data = SpreadsheetReader::read($file['path'], $file['ext']);
                $result = (new Importer($this->app->db, $file['ext'] === 'xlsx'))->import($spec, $data['headers'], $data['rows']);
                $messages[] = $this->text('flash.imported', ['n' => (string) $result['rows']]);
                $labels = array_column(array_map(fn ($f) => ['n' => $f['name'], 'l' => $f['label'][$this->app->lang->locale()] ?? $f['name']], $spec['fields']), 'l', 'n');
                foreach ($result['unrecognized'] as $field => $n) {
                    $messages[] = $this->text('flash.unrecognized', ['n' => (string) $n, 'field' => $labels[$field] ?? $field]);
                    $warn = true;
                }
                if ($result['truncated'] > 0) {
                    $messages[] = $this->text('flash.truncated', ['n' => (string) $result['truncated']]);
                    $warn = true;
                }
                Log::info('Constructor: {rows} filas importadas en {module}', ['rows' => $result['rows'], 'module' => $name, 'unrecognized' => $result['unrecognized']]);
            } catch (\Throwable $e) {
                Log::error('Constructor: importación en {module}: {error}', ['module' => $name, 'error' => $e->getMessage()]);
                $messages[] = $this->text('flash.import_error', ['error' => $e->getMessage()]);
                $warn = true;
            }
        }
        $this->discardFile();
        Session::remove(self::SESSION);
        $this->flash($warn ? 'warning' : 'success', implode(' ', $messages));
        $this->app->redirect($this->app->url('list', ['m' => $name]));
    }

    // ---------------------------------------------------------------- piezas

    private function requireAdmin(): void
    {
        if (!$this->user()->isAdmin()) {
            throw new HttpException(403);
        }
    }

    private function back(string $type, string $message): never
    {
        $this->flash($type, $message);
        $this->app->redirect($this->pageUrl());
    }

    private function draft(): array
    {
        $draft = Session::get(self::SESSION);
        if (!is_array($draft)) {
            $this->back('error', $this->text('no_draft'));
        }
        return $draft;
    }

    private function specs(): ModuleSpec
    {
        $icons = array_keys($this->app->view->iconSet());
        return new ModuleSpec($this->app->db, QS_ROOT . '/modules', $icons);
    }

    private function ai(): BuilderAi
    {
        $tables = [];
        foreach ($this->app->modules()->all() as $m) {
            if ($m->isPage()) {
                continue;
            }
            // Columna que se muestra: name, title… si existe; si no, el primer campo de texto
            $label = null;
            foreach ($m->fields() as $f) {
                if (in_array($f->type(), ['text', 'email'], true) && $f->isStored()) {
                    if (in_array($f->column(), ['name', 'title', 'label', 'nombre', 'nom', 'titulo'], true)) {
                        $label = $f->column();
                        break;
                    }
                    $label ??= $f->column();
                }
            }
            if ($label !== null) {
                $tables[] = ['table' => $m->table(), 'label' => $label, 'module' => $m->name];
            }
        }
        return new BuilderAi($tables);
    }

    /** Llama a la IA con el esquema de ModuleSpec; null (con aviso) si falla. */
    private function askAi(string $message, BuilderAi $prompt, string $logPrompt): ?array
    {
        $ai = (array) ($this->app->config['ai'] ?? []);
        if (!LlmFactory::enabled($ai)) {
            throw new HttpException(404);
        }
        $user = $this->user();
        $usage = new Usage($this->app->db, $ai);
        if ($usage->state()['state'] === 'blocked') {
            $this->flash('error', $this->t('ai.limit_reached'));
            return null;
        }
        if ($usage->userOverRate($user->id())) {
            $this->flash('error', $this->t('ai.too_many'));
            return null;
        }
        @set_time_limit(240);
        $cfg = (array) ($this->app->config['builder'] ?? []);
        $model = (string) ($cfg['model'] ?? 'claude-sonnet-5-5');
        $provider = LlmFactory::make([
            'model' => $model,
            'effort' => $cfg['effort'] ?? 'medium',
            'max_tokens' => (int) ($cfg['max_tokens'] ?? 16000),
            'timeout' => (int) ($cfg['timeout'] ?? 180),
        ] + $ai);
        $log = ['user_id' => $user->id(), 'module' => $this->module->name, 'provider' => $provider->name(), 'model' => $model, 'prompt' => $logPrompt];
        $start = hrtime(true);
        try {
            $response = $provider->complete($prompt->system(), $message, $prompt->responseSchema());
        } catch (LlmException $e) {
            Log::error('Constructor IA: {error}', ['error' => $e->getMessage()]);
            $usage->log($log + ['status' => 'error', 'result' => $e->getMessage(), 'duration_ms' => self::ms($start)], $e->usage);
            $this->flash('error', $this->t('ai.error'));
            return null;
        }
        $data = $response->data;
        $usage->log($log + ['status' => is_array($data) ? 'ok' : 'error', 'result' => $data, 'duration_ms' => self::ms($start)], $response);
        if (!is_array($data) || empty($data['fields'])) {
            $this->flash('error', $this->text('ai_failed'));
            return null;
        }
        return $data;
    }

    /**
     * Guarda el fichero subido en storage/builder/ (fuera de la web).
     * @return array{path:string,name:string,ext:string}|null
     */
    private function storeUpload(): ?array
    {
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || (int) $f['size'] > self::MAX_FILE) {
            throw new \InvalidArgumentException('too_big');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
            throw new \InvalidArgumentException('upload');
        }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv', 'txt'], true)) {
            throw new \InvalidArgumentException('format');
        }
        $dir = QS_ROOT . '/storage/builder';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            throw new \InvalidArgumentException('upload');
        }
        // Ficheros de propuestas abandonadas (más de un día)
        foreach (glob($dir . '/*') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        $path = $dir . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file((string) $f['tmp_name'], $path)) {
            throw new \InvalidArgumentException('upload');
        }
        return ['path' => $path, 'name' => mb_substr(basename((string) $f['name']), 0, 150), 'ext' => $ext];
    }

    private function discardFile(): void
    {
        $draft = Session::get(self::SESSION);
        $path = is_array($draft) ? ($draft['file']['path'] ?? null) : null;
        if (is_string($path) && str_starts_with($path, QS_ROOT . '/storage/builder/')) {
            @unlink($path);
        }
    }

    /** @return list<string> carpetas en las que no se puede escribir */
    private function notWritable(): array
    {
        $dirs = ['modules', ...array_map(static fn ($l) => "lang/$l", ModuleSpec::LOCALES)];
        return array_values(array_filter($dirs, static fn ($d) => !is_writable(QS_ROOT . '/' . $d)));
    }

    private function writeFile(string $path, string $content): void
    {
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw new \RuntimeException(substr($path, strlen(QS_ROOT) + 1));
        }
    }

    /** @return array{id:string,created_at:string} */
    private function autoTexts(string $locale): array
    {
        $file = QS_ROOT . '/lang/' . $locale . '/builder.json';
        $texts = is_file($file) ? (array) (json_decode((string) file_get_contents($file), true)['auto'] ?? []) : [];
        return ['id' => (string) ($texts['id'] ?? 'ID'), 'created_at' => (string) ($texts['created_at'] ?? 'created_at')];
    }

    private static function ms(int $start): int
    {
        return (int) ((hrtime(true) - $start) / 1_000_000);
    }
}
