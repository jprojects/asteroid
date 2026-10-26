<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\HttpException;

/**
 * Campana de avisos de la barra superior:
 *   GET  index.php?r=notifications&a=poll[&after=N]   sin leer, últimos avisos y los nuevos desde N (JSON)
 *   POST index.php?r=notifications&a=read[&id=N]      marca uno (o todos) como leídos
 *   GET  index.php?r=notifications&a=open&id=N        lo marca como leído y va a su página
 */
final class NotificationsController extends Controller
{
    private const LIST_SIZE = 20;

    public function handle(): void
    {
        match (is_string($_GET['a'] ?? null) ? $_GET['a'] : '') {
            'poll' => $this->poll(),
            'read' => $this->read(),
            'open' => $this->open(),
            default => throw new HttpException(404),
        };
    }

    private function poll(): void
    {
        $user = $this->user();
        $n = $this->app->notifier();
        $after = max(0, (int) ($_GET['after'] ?? 0));
        $items = array_map(fn (array $r) => $this->present($r), $n->latest($user->id(), self::LIST_SIZE));
        $this->app->json([
            'unread' => $n->unreadCount($user->id()),
            'items' => $items,
            // Los nuevos desde la última consulta (para el aviso flotante y el del escritorio)
            'fresh' => $after > 0 ? array_values(array_filter($items, static fn ($i) => $i['id'] > $after && !$i['read'])) : [],
            'last' => $items[0]['id'] ?? $after,
        ]);
    }

    private function read(): void
    {
        $this->requirePost();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : null;
        $this->app->notifier()->markRead($this->user()->id(), $id ?: null);
        $this->app->json(['ok' => true, 'unread' => $this->app->notifier()->unreadCount($this->user()->id())]);
    }

    private function open(): void
    {
        $user = $this->user();
        $row = $this->app->notifier()->get($user->id(), (int) ($_GET['id'] ?? 0)) ?? throw new HttpException(404);
        $this->app->notifier()->markRead($user->id(), (int) $row['id']);
        $url = (string) ($row['url'] ?? '');
        // Solo rutas internas
        $this->app->redirect(preg_match('/^index\.php\?[^\s\\\\]*$/', $url) ? $url : $this->app->url('home'));
    }

    private function present(array $r): array
    {
        $actor = (string) ($r['actor_name'] ?: $r['actor_username'] ?: '');
        return [
            'id' => (int) $r['id'],
            'type' => (string) $r['type'],
            'title' => (string) $r['title'],
            'body' => $r['body'] !== null ? (string) $r['body'] : null,
            'url' => $this->app->url('notifications', ['a' => 'open', 'id' => (int) $r['id']]),
            'at' => date('c', strtotime((string) $r['created_at'])),
            'read' => $r['read_at'] !== null,
            'actor' => $actor !== '' ? mb_strtoupper(mb_substr($actor, 0, 1)) : null,
        ];
    }
}
