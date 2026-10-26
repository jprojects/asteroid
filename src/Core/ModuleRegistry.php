<?php
declare(strict_types=1);

namespace Quasar\Core;

final class ModuleRegistry
{
    /** @var array<string,Module>|null */
    private ?array $modules = null;

    /**
     * @param \Closure(string):FieldContext $contextFactory
     * @param \Closure(string):bool|null $features si una función opcional de la instalación está activa ("ai")
     */
    public function __construct(
        private readonly string $dir,
        private readonly \Closure $contextFactory,
        private readonly ?\Closure $features = null,
    ) {
    }

    /** @return array<string,Module> ordenados por menu.order */
    public function all(): array
    {
        if ($this->modules === null) {
            $modules = [];
            foreach (glob($this->dir . '/*.json') ?: [] as $file) {
                $m = Module::fromFile($file, $this->contextFactory);
                // "feature": "ai" → el módulo solo existe si la instalación tiene esa función
                $feature = $m->config('feature');
                if ($feature !== null && !($this->features !== null && ($this->features)((string) $feature))) {
                    continue;
                }
                $modules[$m->name] = $m;
            }
            // "requires": ["activities"] → solo si existen esos módulos (p. ej. un calendario sin su origen)
            do {
                $removed = false;
                foreach ($modules as $name => $m) {
                    foreach ((array) $m->config('requires', []) as $required) {
                        if (!isset($modules[(string) $required])) {
                            unset($modules[$name]);
                            $removed = true;
                            continue 2;
                        }
                    }
                }
            } while ($removed);
            uasort($modules, static fn (Module $a, Module $b) => [$a->menuOrder(), $a->name] <=> [$b->menuOrder(), $b->name]);
            $this->modules = $modules;
        }
        return $this->modules;
    }

    public function get(string $name): ?Module
    {
        return preg_match('/^[a-z0-9_]+$/', $name) ? ($this->all()[$name] ?? null) : null;
    }

    public static function canAccess(User $user, Module $m, string $action = 'view'): bool
    {
        return (!$m->isAdminOnly() || $user->isAdmin())
            && in_array($action, $m->actions(), true)
            && $user->can($m->name, $action);
    }

    /** @return array<string,list<Module>> grupo => módulos visibles en el menú para el usuario */
    public function menuFor(User $user): array
    {
        $groups = [];
        foreach ($this->all() as $m) {
            if ($m->inMenu() && $m->menuAllows($user) && self::canAccess($user, $m)) {
                $groups[$m->menuGroup()][] = $m;
            }
        }
        // "admin" siempre al final
        if (isset($groups['admin'])) {
            $admin = $groups['admin'];
            unset($groups['admin']);
            $groups['admin'] = $admin;
        }
        return $groups;
    }
}
