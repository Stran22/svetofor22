<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Throwable;

final class AdminProductImageController
{
    public function __construct(private PDO $db) {}

    public function upload(Request $request, array $params): never
    {
        $auth = new AdminAuthService($this->db);
        $auth->requireRole('admin');
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            Response::html('Неверный ID товара', 400);
        }
        $back = '/admin/products/' . $id;
        if (!$auth->verifyCsrf($request->post()['csrf_token'] ?? null)) {
            Response::html('Сессия формы устарела', 419);
        }
        $stmt = $this->db->prepare('SELECT id, image_url FROM products WHERE id=? LIMIT 1');
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            Response::html('Товар не найден', 404);
        }
        $file = $request->file('product_photo');
        if (!$file || !isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])
            || (int) ($file['size'] ?? 0) < 1
            || (int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            $this->flash($auth, 'Ошибка загрузки. Выберите фото размером до 2 МБ.', true);
            Response::redirect($back);
        }
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            $this->flash($auth, 'На сервере не доступна библиотека GD/WebP.', true);
            Response::redirect($back);
        }
        $tmp = $file['tmp_name'];
        $info = @getimagesize($tmp);
        $type = is_array($info) ? ($info[2] ?? null) : null;
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1
            || ($info[0] ?? 0) > 6000 || ($info[1] ?? 0) > 6000
            || ($info[0] * $info[1]) > 20000000) {
            $this->flash($auth, 'Допускаются JPEG, PNG, WebP до 6000 px по стороне.', true);
            Response::redirect($back);
        }
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
        };
        if (!$src) {
            $this->flash($auth, 'Не удалось прочитать изображение.', true);
            Response::redirect($back);
        }
        try {
            if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($tmp);
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [3, 6, 8], true)) {
                    $rotated = imagerotate($src, $orientation === 3 ? 180 : ($orientation === 6 ? -90 : 90), 0);
                    if ($rotated !== false) {
                        imagedestroy($src);
                        $src = $rotated;
                    }
                }
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $ratio = min(1.0, 500 / max($w, $h));
            $nw = max(1, (int) round($w * $ratio));
            $nh = max(1, (int) round($h * $ratio));
            $dst = imagecreatetruecolor($nw, $nh);
            if (!$dst) {
                throw new \RuntimeException('Image allocation failed');
            }
            try {
                $white = imagecolorallocate($dst, 255, 255, 255);
                imagefill($dst, 0, 0, $white);
                if (!imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h)) {
                    throw new \RuntimeException('Image resizing failed');
                }
                // Backend web root: backend/app/Controllers -> backend/public.
                $public = dirname(__DIR__, 2) . '/public';
                if (!is_dir($public)) {
                    throw new \RuntimeException('Public directory missing');
                }
                $dir = $public . '/uploads/products';
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Upload directory unavailable');
                }
                $name = $id . '-' . bin2hex(random_bytes(8)) . '.webp';
                $path = $dir . '/' . $name;
                if (!imagewebp($dst, $path, 80)) {
                    throw new \RuntimeException('WebP encoding failed');
                }
                chmod($path, 0644);
                $url = '/uploads/products/' . $name;
                try {
                    $update = $this->db->prepare('UPDATE products SET image_url=? WHERE id=?');
                    $update->execute([$url, $id]);
                } catch (Throwable $e) {
                    @unlink($path);
                    throw $e;
                }
                $old = (string) ($product['image_url'] ?? '');
                if (preg_match('#^/uploads/products/' . $id . '-[a-f0-9]{16}\.webp$#D', $old)) {
                    @unlink($public . $old);
                }
            } finally {
                imagedestroy($dst);
            }
        } catch (Throwable $e) {
            error_log('Product photo upload failed: ' . get_class($e));
            $this->flash($auth, 'Не удалось сохранить фотографию. Проверьте права каталога.', true);
            Response::redirect($back);
        } finally {
            imagedestroy($src);
        }
        $this->flash($auth, 'Фотография товара сохранена.');
        Response::redirect($back);
    }

    private function flash(AdminAuthService $auth, string $message, bool $error = false): void
    {
        $auth->start();
        $_SESSION['flash'] = ['message' => $message, 'error' => $error];
    }
}
