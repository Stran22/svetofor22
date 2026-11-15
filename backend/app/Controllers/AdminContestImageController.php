<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Throwable;

final class AdminContestImageController
{
    public function __construct(private PDO $db) {}

    public function uploadBanner(Request $request, array $params): never
    {
        $this->upload($request, $params, 'banner');
    }

    public function uploadDetail(Request $request, array $params): never
    {
        $this->upload($request, $params, 'detail');
    }

    private function upload(Request $request, array $params, string $kind): never
    {
        $auth = new AdminAuthService($this->db);
        $admin = $auth->requireRole('admin');
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1 || !in_array($kind, ['banner', 'detail'], true)) {
            Response::html('Некорректный конкурс или тип изображения.', 422);
        }
        $back = '/admin/contests/' . $id . '/edit';
        if (!$auth->verifyCsrf($request->post()['csrf_token'] ?? null)) {
            Response::html('Сессия формы устарела.', 419);
        }
        $column = $kind === 'banner' ? 'banner_url' : 'detail_image_url';
        $stmt = $this->db->prepare('SELECT ' . $column . ' FROM contests WHERE id=?');
        $stmt->execute([$id]);
        $old = $stmt->fetchColumn();
        if ($old === false) {
            Response::html('Конкурс не найден.', 404);
        }

        $file = $request->file('contest_image');
        if (!$file || (int)($file['error'] ?? -1) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name'])
            || (int)($file['size'] ?? 0) < 1
            || (int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
            Response::html('Выберите изображение JPG, PNG или WebP размером до 5 МБ. <a href="' . $back . '">Вернуться</a>', 422);
        }
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            Response::html('На сервере недоступна библиотека GD/WebP.', 500);
        }
        $tmp = $file['tmp_name'];
        $info = @getimagesize($tmp);
        $type = is_array($info) ? ($info[2] ?? null) : null;
        $w = (int)($info[0] ?? 0);
        $h = (int)($info[1] ?? 0);
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
            || $w < 1 || $h < 1 || $w > 6000 || $h > 6000 || $w * $h > 20000000) {
            Response::html('Допускаются только JPG, PNG и WebP до 6000 px по стороне.', 422);
        }
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
        };
        if (!$src) Response::html('Не удалось прочитать изображение.', 422);

        $dst = null;
        $path = null;
        try {
            if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $exif = @exif_read_data($tmp);
                $orientation = (int)($exif['Orientation'] ?? 1);
                if (in_array($orientation, [3,6,8], true)) {
                    $rotated = imagerotate($src, $orientation === 3 ? 180 : ($orientation === 6 ? -90 : 90), 0);
                    if ($rotated !== false) {
                        imagedestroy($src);
                        $src = $rotated;
                    }
                }
            }
            $w = imagesx($src);
            $h = imagesy($src);
            [$targetW,$targetH] = $kind === 'banner' ? [600,200] : [800,450];
            $dst = imagecreatetruecolor($targetW, $targetH);
            if (!$dst) throw new \RuntimeException('Image allocation failed');
            $white = imagecolorallocate($dst,255,255,255);
            imagefill($dst,0,0,$white);
            $scale = max($targetW/$w,$targetH/$h);
            $cropW = min($w,$targetW/$scale);
            $cropH = min($h,$targetH/$scale);
            $sx = ($w-$cropW)/2;
            $sy = ($h-$cropH)/2;
            if (!imagecopyresampled($dst,$src,0,0,(int)round($sx),(int)round($sy),
                $targetW,$targetH,(int)round($cropW),(int)round($cropH))) {
                throw new \RuntimeException('Image resizing failed');
            }

            $public = dirname(__DIR__,2) . '/public';
            if (!is_dir($public)) throw new \RuntimeException('Public directory missing');
            $dir = $public . '/uploads/contests';
            if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) {
                throw new \RuntimeException('Upload directory unavailable');
            }
            $name = $id . '-' . $kind . '-' . bin2hex(random_bytes(8)) . '.webp';
            $path = $dir . '/' . $name;
            if (!imagewebp($dst,$path,82)) throw new \RuntimeException('WebP encoding failed');
            chmod($path,0644);
            $url = '/uploads/contests/' . $name;
            $update = $this->db->prepare('UPDATE contests SET ' . $column . '=? WHERE id=?');
            $update->execute([$url,$id]);
            $auth->audit((int)$admin['id'],'contest.image.upload','contest',$id,['kind'=>$kind],$request->clientIp());
            if (is_string($old) && preg_match('#^/uploads/contests/' . $id . '-' . $kind . '-[a-f0-9]{16}\\.webp$#D',$old)) {
                @unlink($public . $old);
            }
        } catch (Throwable $e) {
            if ($path !== null) @unlink($path);
            error_log('Contest image upload failed: ' . $e::class);
            Response::html('Не удалось сохранить изображение. Проверьте права каталога и настройки сервера.',500);
        } finally {
            if ($dst !== null) imagedestroy($dst);
            imagedestroy($src);
        }
        Response::redirect($back);
    }
}
