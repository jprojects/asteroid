<?php
declare(strict_types=1);

namespace Quasar\Core;

use Quasar\Fields\MediaField;

/**
 * Archivos subidos. Se guardan fuera de public/ (config 'uploads_dir') con
 * nombre aleatorio y se registran en la tabla media; solo se sirven a través
 * de index.php?r=media, que comprueba permisos.
 */
final class MediaStorage
{
    /** Extensiones permitidas => tipos MIME reales aceptados (detectados con finfo) */
    public const TYPES = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    /** MIME con el que se sirve cada extensión (no se confía en lo que diga el navegador) */
    private const SERVE_MIME = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8',
        'csv' => 'text/csv; charset=utf-8',
    ];

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const THUMB_SIZE = 320;

    /** @param \Closure(string,array):string $urlBuilder */
    public function __construct(
        private readonly Database $db,
        private readonly string $dir,
        private readonly \Closure $urlBuilder,
    ) {
    }

    /**
     * Valida y guarda un archivo de $_FILES.
     * @throws MediaException con clave de idioma
     */
    public function store(array $file, string $module, MediaField $field, ?int $userId): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new MediaException('media.too_large', ['max' => self::humanSize($field->maxSize())]);
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new MediaException('media.upload_error');
        }
        $tmp = (string) $file['tmp_name'];
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > $field->maxSize()) {
            throw new MediaException('media.too_large', ['max' => self::humanSize($field->maxSize())]);
        }

        $originalName = self::cleanName((string) ($file['name'] ?? 'archivo'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!$field->accepts($ext, $mime)) {
            throw new MediaException('media.type');
        }
        return $this->save($tmp, true, $originalName, $module, $field->path(), $userId);
    }

    /**
     * Guarda un archivo que ya está en el disco (p. ej. al importar datos de otra aplicación), con las
     * mismas comprobaciones del tipo real que una subida. El original no se toca (se copia).
     * @param ?string $createdAt fecha original del archivo ("Y-m-d H:i:s")
     * @throws MediaException con clave de idioma
     */
    public function importFile(string $source, string $originalName, string $module, string $fieldPath, ?int $userId = null, ?string $createdAt = null): array
    {
        if (!is_file($source) || !is_readable($source) || (int) filesize($source) <= 0) {
            throw new MediaException('media.upload_error');
        }
        return $this->save($source, false, self::cleanName($originalName), $module, $fieldPath, $userId, $createdAt);
    }

    /** Valida el tipo real, copia o mueve el archivo a uploads_dir, crea la miniatura y lo registra. */
    private function save(string $tmp, bool $uploaded, string $originalName, string $module, string $fieldPath, ?int $userId, ?string $createdAt = null): array
    {
        $size = (int) filesize($tmp);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$ext]) || !in_array($mime, self::TYPES[$ext], true)) {
            throw new MediaException('media.type');
        }

        $width = $height = null;
        if (in_array($ext, self::IMAGE_EXT, true)) {
            $info = @getimagesize($tmp);
            if ($info === false) {
                throw new MediaException('media.type');
            }
            [$width, $height] = $info;
        }

        $relDir = $createdAt !== null ? date('Y/m', (int) strtotime($createdAt)) : date('Y/m');
        $absDir = $this->dir . '/' . $relDir;
        if (!is_dir($absDir) && !mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            throw new \RuntimeException("No se puede crear el directorio de subidas: $absDir");
        }
        $base = bin2hex(random_bytes(16));
        $path = "$relDir/$base.$ext";
        if (!($uploaded ? move_uploaded_file($tmp, $this->dir . '/' . $path) : copy($tmp, $this->dir . '/' . $path))) {
            throw new MediaException('media.upload_error');
        }
        $thumb = $width ? $this->makeThumb($this->dir . '/' . $path, $mime, "$relDir/{$base}_thumb.jpg") : null;

        $id = (int) $this->db->insert('media', [
            'module' => $module,
            'field' => $fieldPath,
            'original_name' => $originalName,
            'path' => $path,
            'thumb_path' => $thumb,
            'mime' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'user_id' => $userId,
        ] + ($createdAt !== null ? ['created_at' => $createdAt] : []));
        return $this->info($this->get($id) ?? throw new \RuntimeException('Media no guardado'));
    }

    public function get(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM media WHERE id = ?', [$id]);
    }

    /**
     * Información pública de varios archivos, en el orden pedido.
     * @param list<int> $ids
     * @return list<array>
     */
    public function find(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $byId = array_column($rows, null, 'id');
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $this->info($byId[$id]);
            }
        }
        return $out;
    }

    /**
     * Cuántos de los ids existen y pertenecen a ese módulo y campo.
     * @param list<int> $ids
     */
    public function countOwned(array $ids, string $module, string $fieldPath): int
    {
        if (!$ids) {
            return 0;
        }
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM media WHERE module = ? AND field = ? AND id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')',
            [$module, $fieldPath, ...$ids]
        );
    }

    /** Datos que se envían al navegador (nunca la ruta en disco). */
    public function info(array $row): array
    {
        $id = (int) $row['id'];
        $isImage = str_starts_with((string) $row['mime'], 'image/');
        $url = ($this->urlBuilder)('media', ['id' => $id]);
        return [
            'id' => $id,
            'name' => (string) $row['original_name'],
            'size' => (int) $row['size'],
            'sizeText' => self::humanSize((int) $row['size']),
            'mime' => (string) $row['mime'],
            'ext' => strtolower(pathinfo((string) $row['path'], PATHINFO_EXTENSION)),
            'isImage' => $isImage,
            'url' => $url,
            'thumb' => $isImage ? ($this->urlBuilder)('media', ['id' => $id, 'thumb' => 1]) : null,
            'download' => ($this->urlBuilder)('media', ['id' => $id, 'download' => 1]),
        ];
    }

    /** Envía el archivo al navegador. */
    public function send(array $row, bool $thumb, bool $download): void
    {
        $rel = $thumb && !empty($row['thumb_path']) ? (string) $row['thumb_path'] : (string) $row['path'];
        $file = $this->absolutePath($rel);
        if ($file === null) {
            http_response_code(404);
            return;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        $mime = self::SERVE_MIME[$ext] ?? 'application/octet-stream';
        $inline = !$download && isset(self::SERVE_MIME[$ext]) && $ext !== 'csv';

        $etag = '"' . md5($rel . filemtime($file) . filesize($file)) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            return;
        }
        $name = (string) $row['original_name'];
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'archivo';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($file));
        header(sprintf(
            'Content-Disposition: %s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            $ascii,
            rawurlencode($name)
        ));
        readfile($file);
    }

    public function delete(array $row): void
    {
        foreach ([$row['path'] ?? null, $row['thumb_path'] ?? null] as $rel) {
            $file = $rel ? $this->absolutePath((string) $rel) : null;
            if ($file !== null) {
                @unlink($file);
            }
        }
        $this->db->query('DELETE FROM media WHERE id = ?', [(int) $row['id']]);
    }

    private function absolutePath(string $rel): ?string
    {
        $base = realpath($this->dir);
        $file = $base ? realpath($base . '/' . $rel) : false;
        return $file && str_starts_with($file, $base . DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    /** Miniatura JPEG si GD está disponible; si no, se usa la imagen original. */
    private function makeThumb(string $source, string $mime, string $relTarget): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/gif' => @imagecreatefromgif($source),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if (!$img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::THUMB_SIZE / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $thumb = imagecreatetruecolor($tw, $th);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        $ok = imagejpeg($thumb, $this->dir . '/' . $relTarget, 82);
        return $ok ? $relTarget : null;
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name) !== '' ? trim($name) : 'archivo';
        return mb_substr($name, -200);
    }

    public static function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        $text = $i === 0 ? (string) $bytes : number_format($size, $size < 10 ? 1 : 0);
        return (str_ends_with($text, '.0') ? substr($text, 0, -2) : $text) . ' ' . $units[$i];
    }

    /** "10M", "512K", "2G" o bytes -> bytes */
    public static function parseSize(int|string $size): int
    {
        if (is_int($size)) {
            return $size;
        }
        $size = trim($size);
        $unit = strtoupper(substr($size, -1));
        $n = (float) $size;
        return (int) match ($unit) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
