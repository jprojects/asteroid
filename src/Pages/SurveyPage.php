<?php
declare(strict_types=1);

namespace Quasar\Pages;

use Quasar\Controllers\Page;
use Quasar\Core\Chat;
use Quasar\Core\Log;
use Quasar\Core\Notify;
use Quasar\Hooks\Asteroid;
use Quasar\Hooks\Survey;

/**
 * Asteroid · encuesta de satisfacción (modules/survey.json, página pública: no hace falta iniciar sesión).
 * index.php?r=public&m=survey&t={token}: el token del enlace del aviso identifica la encuesta; vale una vez
 * y caduca. Al responder, apunte en la conversación de la incidencia y aviso al técnico.
 */
final class SurveyPage extends Page
{
    public const QUESTIONS = ['rating', 'speed_rating', 'resolution_rating'];
    private const MAX_COMMENT = 2000;

    public function index(): void
    {
        $token = (string) $this->param('t', '');
        $survey = preg_match('/^[0-9a-f]{40}$/', $token)
            ? $this->app->db->fetch(
                'SELECT s.*, i.title AS issue_title, i.worker_id, u.name AS user_name, u.username, u.locale
                   FROM surveys s JOIN issues i ON i.id = s.issue_id LEFT JOIN users u ON u.id = s.user_id
                  WHERE s.token_hash = ?',
                [hash('sha256', $token)]
            )
            : null;
        // En el idioma de quien la recibe (el enlace se abre sin sesión)
        if ($survey !== null && $survey['locale'] && $this->app->auth->user() === null) {
            $this->app->lang->setLocale((string) $survey['locale']);
        }

        $state = match (true) {
            $survey === null => 'invalid',
            $survey['answered_at'] !== null => 'answered',
            strtotime((string) $survey['expires_at']) < time() => 'expired',
            default => 'open',
        };

        $values = ['comment' => ''];
        $error = null;
        if ($state === 'open' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $this->requirePost();
            foreach (self::QUESTIONS as $q) {
                $v = $_POST[$q] ?? '';
                $values[$q] = is_string($v) && preg_match('/^[1-5]$/', $v) ? (int) $v : null;
            }
            $values['comment'] = is_string($_POST['comment'] ?? null) ? mb_substr(trim($_POST['comment']), 0, self::MAX_COMMENT) : '';
            if (in_array(null, array_intersect_key($values, array_flip(self::QUESTIONS)), true)) {
                $error = $this->text('error_required');
            } else {
                $this->save($survey, $values);
                $this->app->redirect($this->pageUrl('index', ['t' => $token]));
            }
        }

        $this->view([
            'title' => $this->text('title'),
            'state' => $state,
            'survey' => $survey,
            'values' => $values,
            'error' => $error,
            'action' => $this->pageUrl('index', ['t' => $token]),
        ], null, $state === 'invalid' ? 404 : ($error ? 422 : 200));
    }

    private function save(array $survey, array $values): void
    {
        $db = $this->app->db;
        // Solo si nadie la ha respondido mientras tanto (dos pestañas)
        $done = $db->query(
            'UPDATE surveys SET answered_at = NOW(), rating = ?, speed_rating = ?, resolution_rating = ?, comment = ?
              WHERE id = ? AND answered_at IS NULL',
            [$values['rating'], $values['speed_rating'], $values['resolution_rating'], $values['comment'] !== '' ? $values['comment'] : null, $survey['id']]
        )->rowCount();
        if ($done === 0) {
            return;
        }
        $issueId = (string) $survey['issue_id'];
        $name = (string) ($survey['user_name'] ?: $survey['username'] ?: '');
        $stars = Survey::stars((int) $values['rating']);
        Chat::system('issues', $issueId, ['issues:log.survey_answered', ['user' => $name, 'stars' => $stars]], $survey['user_id'] !== null ? (int) $survey['user_id'] : null);
        Notify::send($survey['worker_id'] ? [(int) $survey['worker_id']] : Asteroid::teamIds($db), [
            'type' => 'issue.survey_answered',
            'title' => ['issues:notify.survey_answered', ['record' => '#' . $issueId . ' · ' . $survey['issue_title'], 'stars' => $stars]],
            'body' => $values['comment'] !== '' ? $values['comment'] : ['issues:notify.survey_answered_body', ['user' => $name]],
            'url' => 'index.php?' . http_build_query(['r' => 'edit', 'm' => 'surveys', 'id' => $survey['id']]),
            'module' => 'issues',
            'record' => $issueId,
            'actor' => $survey['user_id'] !== null ? (int) $survey['user_id'] : null,
        ]);
        Log::info('Encuesta {id} de la incidencia {issue} respondida: {rating}', ['id' => $survey['id'], 'issue' => $issueId, 'rating' => $values['rating']]);
    }
}
