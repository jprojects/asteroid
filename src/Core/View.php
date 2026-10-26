<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Motor de plantillas PHP. Las plantillas viven en templates/{plantilla}/ y
 * acceden a estos helpers mediante $this (e, t, url, asset, csrfField...).
 *
 * Una plantilla puede sobrescribirse por módulo creando
 * templates/{plantilla}/modules/{módulo}/list.php o form.php.
 */
final class View
{
    /** Iconos que usa app.js (filas de tabla, diálogos, avisos, archivos): siempre van en el sprite */
    private const SCRIPT_ICONS = [
        'pencil', 'eye', 'trash-2', 'x', 'grip-vertical', 'file',
        'circle-help', 'circle-check', 'info', 'triangle-alert', 'circle-alert', 'chevrons-up-down', 'chevron-right',
        'message-circle', 'bell', 'video', 'paperclip', 'download', 'check-check', 'send', 'bell-off', 'at-sign',
        'bold', 'italic', 'underline', 'list', 'list-ordered', 'link', 'quote', 'image', 'remove-formatting',
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

    public function resolve(string $preferred, string $fallback): string
    {
        return is_file($this->dir() . '/' . $preferred . '.php') ? $preferred : $fallback;
    }

    public function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $vars);
        return $layout === null ? $content : $this->renderFile($layout, $vars + ['content' => $content]);
    }

    private function renderFile(string $__template, array $__vars): string
    {
        if (!preg_match('~^[A-Za-z0-9_/-]+$~', $__template) || !is_file($__file = $this->dir() . '/' . $__template . '.php')) {
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
        $path = $this->dir() . '/assets/' . $file;
        $v = is_file($path) ? filemtime($path) : 0;
        return $this->e($this->app->url('asset', ['f' => $file, 'v' => $v]));
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
        $custom = $this->dir() . '/icons/custom/' . $name . '.svg';
        if (is_file($custom) && preg_match('~<svg\b[^>]*>(.*)</svg>~s', (string) file_get_contents($custom), $m)) {
            return trim($m[1]);
        }
        if ($this->iconSet === null) {
            $file = $this->dir() . '/icons/lucide.php';
            if (!is_file($file)) {
                $file = QS_ROOT . '/templates/default/icons/lucide.php';
            }
            $this->iconSet = is_file($file) ? (array) require $file : [];
        }
        return $this->iconSet[$name] ?? null;
    }

    /** Barra lateral plegada (cookie que guarda app.js; así se pinta ya plegada, sin parpadeo). */
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

    /** Textos de los diálogos y avisos de app.js (Quasar.dialog / Quasar.toast). */
    public function dialogTexts(): array
    {
        $out = [];
        foreach (['ok', 'cancel', 'close', 'confirmTitle', 'alertTitle', 'errorTitle', 'deleteTitle', 'unsavedTitle', 'unsaved', 'leave', 'stay'] as $key) {
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
