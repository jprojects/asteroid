<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\ApiTokens;
use Quasar\Core\DataQuery;
use Quasar\Core\FormHandler;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\Module;
use Quasar\Core\ModuleRegistry;
use Quasar\Core\User;
use Quasar\Fields\CheckboxField;
use Quasar\Fields\DateTimeField;
use Quasar\Fields\DurationField;
use Quasar\Fields\Field;
use Quasar\Fields\MediaField;
use Quasar\Fields\NumberField;
use Quasar\Fields\PasswordField;
use Quasar\Fields\SelectField;

/**
 * API REST para apps y bots: /api/v1/… (o index.php?r=api&p=v1/…). JSON de entrada y de salida.
 * Se autentica con un token personal (cabecera "Authorization: Bearer qs_…"), que se crea en el perfil
 * o con POST /api/v1/auth/token. Cada petición actúa como el usuario del token: mismos permisos, "scope",
 * campos con "access", hooks y avisos que en la web. Referencia completa en el README ("API").
 *
 * Los errores son {"error": "mensaje", "code": "…"} (y "fields" en los de validación).
 */
final class ApiController extends Controller
{
    public const VERSION = 'v1';
    private const MAX_PER_PAGE = 200;
    private const INLINE_OPTIONS = 100;

    /** Ruta pedida (sin "v1/"), p. ej. ["issues", "12", "messages"] */
    private array $path = [];
    private string $method = 'GET';
    private bool $pretty = false;

    public function handle(string $path, bool $pretty): void
    {
        $this->pretty = $pretty;
        $this->method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        // Los delegados (chat, archivos, acciones) responden en JSON
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->cors();
        if ($this->method === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        try {
            if (empty($this->app->config['api']['enabled'])) {
                throw new HttpException(404, 'api.disabled');
            }
            $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn ($p) => $p !== ''));
            if (($parts[0] ?? '') !== self::VERSION) {
                throw new HttpException(404, 'api.unknown_version');
            }
            $this->path = array_slice($parts, 1);
            $this->route();
        } catch (HttpException $e) {
            $this->error($e->status, $this->app->lang->get($e->getMessage()), self::codeFor($e));
        } catch (\Throwable $e) {
            Log::error('API: excepción no controlada: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $this->error(500, $this->app->lang->get('errors.500'), 'server_error');
        }
    }

    private function route(): void
    {
        $p = $this->path;
        $n = count($p);
        if ($n === 0) {
            $this->only('GET');
            $this->app->json([
                'name' => (string) ($this->app->config['app_name'] ?? 'Quasar'),
                'version' => \Quasar\Core\Version::current(),
                'api' => self::VERSION,
            ]);
            return;
        }
        if ($p === ['auth', 'token']) {
            $this->method === 'POST' ? $this->createToken() : $this->revokeToken();
            return;
        }

        $user = $this->authenticate();

        match (true) {
            $p === ['me'] => $this->only('GET') ?? $this->me($user),
            $p === ['modules'] => $this->only('GET') ?? $this->modules($user),
            $n === 2 && $p[0] === 'modules' => $this->only('GET') ?? $this->schema($this->tableModule($p[1], 'view')),
            $p === ['notifications'] => $this->only('GET') ?? $this->notifications($user),
            $p === ['notifications', 'read'] => $this->only('POST') ?? $this->readNotifications($user),
            $n === 2 && $p[0] === 'files' => $this->only('GET') ?? $this->file($p[1]),
            $n === 2 && $p[0] === 'pages' => $this->only('GET') ?? $this->page($p[1]),
            $n === 1 => $this->collection($p[0]),
            $n === 3 && $p[1] === 'options' => $this->only('GET') ?? $this->options($p[0], $p[2]),
            $n === 3 && $p[1] === 'files' => $this->only('POST') ?? $this->upload($p[0], $p[2]),
            $n === 3 && $p[1] === 'actions' => $this->only('POST') ?? $this->action($p[0], $p[2], null),
            $n === 2 => $this->item($p[0], $p[1]),
            $n === 3 && $p[2] === 'messages' => $this->messages($p[0], $p[1]),
            $n === 4 && $p[2] === 'messages' && $p[3] === 'files' => $this->only('POST') ?? $this->chatUpload($p[0], $p[1]),
            $n === 4 && $p[2] === 'actions' => $this->only('POST') ?? $this->action($p[0], $p[3], $p[1]),
            default => throw new HttpException(404),
        };
    }

