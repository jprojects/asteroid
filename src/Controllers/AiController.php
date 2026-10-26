<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Ai\LlmException;
use Quasar\Ai\LlmFactory;
use Quasar\Ai\NaturalFilter;
use Quasar\Ai\Usage;
use Quasar\Core\HttpException;
use Quasar\Core\Log;

/**
 * Filtrar un listado con lenguaje natural:
 *   POST index.php?r=ask&m={módulo}   q = frase
 * Responde {ok, params: {f, q?, sort?, dir?, labels?}, note?} o {error} (429 límite, 502 proveedor).
 */
final class AiController extends Controller
{
    private const MAX_PHRASE = 300;

    public function ask(): void
    {
        $this->requirePost();
        $m = $this->module('view');
        $config = (array) ($this->app->config['ai'] ?? []);
        if (!LlmFactory::enabled($config) || $m->aiConfig() === null) {
            throw new HttpException(404);
        }
        $phrase = is_string($_POST['q'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $_POST['q'])) : '';
        if ($phrase === '' || mb_strlen($phrase) > self::MAX_PHRASE) {
            throw new HttpException(400);
        }
        $user = $this->user();
        $usage = new Usage($this->app->db, $config);
        if ($usage->state()['state'] === 'blocked') {
            $this->app->json(['error' => $this->t('ai.limit_reached')], 429);
            return;
        }
        if ($usage->userOverRate($user->id())) {
            $this->app->json(['error' => $this->t('ai.too_many')], 429);
            return;
        }

        $filter = new NaturalFilter($m, $config, $this->app->lang->locale());
        $system = $filter->system();
        $message = $filter->userMessage(mb_strtolower($phrase), new \DateTimeImmutable('now'));
        $schema = $filter->schema();
        $model = (string) ($config['model'] ?? '');
        // La misma frase con las mismas instrucciones (módulo, permisos, idioma) y el mismo día: misma respuesta
        $hash = hash('sha256', json_encode([$model, $system, $message, $schema], JSON_UNESCAPED_UNICODE));
        $log = [
            'user_id' => $user->id(),
            'module' => $m->name,
            'provider' => (string) ($config['provider'] ?? 'anthropic'),
            'model' => $model,
            'prompt' => $phrase,
        ];
        $start = hrtime(true);
        $ms = static fn () => (int) ((hrtime(true) - $start) / 1_000_000);

        $response = null;
        $data = $usage->cacheGet($hash);
        $cached = $data !== null;
        if (!$cached) {
            try {
                $response = LlmFactory::make($config)->complete($system, $message, $schema);
            } catch (LlmException $e) {
                Log::error('IA: {error}', ['error' => $e->getMessage(), 'module' => $m->name]);
                $usage->log($log + ['status' => 'error', 'result' => $e->getMessage(), 'duration_ms' => $ms()], $e->usage);
                $this->app->json(['error' => $this->t('ai.error')], 502);
                return;
            }
            $data = $response->data ?? ['understood' => false];
        }

        $result = $filter->toParams($data);
        if ($result['understood'] && !$cached) {
            $usage->cachePut($hash, $data);
        }
        $note = $result['note'];
        if ($result['unresolved']) {
            $missing = $this->t('ai.unresolved', ['values' => implode(', ', $result['unresolved'])]);
            $note = $note !== null ? "$note $missing" : $missing;
        }
        $usage->log($log + [
            'status' => $cached ? 'cached' : ($result['understood'] ? 'ok' : 'not_understood'),
            'result' => $result['understood'] ? $result['params'] + ($note !== null ? ['note' => $note] : []) : $note,
            'duration_ms' => $ms(),
        ], $response);

        $this->app->json(['ok' => $result['understood'], 'params' => $result['params']] + ($note !== null ? ['note' => $note] : []));
    }
}
