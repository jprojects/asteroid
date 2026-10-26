<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Acceso a las conversaciones desde los hooks y scripts:
 *   Chat::system('issues', $id, 'Estado: Resuelta', $user->id());   apunte en la conversación del registro
 * Ver ChatService.
 */
final class Chat
{
    private static ?\Closure $factory = null;
    private static ?ChatService $service = null;

    public static function setFactory(\Closure $factory): void
    {
        self::$factory = $factory;
        self::$service = null;
    }

    public static function service(): ChatService
    {
        if (self::$service === null) {
            if (self::$factory === null) {
                throw new \LogicException('Las conversaciones no están inicializadas (falta App)');
            }
            self::$service = (self::$factory)();
        }
        return self::$service;
    }

    /** @param string|array{0:string,1?:array} $text texto o [clave de idioma, variables] */
    public static function system(string $module, string $id, string|array $text, ?int $actorId = null): int
    {
        return self::service()->system($module, $id, $text, $actorId);
    }
}