    // ---------------------------------------------------------------- autenticación

    /** Token de la cabecera Authorization (o X-Api-Key); 401 si falta o no vale. */
    private function authenticate(): User
    {
        $token = $this->bearer() ?? throw new HttpException(401, 'api.unauthorized');
        $auth = (new ApiTokens($this->app->db))->authenticate($token) ?? throw new HttpException(401, 'api.unauthorized');
        $this->app->auth->actAs($auth['user'], (int) $auth['token']['id']);
        $user = $this->user();
        Log::withContext(['user' => $user->id(), 'r' => 'api', 'token' => (int) $auth['token']['id']]);
        $lang = $_GET['lang'] ?? null;
        $locales = (array) ($this->app->config['locales'] ?? []);
        $this->app->lang->setLocale(is_string($lang) && isset($locales[$lang]) ? $lang : ($user->locale() ?? (string) ($this->app->config['locale'] ?? 'es')));
        return $user;
    }

    private function bearer(): ?string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }
        $key = $_SERVER['HTTP_X_API_KEY'] ?? null;
        return is_string($key) && $key !== '' ? $key : null;
    }

    /** POST auth/token {login, password, name?}: un token nuevo (inicio de sesión de una app). */
    private function createToken(): void
    {
        $in = $this->input();
        $login = is_string($in['login'] ?? null) ? trim($in['login']) : '';
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';
        $result = $login === '' || $password === ''
            ? ['error' => 'login.invalid', 'row' => null]
            : $this->app->auth->verifyCredentials($login, $password);
        $row = $result['row'];
        if ($row === null) {
            Log::warning('API: login fallido para "{login}": {reason}', ['login' => $login, 'reason' => $result['error']]);
            $key = $result['error'] === 'login.invalid' && $this->app->auth->loginField() !== 'username'
                ? 'login.invalid_' . $this->app->auth->loginField() : (string) $result['error'];
            $code = ['login.invalid' => 'invalid_credentials', 'login.locked' => 'locked', 'login.inactive' => 'inactive'][$result['error']] ?? 'invalid_credentials';
            $this->error($result['error'] === 'login.locked' ? 429 : 401, $this->app->lang->get($key), $code);
            return;
        }
        // La verificación en dos pasos necesita la web: el token se crea desde el perfil
        if ($this->app->twoFactor()->appliesTo($row)) {
            $this->error(403, $this->app->lang->get('api.two_factor'), 'two_factor_required');
            return;
        }
        $this->app->db->update('users', ['failed_logins' => 0, 'locked_until' => null, 'last_login' => date('Y-m-d H:i:s')], 'id', $row['id']);
        $name = is_string($in['name'] ?? null) && trim($in['name']) !== '' ? $in['name'] : 'API';
        $days = (int) ($this->app->config['api']['token_days'] ?? 0);
        $token = (new ApiTokens($this->app->db))->create((int) $row['id'], $name, $days ?: null);
        $this->app->auth->actAs($row, $token['id']);
        Log::info('API: token "{name}" creado para {username}', ['name' => $name, 'username' => $row['username']]);
        $this->app->json(['token' => $token['token'], 'expires_at' => self::iso($token['expires_at']), 'user' => $this->userInfo($this->user())], 201);
    }

    /** DELETE auth/token: revoca el token con el que se hace la petición (cerrar sesión en la app). */
    private function revokeToken(): void
    {
        $this->only('DELETE');
        $user = $this->authenticate();
        (new ApiTokens($this->app->db))->revoke($user->id(), (int) $this->app->auth->apiTokenId());
        Log::info('API: token revocado');
        $this->app->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- usuario y módulos

    private function me(User $user): void
    {
        $modules = [];
        foreach ($this->app->modules()->all() as $m) {
            if (ModuleRegistry::canAccess($user, $m)) {
                $modules[] = $this->moduleInfo($m, $user);
            }
        }
        $this->app->json($this->userInfo($user) + [
            'unread_notifications' => $this->app->notifier()->unreadCount($user->id()),
            'modules' => $modules,
        ]);
    }

    private function modules(User $user): void
    {
        $out = [];
        foreach ($this->app->modules()->all() as $m) {
            if (ModuleRegistry::canAccess($user, $m)) {
                $out[] = $this->moduleInfo($m, $user);
            }
        }
        $this->app->json(['data' => $out]);
    }

    private function userInfo(User $user): array
    {
        return [
            'id' => $user->id(),
            'username' => $user->username(),
            'name' => $user->name(),
            'email' => $user->email(),
            'locale' => $user->locale(),
            'is_admin' => $user->isAdmin(),
            'groups' => $user->groups(),
        ];
    }

    private function moduleInfo(Module $m, User $user): array
    {
        $info = [
            'name' => $m->name,
            'title' => $m->title(),
            'type' => $m->isPage() ? 'page' : 'table',
            'group' => $m->menuGroup(),
            'icon' => $m->icon(),
            'in_menu' => $m->inMenu() && $m->menuAllows($user),
            'actions' => array_values(array_filter($m->actions(), static fn ($a) => ModuleRegistry::canAccess($user, $m, $a))),
        ];
        if ($m->isPage()) {
            // Si la página implementa api() (sin ejecutarla: puede ser una consulta pesada)
            $info['api'] = (new \ReflectionMethod((string) $m->pageController(), 'api'))->getDeclaringClass()->getName() !== Page::class;
        } else {
            $info['chat'] = $m->chatConfig() !== null && $m->chatAllows($user);
        }
        return $info;
    }

    /** GET modules/{m}: campos, filtros, orden y búsqueda del módulo, tal como los ve el usuario. */
    private function schema(Module $m): void
    {
        $editable = [];
        foreach ($m->formFields() as $f) {
            $editable[$f->name] = !$f->isReadonly();
        }
        $filters = array_map(static fn ($f) => $f['field']->name, $m->filters());
        $fields = [];
        foreach ($this->viewableFields($m) as $f) {
            $fields[] = $this->fieldInfo($m, $f, $editable[$f->name] ?? false, in_array($f->name, $filters, true));
        }
        $this->app->json([
            'name' => $m->name,
            'title' => $m->title(),
            'primary_key' => $m->primaryKey(),
            'fields' => $fields,
            'list' => array_map(static fn ($c) => $c['field']->name, $m->listColumns()),
            'sortable' => array_values(array_map(static fn ($c) => $c['field']->name, array_filter($m->listColumns(), static fn ($c) => $c['sortable']))),
            'default_sort' => $m->defaultSort(),
            'search' => array_map(static fn (Field $f) => $f->name, $m->searchFields()),
            'filters' => array_map(static fn ($f) => ['field' => $f['field']->name, 'operator' => $f['operator'], 'label' => $f['label']], $m->filters()),
            'per_page' => $m->pageSize(),
            'chat' => $m->chatConfig() !== null && $m->chatAllows($this->user()),
            // Acciones propias que se pueden ejecutar por la API (las que descargan un fichero, no)
            'actions' => array_values(array_map(static fn ($a) => ['name' => $a['action'], 'label' => $a['label'], 'place' => $a['place']],
                array_filter(array_merge($m->toolbarButtons(), $m->rowActions(), $m->formButtons()), static fn ($a) => $a['action'] !== null && !$a['download']))),
        ]);
    }

    /** Las opciones de un select solo si se puede rellenar o filtrar por él (como en la web). */
    private function fieldInfo(Module $m, Field $f, bool $editable, bool $isFilter): array
    {
        $info = [
            'name' => $f->name,
            'type' => $f->type(),
            'label' => $f->label(),
            'required' => $f->isRequired(true),
            'editable' => $editable,
        ];
        if ($f instanceof SelectField) {
            $info['multiple'] = $f->isMultiple();
            if (($dep = $f->dependency('form')) !== null) {
                $info['depends_on'] = $dep['field'];
            }
            if (!$editable && !$isFilter) {
                // sin opciones: el valor del registro lleva su texto en "_display"
            } elseif ($f->searchMode() !== 'remote' && count($f->options()) <= self::INLINE_OPTIONS) {
                $info['options'] = array_map(static fn ($v, $l) => ['value' => self::scalar($v), 'label' => $l], array_keys($f->options()), $f->options());
            } else {
                $info['options_url'] = $this->apiUrl($m->name . '/options/' . $f->name);
            }
        } elseif ($f instanceof MediaField) {
            $info['multiple'] = $f->multiple();
            $info['max_files'] = $f->maxFiles();
            $info['max_size'] = $f->maxSize();
            $info['accept'] = $f->accept();
            $info['upload_url'] = $this->apiUrl($m->name . '/files/' . $f->name);
        }
        $help = $f->help();
        return $info + ($help !== null ? ['help' => $help] : []);
    }

    // ---------------------------------------------------------------- registros

    /** GET {m}: listado · POST {m}: alta */
    private function collection(string $name): void
    {
        if ($this->method === 'POST') {
            $m = $this->tableModule($name, 'create');
            $this->save($m, null);
            return;
        }
        $this->only('GET');
        $m = $this->tableModule($name, 'view');

        // Campos: ?fields=a,b · ?fields=all · por defecto las columnas del listado
        $viewable = [];
        foreach ($this->viewableFields($m) as $f) {
            if ($f->sqlExpr() !== null || $f->computeFunction() !== null) {
                $viewable[$f->name] = $f;
            }
        }
        $wanted = is_string($_GET['fields'] ?? null) ? trim($_GET['fields']) : '';
        if ($wanted === 'all') {
            $fields = $viewable;
        } elseif ($wanted !== '') {
            $fields = [];
            foreach (explode(',', $wanted) as $fname) {
                $fname = trim($fname);
                $fields[$fname] = $viewable[$fname] ?? throw new HttpException(400, 'api.unknown_field');
            }
        } else {
            $fields = array_intersect_key($viewable, array_flip(array_map(static fn ($c) => $c['field']->name, $m->listColumns())));
        }

        $perPage = (int) ($_GET['per_page'] ?? $m->pageSize());
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $columns = array_map(static fn (Field $f) => ['field' => $f], array_values($fields));
        $result = (new DataQuery($m, $this->app->db))->page($_GET, $columns, $perPage, (int) ($_GET['page'] ?? 1));

        $this->app->json([
            'data' => array_map(fn (array $row) => $this->present($m, $fields, $row, (string) $row['__pk'], true), $result['rows']),
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'pages' => $result['pages'],
                'per_page' => $result['size'],
                'sort' => $result['sort'],
                'dir' => $result['dir'],
            ],
        ]);
    }

    /** GET/PATCH/DELETE {m}/{id} */
    private function item(string $name, string $id): void
    {
        self::checkId($id);
        match ($this->method) {
            'GET' => $this->app->json($this->detail($this->tableModule($name, 'view'), $id)),
            'PATCH', 'PUT' => $this->save($this->tableModule($name, 'edit'), $id),
            'DELETE' => $this->delete($this->tableModule($name, 'delete'), $id),
            default => throw new HttpException(405),
        };
    }

    /** Registro completo con los campos que ve el usuario, o 404 si no existe o queda fuera de su "scope". */
    private function detail(Module $m, string $id): array
    {
        $row = (new FormHandler($m, $this->app->db))->load($id) ?? throw new HttpException(404);
        $fields = [];
        foreach ($this->viewableFields($m) as $f) {
            $fields[$f->name] = $f;
        }
        $out = $this->present($m, $fields, $row, $id, false);

        // Archivos de los campos media, con su URL de descarga en la API
        $files = [];
        foreach ($fields as $f) {
            if ($f instanceof MediaField && ($ids = $f->mediaIds($row[$f->name] ?? null))) {
                $files[$f->name] = array_map(fn (array $r) => $this->apiFile($this->app->media()->info($r)), $this->app->media()->find($ids));
            }
        }
        if ($files) {
            $out['_files'] = $files;
        }
        if ($m->chatConfig() !== null && $m->chatAllows($this->user())) {
            [$total, $unread] = $this->app->chat()->counts($m, [$id], $this->user())[$id] ?? [0, 0];
            $out['_chat'] = ['messages' => $total, 'unread' => $unread];
        }
        return $out;
    }

    /**
     * Valores en JSON: números como número, checkbox como booleano, selects múltiples y archivos como
     * listas. "_display" lleva el texto que se ve en la web cuando es distinto del valor (etiquetas, fechas…).
     * @param array<string,Field> $fields
     */
    private function present(Module $m, array $fields, array $row, string $id, bool $fromDb): array
    {
        $out = ['id' => ctype_digit($id) ? (int) $id : $id];
        $display = [];
        foreach ($fields as $name => $f) {
            if ($name === $m->primaryKey()) {
                continue;
            }
            $value = $row[$name] ?? null;
            if ($fromDb) {
                $value = $f->fromDb($value);
            }
            $out[$name] = match (true) {
                $value === null || $value === '' => $f instanceof SelectField && $f->isMultiple() ? [] : null,
                $f instanceof CheckboxField => (bool) $value,
                $f instanceof DateTimeField => self::iso($value),
                $f instanceof MediaField => $f->toJson($value),
                $f instanceof NumberField && is_numeric($value) => str_contains((string) $value, '.') ? (float) $value : (int) $value,
                $f instanceof SelectField => is_array($value) ? array_map([self::class, 'scalar'], $value) : self::scalar($value),
                default => $value,
            };
            $text = $f->format($value);
            if ($value !== null && $value !== '' && $value !== [] && (is_array($value) || $text !== (string) $value)) {
                $display[$name] = $text;
            }
        }
        if ($display) {
            $out['_display'] = $display;
        }
        return $out;
    }

    /** Alta (POST) o modificación parcial (PATCH: solo los campos que se envían). */
    private function save(Module $m, ?string $id): void
    {
        $form = new FormHandler($m, $this->app->db);
        if ($id !== null && $form->load($id) === null) {
            throw new HttpException(404);
        }
        [$input, $unknown] = $this->formInput($m, $this->input());
        if ($unknown) {
            $this->error(422, $this->app->lang->get('api.validation'), 'validation', $unknown);
            return;
        }
        $result = $form->process($input, $id, $this->user(), true);
        if (!$result['ok']) {
            $errors = $result['errors'];
            $message = $errors['_form'] ?? $this->app->lang->get('api.validation');
            unset($errors['_form']);
            $this->error(422, $message, 'validation', $errors);
            return;
        }
        Log::info('{module}: registro {id} {action} (API)', ['module' => $m->name, 'id' => $result['id'], 'action' => $id === null ? 'creado' : 'modificado']);
        $this->app->json($this->detail($m, (string) $result['id']), $id === null ? 201 : 200);
    }

    private function delete(Module $m, string $id): void
    {
        $user = $this->user();
        if (!$m->isVisible($id)) {
            throw new HttpException(404);
        }
        $hooks = $m->hooks($this->app->db);
        $error = $hooks?->beforeDelete($id, $user);
        if ($error === null) {
            try {
                if ($this->app->db->delete($m->table(), $m->primaryKey(), $id) === 0) {
                    throw new HttpException(404);
                }
                $hooks?->afterDelete($id, $user);
                Log::info('{module}: registro {id} eliminado (API)', ['module' => $m->name, 'id' => $id]);
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $error = 'errors.in_use';
            }
        }
        $error === null ? $this->app->json(['ok' => true]) : $this->error(409, $m->t($error), 'conflict');
    }

    /**
     * Cuerpo de la petición → valores como los enviaría el formulario web. Números, booleanos y null
     * se convierten a texto; una duración numérica son minutos (en la web, "1,5" son horas).
     * @return array{0:array<string,mixed>,1:array<string,string>} valores y campos desconocidos
     */
    private function formInput(Module $m, array $data): array
    {
        $fields = $m->fields();
        $out = [];
        $unknown = [];
        foreach ($data as $name => $value) {
            $f = is_string($name) ? ($fields[$name] ?? null) : null;
            if ($f === null || !$f->canView() || $f instanceof PasswordField && $m->name !== 'users') {
                $unknown[(string) $name] = $this->app->lang->get('api.unknown_field');
                continue;
            }
            if ($f instanceof DurationField && (is_int($value) || is_float($value))) {
                $out[$name] = (int) round($value) . 'm';
                continue;
            }
            $out[$name] = self::toText($value);
        }
        return [$out, $unknown];
    }

    /** "12" → 12 (ids y códigos numéricos); el resto, como texto. */
    private static function scalar(mixed $value): int|string
    {
        $s = (string) $value;
        return preg_match('/^(0|-?[1-9]\d{0,17})$/', $s) ? (int) $s : $s;
    }

    private static function toText(mixed $value): mixed
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => array_map([self::class, 'toText'], $value),
            default => $value,
        };
    }

    // ---------------------------------------------------------------- opciones, archivos, conversación, acciones

    /** GET {m}/options/{campo}?q=&parent= */
    private function options(string $module, string $field): void
    {
        $this->tableModule($module, 'view');
        $this->delegate(fn () => (new OptionsController($this->app))->index(), ['m' => $module, 'field' => $field] + $this->query(['q', 'parent', 'value']),
            [], static fn (array $d) => isset($d['results']) ? ['results' => array_map(
                static fn ($r) => is_array($r) ? ['value' => self::scalar($r['value'])] + $r : $r, $d['results'])] : $d);
    }

    /** POST {m}/files/{campo} (multipart, archivo en "file"): sube un archivo para ponerlo después en el campo. */
    private function upload(string $module, string $field): void
    {
        $this->tableModule($module, 'view');
        $this->delegate(fn () => (new MediaController($this->app))->upload(), ['m' => $module, 'field' => $field]);
    }

    /** GET files/{id}[?thumb=1][&download=1] */
    private function file(string $id): void
    {
        $_GET = ['id' => $id] + $this->query(['thumb', 'download']);
        (new MediaController($this->app))->show();
    }

    /** GET {m}/{id}/messages?after=&before=&read=1 · POST {m}/{id}/messages {body, media?: [ids]} */
    private function messages(string $module, string $id): void
    {
        self::checkId($id);
        $this->tableModule($module, 'view');
        if ($this->method === 'POST') {
            $in = $this->input();
            $media = is_array($in['media'] ?? null) ? implode(',', array_map('intval', $in['media'])) : '';
            $mentions = is_array($in['mentions'] ?? null) ? implode(',', array_map('intval', $in['mentions'])) : '';
            $this->delegate(fn () => (new ChatController($this->app))->handle(), ['m' => $module, 'id' => $id, 'a' => 'post'],
                ['body' => is_string($in['body'] ?? null) ? $in['body'] : '', 'media' => $media, 'mentions' => $mentions]);
            return;
        }
        $this->only('GET');
        $this->delegate(fn () => (new ChatController($this->app))->handle(), ['m' => $module, 'id' => $id, 'a' => 'poll'] + $this->query(['after', 'before', 'read']));
    }

    /** POST {m}/{id}/messages/files (multipart, "file"): adjunto para un mensaje (su id va en "media"). */
    private function chatUpload(string $module, string $id): void
    {
        self::checkId($id);
        $this->tableModule($module, 'view');
        $this->delegate(fn () => (new ChatController($this->app))->handle(), ['m' => $module, 'id' => $id, 'a' => 'upload']);
    }

    /** POST {m}/actions/{acción} (barra) o {m}/{id}/actions/{acción} (registro): acciones propias del módulo. */
    private function action(string $module, string $name, ?string $id): void
    {
        if ($id !== null) {
            self::checkId($id);
        }
        $m = $this->tableModule($module, 'view');
        $button = $m->action($name) ?? throw new HttpException(404);
        if (!empty($button['download'])) {
            throw new HttpException(404, 'api.download_action'); // generan un fichero para el navegador
        }
        $this->delegate(fn () => (new ActionController($this->app))->run(), ['m' => $module, 'a' => $name], ($id !== null ? ['_id' => $id] : []) + $this->input());
    }

    // ---------------------------------------------------------------- avisos y páginas

    /** GET notifications?after=&limit=&unread=1 */
    private function notifications(User $user): void
    {
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
        $sql = 'SELECT n.id, n.type, n.module, n.record_id, n.title, n.body, n.url, n.created_at, n.read_at, a.name AS actor
                  FROM notifications n LEFT JOIN users a ON a.id = n.actor_id
                 WHERE n.user_id = ? AND n.id > ?' . (!empty($_GET['unread']) ? ' AND n.read_at IS NULL' : '') . "
                 ORDER BY n.id DESC LIMIT $limit";
        $rows = $this->app->db->fetchAll($sql, [$user->id(), max(0, (int) ($_GET['after'] ?? 0))]);
        $this->app->json([
            'data' => array_map(fn (array $r) => [
                'id' => (int) $r['id'],
                'type' => $r['type'],
                'module' => $r['module'],
                'record_id' => $r['record_id'],
                'title' => $r['title'],
                'body' => $r['body'],
                'actor' => $r['actor'],
                'web_url' => $r['url'] !== null ? $this->app->absoluteUrl((string) $r['url']) : null,
                'created_at' => self::iso($r['created_at']),
                'read_at' => self::iso($r['read_at']),
            ], $rows),
            'unread' => $this->app->notifier()->unreadCount($user->id()),
        ]);
    }

    /** POST notifications/read {id?}: uno o, sin id, todos. */
    private function readNotifications(User $user): void
    {
        $id = $this->input()['id'] ?? null;
        $this->app->notifier()->markRead($user->id(), is_numeric($id) ? (int) $id : null);
        $this->app->json(['ok' => true, 'unread' => $this->app->notifier()->unreadCount($user->id())]);
    }

    /** GET pages/{m}: datos de una página personalizada que los ofrezca (Page::api()). */
    private function page(string $name): void
    {
        $m = $this->app->modules()->get($name);
        if ($m === null || !$m->isPage()) {
            throw new HttpException(404);
        }
        $this->authorize($m, 'view');
        $this->app->view->setDomain($m->name);
        $data = $this->pageController($m)->api() ?? throw new HttpException(404, 'api.no_page_api');
        $this->app->json($data);
    }

    private function pageController(Module $m): Page
    {
        $class = (string) $m->pageController();
        return new $class($this->app, $m);
    }

    // ---------------------------------------------------------------- utilidades

    /** Módulo de tabla con permiso para la acción (404 si no existe o es una página, 403 sin permiso). */
    private function tableModule(string $name, string $action): Module
    {
        $m = $this->app->modules()->get($name);
        if ($m === null || $m->isPage()) {
            throw new HttpException(404);
        }
        $this->authorize($m, $action);
        $this->app->view->setDomain($m->name);
        return $m;
    }

    /** @return list<Field> campos que el usuario puede ver (nunca contraseñas) */
    private function viewableFields(Module $m): array
    {
        return array_values(array_filter($m->fields(), static fn (Field $f) => $f->canView() && !$f instanceof PasswordField));
    }

    /** Cuerpo JSON (o formulario) de la petición. */
    private function input(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($type, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            $data = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($data) || ($data !== [] && array_is_list($data))) {
                throw new HttpException(400, 'api.bad_json');
            }
            return $cache = $data;
        }
        if ($this->method !== 'POST' && !str_contains($type, 'multipart/')) {
            parse_str((string) file_get_contents('php://input'), $data);
            return $cache = $data;
        }
        return $cache = $_POST;
    }

    /** Parámetros GET que se pasan a un controlador delegado. */
    private function query(array $names): array
    {
        return array_intersect_key($_GET, array_flip($names));
    }

    /**
     * Ejecuta un controlador de la web con estos parámetros y reescribe su JSON: las URL de archivos
     * apuntan a la API y los errores llevan "code".
     */
    private function delegate(callable $run, array $get, array $post = [], ?\Closure $transform = null): void
    {
        $_GET = $get;
        $_POST = $post;
        ob_start();
        try {
            $run();
        } finally {
            $body = (string) ob_get_clean();
        }
        $status = http_response_code() ?: 200;
        $data = json_decode($body, true);
        if (!is_array($data)) {
            echo $body;
            return;
        }
        $data = $this->rewriteUrls($data);
        if ($transform !== null && $status < 400) {
            $data = $transform($data);
        }
        if ($status >= 400 && is_string($data['error'] ?? null) && !isset($data['code'])) {
            $data['code'] = self::codeForStatus($status);
        }
        $this->app->json($data, $status);
    }

    /** "index.php?r=media&id=5&thumb=1" → "{api}/files/5?thumb=1" en todo el árbol. */
    private function rewriteUrls(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $data[$k] = $this->rewriteUrls($v);
            } elseif (is_string($v) && str_starts_with($v, 'index.php?r=media&')) {
                parse_str(substr($v, strlen('index.php?')), $q);
                $data[$k] = $this->apiUrl('files/' . (int) ($q['id'] ?? 0), array_intersect_key($q, ['thumb' => 1, 'download' => 1]));
            }
        }
        return $data;
    }

    private function apiFile(array $info): array
    {
        return $this->rewriteUrls($info);
    }

    /** URL absoluta de un recurso de la API, con la misma forma con la que ha llegado la petición. */
    private function apiUrl(string $path, array $params = []): string
    {
        $path = self::VERSION . '/' . ltrim($path, '/');
        if ($this->pretty) {
            return $this->app->absoluteUrl('api/' . $path . ($params ? '?' . http_build_query($params) : ''));
        }
        return $this->app->absoluteUrl('index.php?' . http_build_query(['r' => 'api', 'p' => $path] + $params));
    }

    /** null si el método es el esperado (para encadenar con ??); si no, 405. */
    private function only(string $method): mixed
    {
        if ($this->method !== $method) {
            header('Allow: ' . $method);
            throw new HttpException(405);
        }
        return null;
    }

    private static function checkId(string $id): void
    {
        if ($id === '' || strlen($id) > 64) {
            throw new HttpException(400);
        }
    }

    private function cors(): void
    {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        $allowed = (array) ($this->app->config['api']['cors'] ?? []);
        if ($origin !== '' && (in_array('*', $allowed, true) || in_array($origin, $allowed, true))) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
            header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key');
            header('Access-Control-Max-Age: 600');
        }
    }

    private function error(int $status, string $message, string $code, array $fields = []): void
    {
        $this->app->json(['error' => $message, 'code' => $code] + ($fields ? ['fields' => $fields] : []), $status);
    }

    private static function codeFor(HttpException $e): string
    {
        $key = $e->getMessage();
        return str_starts_with($key, 'api.') ? substr($key, 4) : self::codeForStatus($e->status);
    }

    private static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'too_large',
            422 => 'validation',
            429 => 'locked',
            503 => 'maintenance',
            default => 'error',
        };
    }

    private static function iso(mixed $datetime): ?string
    {
        return $datetime === null || $datetime === '' ? null : date('c', (int) strtotime((string) $datetime));
    }
}
