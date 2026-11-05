<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Motor de plantillas PHP. Las plantillas viven en templates/{plantilla}/ y
 * acceden a estos helpers mediante $this (e, t, url, asset, csrfField...).
 *
 * "default" es la plantilla base: otra plantilla ('template' en la config) solo contiene lo que cambia, y
 * cualquier vista, correo, asset o icono que no tenga se toma de default. Si trae assets/theme.css, el
 * layout lo carga después de style.css (una plantilla puede ser solo eso).
 *
 * Una plantilla puede sobrescribirse por módulo creando
 * templates/{plantilla}/modules/{módulo}/list.php o form.php.
 */
final class View
{
    public const BASE = 'default';

    /** Iconos que usa app.js (filas de tabla, diálogos, avisos, archivos): siempre van en el sprite */
    private const SCRIPT_ICONS = [
        'pencil', 'eye', 'trash-2', 'x', 'grip-vertical', 'file',
        'circle-help', 'circle-check', 'info', 'triangle-alert', 'circle-alert', 'chevrons-up-down', 'chevron-right',
        'message-circle', 'bell', 'video', 'paperclip', 'download', 'check-check', 'send', 'bell-off', 'at-sign',
        'bold', 'italic', 'underline', 'list', 'list-ordered', 'link', 'quote', 'image', 'remove-formatting',
        'sparkles', 'eye-off',
    ];

    private string $domain = 'common';
    /** @var array<string,string>|null nombre => interior SVG */
    private ?array $iconSet = null;
    /** @var array<string,true> iconos pedidos para el sprite */
    private array $spriteIcons = [];

    public function __construct(private readonly App $app)
    {
    }

    public function setDomain(string $domain): void
    {
        $this->domain = $domain;
    }

    public function dir(): string
    {
        return QS_ROOT . '/templates/' . $this->app->template();
    }

