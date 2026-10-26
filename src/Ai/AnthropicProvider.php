<?php
declare(strict_types=1);

namespace Quasar\Ai;

/**
 * Claude (API de Anthropic) por HTTP con cURL, sin dependencias.
 *
 * - Salida estructurada: output_config.format = json_schema (la respuesta siempre cumple el esquema).
 * - Las instrucciones van en "system" con cache_control: las consultas siguientes del mismo listado
 *   leen el prefijo de la caché (más barato) si supera el mínimo cacheable del modelo.
 * - El razonamiento se controla con output_config.effort (los modelos actuales razonan siempre).
 */
final class AnthropicProvider implements LlmProvider
{
    private const URL = 'https://api.anthropic.com';
    private const API_VERSION = '2026-10-21';
    private const MAX_TOKENS = 4096; // incluye el razonamiento; la respuesta en sí son unos cientos de tokens

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $effort = 'low',
        private readonly int $timeout = 20,
        private readonly ?string $baseUrl = null,   // proxy o pasarela propia; por defecto la API de Anthropic
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(string $system, string $user, array $schema): LlmResponse
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => self::MAX_TOKENS,
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $user]],
            'output_config' => [
                'effort' => $this->effort,
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
        ];
        $base = rtrim($this->baseUrl ?? self::URL, '/');
        $ch = curl_init($base . '/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            // Solo HTTPS (la clave viaja en la cabecera), salvo un servidor en esta misma máquina
            CURLOPT_PROTOCOLS => preg_match('~^http://(127\.0\.0\.1|localhost)[:/]~', $base . '/') ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? curl_error($ch) : null;
        curl_close($ch);

        if ($error !== null) {
            throw new LlmException('No se puede conectar con Anthropic: ' . $error);
        }
        $json = json_decode((string) $raw, true);
        if ($code !== 200 || !is_array($json)) {
            $detail = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
            throw new LlmException(match (true) {
                $code === 401 => 'Anthropic: la clave de la API no es válida',
                $code === 429 => 'Anthropic: límite de peticiones alcanzado; inténtalo dentro de un momento',
                $code >= 500 => 'Anthropic: el servicio no está disponible ahora mismo',
                default => "Anthropic: HTTP $code" . ($detail !== '' ? " ($detail)" : ''),
            });
        }

        $u = (array) ($json['usage'] ?? []);
        $usage = static fn (?array $data) => new LlmResponse(
            $data,
            (int) ($u['input_tokens'] ?? 0),
            (int) ($u['output_tokens'] ?? 0),
            (int) ($u['cache_creation_input_tokens'] ?? 0),
            (int) ($u['cache_read_input_tokens'] ?? 0),
        );
        $stop = (string) ($json['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            return $usage(null); // el modelo no quiso responder: se trata como "no entendido"
        }
        if ($stop === 'max_tokens') {
            throw new LlmException('Anthropic: respuesta cortada (max_tokens)', $usage(null));
        }
        // La respuesta puede empezar con bloques de razonamiento: el JSON es el bloque de texto
        foreach ((array) ($json['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $data = json_decode((string) $block['text'], true);
                if (is_array($data)) {
                    return $usage($data);
                }
            }
        }
        throw new LlmException('Anthropic: la respuesta no contiene el JSON esperado', $usage(null));
    }
}
