<?php
declare(strict_types=1);

namespace Quasar\Telegram;

use Quasar\Core\Log;

/**
 * Asteroid · cliente mínimo de la Bot API de Telegram (sin librerías): cada método es un POST con JSON a
 * https://api.telegram.org/bot{token}/{método}. Los fallos se anotan en el log y devuelven null: un aviso a Telegram
 * nunca rompe lo que se está guardando en Asteroid.
 */
final class Bot
{
    private const API = 'https://api.telegram.org/bot';
    private const TIMEOUT = 6;

    /** @param ?string $api otra URL de la API (solo para pruebas; por defecto la de Telegram) */
    public function __construct(private readonly string $token, private readonly ?string $api = null)
    {
    }

    /**
     * @param ?int $timeout segundos (por defecto TIMEOUT; más en las consultas largas de getUpdates)
     * @return array<string,mixed>|null el "result" de Telegram, o null si ha fallado
     */
    public function call(string $method, array $params = [], ?int $timeout = null): ?array
    {
        $timeout ??= self::TIMEOUT;
        $body = json_encode($params, JSON_UNESCAPED_UNICODE);
        $url = ($this->api ?? self::API) . $this->token . '/' . $method;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $raw = curl_exec($ch);
            $error = $raw === false ? curl_error($ch) : null;
        } else {
            $raw = @file_get_contents($url, false, stream_context_create(['http' => [
                'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body,
                'timeout' => $timeout, 'ignore_errors' => true,
            ]]));
            $error = $raw === false ? 'sin respuesta' : null;
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['ok'])) {
            Log::warning('Telegram: {method} ha fallado: {error}', [
                'method' => $method,
                'error' => $error ?? (is_array($data) ? (string) ($data['description'] ?? 'error') : 'respuesta no válida'),
            ]);
            return null;
        }
        return is_array($data['result'] ?? null) ? $data['result'] : ['value' => $data['result'] ?? null];
    }

    /**
     * Mensaje con formato HTML (el texto ya escapado con Telegram::e()).
     * @return ?int message_id
     */
    public function send(int|string $chatId, string $html, ?int $threadId = null, ?int $replyTo = null, ?array $buttons = null): ?int
    {
        $params = ['chat_id' => $chatId, 'text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
        if ($buttons) {
            $params['reply_markup'] = ['inline_keyboard' => $buttons];
        }
        if ($threadId) {
            $params['message_thread_id'] = $threadId;
        }
        if ($replyTo) {
            $params['reply_parameters'] = ['message_id' => $replyTo, 'allow_sending_without_reply' => true];
        }
        $r = $this->call('sendMessage', $params);
        return isset($r['message_id']) ? (int) $r['message_id'] : null;
    }

    /** Cambia el texto de un mensaje del bot (y quita sus botones si no se pasan otros). */
    public function edit(int|string $chatId, int $messageId, string $html, ?array $buttons = null): bool
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
        if ($buttons) {
            $params['reply_markup'] = ['inline_keyboard' => $buttons];
        }
        return $this->call('editMessageText', $params) !== null;
    }

    /** Respuesta a un botón (Telegram deja de mostrar «cargando»); con $alert, en una ventana en vez de un aviso breve. */
    public function answer(string $callbackId, string $text = '', bool $alert = false): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => mb_substr($text, 0, 190), 'show_alert' => $alert]);
    }
}
