<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Acceso a los avisos desde cualquier punto del código (hooks, páginas, scripts):
 *   Notify::send([$userId], ['type' => 'issue.assigned', 'title' => …, 'url' => 'index.php?…']);
 * Ver Notifier.
 */
final class Notify
{
    private static ?\Closure $factory = null;
    private static ?Notifier $notifier = null;

    public static function setFactory(\Closure $factory): void
    {
        self::$factory = $factory;
        self::$notifier = null;
    }

    public static function notifier(): Notifier
    {
        if (self::$notifier === null) {
            if (self::$factory === null) {
                throw new \LogicException('Los avisos no están inicializados (falta App)');
            }
            self::$notifier = (self::$factory)();
        }
        return self::$notifier;
    }

    /** @param list<int> $userIds  @return int avisos creados */
    public static function send(array $userIds, array $notification): int
    {
        return self::notifier()->send($userIds, $notification);
    }
}
