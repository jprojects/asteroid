<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\MediaException;
use Quasar\Core\Module;
use Quasar\Google\GoogleException;

/**
 * Conversación de un registro (módulos con "chat"). Todas las rutas llevan m={módulo}&id={registro}:
 *   GET  r=chat&a=poll[&after=N|&before=N][&read=1]   mensajes (los nuevos desde N o los anteriores a N)
 *   POST r=chat&a=post      body, media (ids separados por comas), mentions (ids de usuario)
 *   POST r=chat&a=upload    adjunto en "file"
 *   POST r=chat&a=delete    message
 *   POST r=chat&a=mute      on=1|0
 *   GET  r=chat&a=people    usuarios que se pueden mencionar
 *   POST r=chat&a=meet      crea una videollamada de Google Meet
 */
final class ChatController extends Controller
{
    public function handle(): void
    {
        $action = is_string($_GET['a'] ?? null) ? $_GET['a'] : '';
        if (!in_array($action, ['poll', 'people'], true)) {
            $this->requirePost();
        }
        $m = $this->module('view');
        $id = $this->idParam() ?? throw new HttpException(400);
        $user = $this->user();
        $chat = $this->app->chat();
        $row = $chat->record($m, $id, $user) ?? throw new HttpException(404);

        match ($action) {
            'poll' => $this->poll($m, $id),
            'post' => $this->post($m, $id, $row),
            'upload' => $this->upload($m),
            'delete' => $this->app->json(['ok' => $chat->delete($m, $id, (int) ($_POST['message'] ?? 0), $user)]),
            'mute' => $this->mute($m, $id),
            'people' => $this->app->json(['people' => $chat->people($m, $id, $user)]),
            'meet' => $this->meet($m, $id, $row),
            default => throw new HttpException(404),
        };
    }

    private function poll(Module $m, string $id): void
    {
        $user = $this->user();
        $chat = $this->app->chat();
        $after = max(0, (int) ($_GET['after'] ?? 0));
        $before = max(0, (int) ($_GET['before'] ?? 0));
        $messages = $chat->messages($m, $id, $user, $after, $before);
        $lastRead = $chat->lastRead($m, $id, $user->id());
        if (!empty($_GET['read'])) {
            // El navegador la tiene a la vista: leído hasta el último y "conversación abierta"
            $last = $messages ? (int) end($messages)['id'] : $after;
            $chat->markRead($m, $id, $user->id(), $before > 0 ? 0 : $last);
        }
        $this->app->json([
            'messages' => $messages,
            'reads' => $chat->reads($m, $id, $user),
            'older' => $after === 0 && $messages ? $chat->hasOlder($m, $id, (int) $messages[0]['id']) : null,
            'muted' => $chat->isMuted($m, $id, $user->id()),
            'lastRead' => $lastRead,
        ]);
    }

    private function post(Module $m, string $id, array $row): void
    {
        try {
            $message = $this->app->chat()->post(
                $m,
                $id,
                $row,
                $this->user(),
                is_string($_POST['body'] ?? null) ? $_POST['body'] : '',
                self::idList($_POST['media'] ?? ''),
                self::idList($_POST['mentions'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            $this->app->json(['error' => $this->t($e->getMessage())], 422);
            return;
        }
        $this->app->json(['message' => $message], 201);
    }

    private function upload(Module $m): void
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            throw new HttpException(400);
        }
        try {
            $info = $this->app->chat()->upload($m, $file, $this->user());
            Log::info('{module}: adjunto de conversación "{name}" ({size} bytes)', ['module' => $m->name, 'name' => $info['name'], 'size' => $info['size']]);
            $this->app->json($info, 201);
        } catch (MediaException $e) {
            $this->app->json(['error' => $this->t($e->langKey, $e->vars)], 422);
        }
    }

    private function mute(Module $m, string $id): void
    {
        $on = !empty($_POST['on']);
        $this->app->chat()->setMuted($m, $id, $this->user()->id(), $on);
        $this->app->json(['ok' => true, 'muted' => $on]);
    }

    private function meet(Module $m, string $id, array $row): void
    {
        $google = $this->app->google();
        if (!($m->chatConfig()['meet'] ?? false) || $google === null || $google->connection() === null) {
            throw new HttpException(404);
        }
        try {
            $meeting = $google->createMeeting();
        } catch (GoogleException $e) {
            Log::error('Google Meet: no se ha podido crear la reunión', ['exception' => $e]);
            $this->app->json(['error' => $this->t('chat.meet_failed')], 502);
            return;
        }
        Log::info('{module}: videollamada {uri} en el registro {id}', ['module' => $m->name, 'uri' => $meeting['uri'], 'id' => $id]);
        $this->app->json(['message' => $this->app->chat()->postMeeting($m, $id, $row, $this->user(), $meeting)], 201);
    }

    /** @return list<int> "1,2,3" → [1, 2, 3] */
    private static function idList(mixed $value): array
    {
        return is_string($value) ? array_values(array_filter(array_map('intval', explode(',', $value)), static fn ($i) => $i > 0)) : [];
    }
}
