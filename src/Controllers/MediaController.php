<?php
declare(strict_types=1);

namespace Quasar\Controllers;

use Quasar\Core\ChatService;
use Quasar\Core\HttpException;
use Quasar\Core\Log;
use Quasar\Core\MediaException;
use Quasar\Core\ModuleRegistry;
use Quasar\Fields\MediaField;
use Quasar\Fields\RichTextField;

/**
 * Subida (AJAX desde la dropzone) y descarga de archivos del campo media.
 */
final class MediaController extends Controller
{
    /** POST index.php?r=upload&m={módulo}&field={ruta.del.campo}  (archivo en "file") */
    public function upload(): void
    {
        // Si el cuerpo supera post_max_size, PHP vacía $_POST y $_FILES: avisamos del tamaño, no del CSRF
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > 0 && $_POST === [] && $_FILES === []) {
            $this->app->json(['error' => $this->t('media.too_large', ['max' => ini_get('post_max_size')])], 413);
            return;
        }
        $this->requirePost();

        $m = $this->module('view');
        if (!$this->can($m, 'create') && !$this->can($m, 'edit')) {
            throw new HttpException(403);
        }
        $path = $_GET['field'] ?? '';
        $field = is_string($path) ? $m->fieldByPath($path) : null;
        if ($field instanceof RichTextField && !$field->isReadonly()) {
            $field = $field->imageField(); // imágenes del editor de texto con formato
        }
        if (!$field instanceof MediaField || $field->isReadonly()) {
            throw new HttpException(404);
        }
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            throw new HttpException(400);
        }

        try {
            $info = $this->app->media()->store($file, $m->name, $field, $this->user()->id());
            Log::info('{module}: archivo subido "{name}" ({size} bytes) en {field}', [
                'module' => $m->name, 'name' => $info['name'], 'size' => $info['size'], 'field' => $field->path(), 'media' => $info['id'],
            ]);
            $this->app->json($info, 201);
        } catch (MediaException $e) {
            Log::notice('{module}: subida rechazada "{name}": {reason}', [
                'module' => $m->name, 'name' => (string) ($file['name'] ?? ''), 'reason' => $e->langKey,
            ]);
            $this->app->json(['error' => $this->t($e->langKey, $e->vars)], 422);
        }
    }

    /** GET index.php?r=media&id={id}[&thumb=1][&download=1] */
    public function show(): void
    {
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || !ctype_digit($id)) {
            throw new HttpException(404);
        }
        $row = $this->app->media()->get((int) $id) ?? throw new HttpException(404);

        // Solo quien puede ver el módulo propietario (y el campo, si tiene "access") puede ver el archivo
        $user = $this->user();
        $module = $this->app->modules()->get((string) $row['module']);
        if ($row['field'] === ChatService::MEDIA_FIELD) {
            // Adjunto de una conversación: quien puede ver ese mensaje
            if ($module === null || !$this->app->chat()->canSeeMedia($row, $user)) {
                throw new HttpException(403);
            }
            $this->app->media()->send($row, !empty($_GET['thumb']), !empty($_GET['download']));
            return;
        }
        $allowed = $module !== null
            ? ModuleRegistry::canAccess($user, $module, 'view') && $module->fieldByPath((string) $row['field']) !== null
                // Con "scope", además, en un registro que ve (o subido por él y aún sin guardar)
                && ((int) $row['user_id'] === $user->id() || $module->mediaInScope((string) $row['field'], (int) $row['id']))
            : $user->isAdmin();
        if (!$allowed) {
            throw new HttpException(403);
        }

        $this->app->media()->send($row, !empty($_GET['thumb']), !empty($_GET['download']));
    }
}