    /**
     * Fichero de la plantilla activa o, si no lo tiene, de la base (default). Ruta relativa a la carpeta de
     * la plantilla ("list.php", "assets/style.css"); null si no existe en ninguna.
     */
    public function file(string $relative): ?string
    {
        foreach (array_unique([$this->dir(), QS_ROOT . '/templates/' . self::BASE]) as $dir) {
            $root = realpath($dir);
            $path = $root ? realpath($root . '/' . $relative) : false;
            if ($path && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public function resolve(string $preferred, string $fallback): string
    {
        return $this->file($preferred . '.php') !== null ? $preferred : $fallback;
    }

    public function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $vars);
        return $layout === null ? $content : $this->renderFile($layout, $vars + ['content' => $content]);
    }

    private function renderFile(string $__template, array $__vars): string
    {
        if (!preg_match('~^[A-Za-z0-9_/-]+$~', $__template) || ($__file = $this->file($__template . '.php')) === null) {
            throw new \RuntimeException("Plantilla no encontrada: $__template");
        }
        extract($__vars, EXTR_SKIP);
        ob_start();
        try {
            include $__file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    // ---------------------------------------------------------------- helpers para plantillas

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function t(string $key, array $vars = []): string
    {
        return $this->app->lang->get($key, $vars, $this->domain);
    }

    /** JSON escapado para un atributo data-* */
    public function json(mixed $data): string
    {
        return $this->e(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function url(string $route, array $params = []): string
    {
        return $this->e($this->app->url($route, $params));
    }

    public function asset(string $file): string
    {
        $path = $this->file('assets/' . $file);
        $v = $path !== null ? filemtime($path) : 0;
        return $this->e($this->app->url('asset', ['f' => $file, 'v' => $v]));
    }

    /** Si la plantilla (o la base) tiene ese asset; p. ej. el layout carga theme.css solo si existe. */
    public function hasAsset(string $file): bool
    {
        return $this->file('assets/' . $file) !== null;
    }

    public function csrfToken(): string
    {
        return Session::csrfToken();
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e(Session::csrfToken()) . '">';
    }

    public function user(): ?User
    {
        return $this->app->auth->user();
    }

    /** @return array<string,list<Module>> */
    public function menu(): array
    {
        $user = $this->user();
        return $user ? $this->app->modules()->menuFor($user) : [];
    }

    public function currentModule(): ?string
    {
        return is_string($_GET['m'] ?? null) ? $_GET['m'] : null;
    }

    public function currentRoute(): string
    {
        return is_string($_GET['r'] ?? null) ? $_GET['r'] : 'home';
    }

    public function flashes(): array
    {
        return Session::takeFlashes();
    }

    // ---------------------------------------------------------------- iconos

    /**
     * Icono SVG (set Lucide, https://lucide.dev/icons, o propio en icons/custom/{nombre}.svg).
     * Si $name no es un icono conocido (p. ej. un emoji de una configuración antigua) se muestra como texto.
     */
    public function icon(string $name, string $class = '', ?string $label = null): string
    {
        $inner = $this->iconInner($name);
        if ($inner === null) {
            return $name === '' ? '' : '<span class="qs-icon-text ' . $this->e($class) . '" aria-hidden="true">' . $this->e($name) . '</span>';
        }
        $a11y = $label !== null ? ' role="img" aria-label="' . $this->e($label) . '"' : ' aria-hidden="true" focusable="false"';
        return '<svg class="qs-icon ' . $this->e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' . $a11y . '>' . $inner . '</svg>';
    }

    public function hasIcon(string $name): bool
    {
        return $this->iconInner($name) !== null;
    }

    /** Pide que un icono esté disponible para app.js (<use href="#qs-i-nombre">). */
    public function useIcon(string $name): void
    {
        if ($this->hasIcon($name)) {
            $this->spriteIcons[$name] = true;
        }
    }

    /** <svg> oculto con los iconos que necesita app.js; va al final del layout. */
    public function iconSprite(): string
    {
        $symbols = '';
        foreach (array_unique([...self::SCRIPT_ICONS, ...array_keys($this->spriteIcons)]) as $name) {
            $inner = $this->iconInner($name);
            if ($inner !== null) {
                $symbols .= '<symbol id="qs-i-' . $this->e($name) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                    . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</symbol>';
            }
        }
        return '<svg class="qs-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" hidden>' . $symbols . '</svg>';
    }

    private function iconInner(string $name): ?string
    {
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $name)) {
            return null;
        }
        $custom = $this->file('icons/custom/' . $name . '.svg');
        if ($custom !== null && preg_match('~<svg\b[^>]*>(.*)</svg>~s', (string) file_get_contents($custom), $m)) {
            return trim($m[1]);
        }
        return $this->iconSet()[$name] ?? null;
    }

    /** @return array<string,string> iconos Lucide de la plantilla (o de la base): nombre => interior SVG */
    public function iconSet(): array
    {
        if ($this->iconSet === null) {
            $file = $this->file('icons/lucide.php');
            $this->iconSet = $file !== null ? (array) require $file : [];
        }
        return $this->iconSet;
    }

    /** Barra lateral plegada (cookie que guarda app.js; así se pinta ya plegada, sin parpadeo). */
    /**
     * Botones de alta rápida de la barra superior ("quickCreate" de los módulos) que puede usar el usuario.
     * En la ficha de un módulo de su "context", el alta se rellena con ese registro.
     * @return list<array{label:string,icon:string,url:string}>
     */
    public function quickCreates(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }
        $out = [];
        $current = $this->currentModule();
        $id = $this->currentRoute() === 'edit' && is_string($_GET['id'] ?? null) ? $_GET['id'] : null;
        $record = null; // el registro abierto, solo si algún "context" pide otros campos además del id
        foreach ($this->app->modules()->all() as $m) {
            $q = $m->isPage() ? null : $m->quickCreate();
            if ($q === null || !ModuleRegistry::canAccess($user, $m, 'create')) {
                continue;
            }
            $params = ['m' => $m->name, 'modal' => 1];
            if ($id !== null && $current !== null && isset($q['context'][$current])) {
                $values = [];
                foreach ($q['context'][$current] as $field => $from) {
                    if ($from === 'id') {
                        $values[$field] = $id;
                        continue;
                    }
                    $record ??= $this->currentRecord($current, $id);
                    $value = $record[$from] ?? null;
                    if (is_scalar($value) && (string) $value !== '') {
                        $values[$field] = (string) $value;
                    }
                }
                if ($values) {
                    $params['v'] = $values;
                }
            }
            $out[] = ['label' => $q['label'], 'icon' => $q['icon'], 'url' => $this->app->url('edit', $params)];
        }
        return $out;
    }

    /**
     * Valores del registro abierto (los campos que el usuario puede ver, con su "scope"), para rellenar las altas
     * rápidas que piden más que su id. [] si no existe o no lo ve.
     * @return array<string,mixed>
     */
    private function currentRecord(string $module, string $id): array
    {
        $m = $this->app->modules()->get($module);
        if ($m === null || $m->isPage()) {
            return [];
        }
        $row = (new FormHandler($m, $this->app->db))->load($id) ?? [];
        return array_intersect_key($row, array_filter($m->fields(), static fn ($f) => $f->canView()));
    }

    /** Lo que necesita la campana de avisos de la barra superior (app.js). */
    public function notificationsConfig(): array
    {
        $cfg = (array) ($this->app->config['notifications'] ?? []);
        return [
            'poll' => $this->app->url('notifications', ['a' => 'poll']),
            'read' => $this->app->url('notifications', ['a' => 'read']),
            'seconds' => max(10, (int) ($cfg['poll_seconds'] ?? 30)),
            'i18n' => [
                'empty' => $this->t('notifications.empty'),
                'desktopOn' => $this->t('notifications.desktop_on'),
                'desktopDenied' => $this->t('notifications.desktop_denied'),
                'now' => $this->t('notifications.now'),
                'minutes' => $this->t('notifications.minutes'),
                'hours' => $this->t('notifications.hours'),
                'days' => $this->t('notifications.days'),
            ],
        ];
    }

    public function sidebarCollapsed(): bool
    {
        return ($_COOKIE['qs_sidebar'] ?? '') === 'collapsed';
    }

    /**
     * Si un grupo del menú se pinta plegado: los plegados van en la cookie qs_nav (nombres separados por comas), pero
     * el grupo del módulo abierto se muestra siempre desplegado.
     *
     * @param list<Module> $modules los módulos del grupo
     */
    public function navGroupClosed(string $group, array $modules): bool
    {
        $closed = explode(',', (string) ($_COOKIE['qs_nav'] ?? ''));
        if (!in_array($group, $closed, true)) {
            return false;
        }
        foreach ($modules as $m) {
            if ($m->name === $this->currentModule()) {
                return false;
            }
        }
        return true;
    }

    /** Textos de los diálogos y avisos de app.js (Quasar.dialog / Quasar.toast). */
    public function dialogTexts(): array
    {
        $out = [];
        foreach (['ok', 'cancel', 'close', 'confirmTitle', 'alertTitle', 'errorTitle', 'deleteTitle', 'unsavedTitle', 'unsaved', 'leave', 'stay', 'showPassword', 'hidePassword', 'capsLock'] as $key) {
            $out[$key] = $this->app->lang->get('dialog.' . $key);
        }
        return $out;
    }

    /** URL del logo del cliente (config branding.logo) o null. */
    public function logoUrl(): ?string
    {
        $logo = $this->app->branding()['logo'];
        return $logo !== null ? $this->e($this->app->url('logo', ['v' => filemtime($logo)])) : null;
    }

    /** Color principal del cliente (#rrggbb) o null. */
    /** Clase del logo: sin la placa blanca si branding.logo_plate es false */
    public function logoClass(string $base): string
    {
        return $this->app->branding()['logo_plate'] ? $base : $base . ' is-bare';
    }

    public function brandColor(): ?string
    {
        return $this->app->branding()['color'];
    }

    public function appName(): string
    {
        return (string) ($this->app->config['app_name'] ?? 'Quasar');
    }

    /** Versión del núcleo (fichero VERSION). */
    public function version(): string
    {
        return Version::current();
    }

    public function locale(): string
    {
        return $this->app->lang->locale();
    }

    /** @return array<string,string> */
    public function locales(): array
    {
        return (array) ($this->app->config['locales'] ?? []);
    }
}
