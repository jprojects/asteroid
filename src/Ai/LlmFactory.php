<?php
declare(strict_types=1);

namespace Quasar\Ai;

/** Crea el proveedor de la configuración 'ai'. */
final class LlmFactory
{
    /** Si la instalación tiene la IA activada y con clave. */
    public static function enabled(array $config): bool
    {
        return !empty($config['enabled']) && (string) ($config['api_key'] ?? '') !== '';
    }

    /** @param array<string,mixed> $config config['ai'] */
    public static function make(array $config): LlmProvider
    {
        $key = (string) ($config['api_key'] ?? '');
        if ($key === '') {
            throw new LlmException('Falta ai.api_key en la configuración');
        }
        return match ((string) ($config['provider'] ?? 'anthropic')) {
            'anthropic' => new AnthropicProvider(
                $key,
                (string) ($config['model'] ?? 'claude-haiku-5-5'),
                in_array($config['effort'] ?? 'low', ['low', 'medium', 'high'], true) ? $config['effort'] : 'low',
                max(5, (int) ($config['timeout'] ?? 20)),
                isset($config['base_url']) ? (string) $config['base_url'] : null,
            ),
            default => throw new LlmException('Proveedor de IA desconocido: ' . $config['provider']),
        };
    }
}
