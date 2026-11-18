<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;
use Throwable;

final class SalesBannerController
{
    private AdminAuthService $auth;
    public function __construct(private PDO $db) { $this->auth = new AdminAuthService($db); }

    public function api(Request $request): never
    {
        $row = $this->db->query("SELECT s.is_enabled,s.mode,s.heading,s.cta_text,s.right_title,s.right_subtitle,s.product_id,s.image_override_url,p.name AS product_name,p.image_url AS product_image_url FROM sales_banner_settings s LEFT JOIN products p ON p.id=s.product_id AND p.is_active=1 WHERE s.id=1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) Response::success(['is_enabled'=>false]);
        $productMode = $row['mode'] === 'product';
        if ($productMode && $row['product_name'] === null) $row['is_enabled'] = 0;
        $row['is_enabled'] = (bool)$row['is_enabled'];
        $row['product_id'] = $productMode && $row['product_name'] !== null ? (int)$row['product_id'] : null;
        $row['image_url'] = $productMode ? ($row['image_override_url'] ?: $row['product_image_url']) : null;
        unset($row['image_override_url'], $row['product_image_url']);
        Response::success($row);
    }

    public function edit(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $row = $this->db->query("SELECT * FROM sales_banner_settings WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        if (!$row) Response::html('Примените миграцию 009_sales_banner_settings.sql.', 503);
        $e = [AdminView::class,'e'];
        $checked = static fn(bool $v): string => $v ? ' checked' : '';
        $html = '<section class="card"><p>Баннер SALES на главной Android. Изменения появятся после обновления данных приложением. В режиме товара нажатие открывает карточку товара.</p>'
            .'<form method="post" action="/admin/sales-banner">'
            .'<input type="hidden" name="csrf_token" value="'.$e($this->auth->csrfToken()).'">'
            .'<label><input type="checkbox" name="is_enabled" value="1" style="display:inline;width:auto"'.$checked((bool)$row['is_enabled']).'> Показывать баннер</label>'
            .'<label>Режим <select name="mode"><option value="text"'.($row['mode']==='text'?' selected':'').'>Текст справа</option><option value="product"'.($row['mode']==='product'?' selected':'').'>Фотография товара</option></select></label>';
        foreach (['heading'=>['Заголовок SALES',60], 'cta_text'=>['Подпись SHOP NOW',60], 'right_title'=>['Правый заголовок BIGGEST SALES',100], 'right_subtitle'=>['Правый подзаголовок UP TO 70% OFF',100]] as $key=>$spec) {
            $html.='<label>'.$e($spec[0]).'<input name="'.$key.'" maxlength="'.$spec[1].'" value="'.$e($row[$key]).'" required></label>';
        }
        $html.='<label>ID товара (для режима фотографии)<input name="product_id" type="number" min="1" value="'.$e($row['product_id']).'"></label>';
        if ($row['product_id']) {
            $stmt=$this->db->prepare('SELECT name,image_url FROM products WHERE id=?');
            $stmt->execute([$row['product_id']]);
            $product=$stmt->fetch(PDO::FETCH_ASSOC);
            if ($product) {
                $html.='<p>Выбран: '.$e($product['name']).'</p>';
                if ($product['image_url']) $html.='<img alt="Фото товара" src="'.$e($product['image_url']).'" style="max-width:180px;max-height:160px">';
            }
        }
        $html.='<p>По умолчанию используется фото из карточки товара. Можно загрузить отдельный прозрачный PNG/WebP.</p>'
            .'<button type="submit">Сохранить настройки</button></form></section>'
            .'<section class="card"><h2>Отдельная фотография товара</h2>';
        if ($row['image_override_url']) $html.='<p><img alt="Отдельное изображение" src="'.$e($row['image_override_url']).'" style="max-width:240px;max-height:200px"></p>';
        $html.='<form method="post" enctype="multipart/form-data" action="/admin/sales-banner/image">'
            .'<input type="hidden" name="csrf_token" value="'.$e($this->auth->csrfToken()).'">'
            .'<label>PNG/WebP с прозрачностью (до 5 МБ)<input type="file" name="banner_image" accept="image/png,image/webp" required></label>'
            .'<button type="submit">Загрузить фотографию</button></form>'
            .'<form method="post" action="/admin/sales-banner/image/remove">'
            .'<input type="hidden" name="csrf_token" value="'.$e($this->auth->csrfToken()).'">'
            .'<button type="submit">Вернуть фото из карточки товара</button></form></section>';
        Response::html(AdminView::layout('Баннер SALES',$html,['username'=>$admin['username'],'role'=>$admin['role'],'csrf'=>$this->auth->csrfToken()]));
    }

    public function save(Request $request): never
    {
        $admin=$this->auth->requireRole('admin');
        $data=$request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) Response::html('Сессия формы устарела.',419);
        $mode=(string)($data['mode'] ?? '');
        if (!in_array($mode,['text','product'],true)) Response::html('Некорректный режим.',422);
        $texts=[];
        foreach (['heading'=>60,'cta_text'=>60,'right_title'=>100,'right_subtitle'=>100] as $field=>$max) {
            $value=trim((string)($data[$field] ?? ''));
            if ($value==='' || mb_strlen($value)>$max) Response::html('Проверьте текст: '.$field,422);
            $texts[]=$value;
        }
        $id=null;
        if ($mode==='product') {
            $id=filter_var($data['product_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($id===false || $id===null) Response::html('Укажите ID товара.',422);
            $stmt=$this->db->prepare('SELECT id FROM products WHERE id=? AND is_active=1');
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) Response::html('Активный товар с этим ID не найден.',422);
        }
        $stmt=$this->db->prepare('UPDATE sales_banner_settings SET is_enabled=?,mode=?,heading=?,cta_text=?,right_title=?,right_subtitle=?,product_id=? WHERE id=1');
        $stmt->execute([isset($data['is_enabled'])?1:0,$mode,...$texts,$id]);
        $this->auth->audit((int)$admin['id'],'sales_banner.save','sales_banner',1,['mode'=>$mode,'product_id'=>$id],$request->clientIp());
        Response::redirect('/admin/sales-banner');
    }

    public function upload(Request $request): never
    {
        $admin=$this->auth->requireRole('admin');
        if (!$this->auth->verifyCsrf($request->post()['csrf_token'] ?? null)) Response::html('Сессия формы устарела.',419);
        $file=$request->file('banner_image');
        if (!$file || (int)($file['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??'')) || (int)($file['size']??0)<1 || (int)($file['size']??0)>5*1024*1024) Response::html('Нужен PNG/WebP до 5 МБ.',422);
        if (!extension_loaded('gd') || !function_exists('imagewebp')) Response::html('На сервере недоступен GD/WebP.',500);
        $tmp=$file['tmp_name']; $info=@getimagesize($tmp);
        $type=$info[2]??null; $w=(int)($info[0]??0); $h=(int)($info[1]??0);
        if (!in_array($type,[IMAGETYPE_PNG,IMAGETYPE_WEBP],true) || $w<1 || $h<1 || $w>4000 || $h>4000 || $w*$h>12000000) Response::html('Нужен корректный PNG/WebP до 4000 px.',422);
        $src=$type===IMAGETYPE_PNG ? @imagecreatefrompng($tmp) : @imagecreatefromwebp($tmp);
        if (!$src) Response::html('Не удалось прочитать изображение.',422);
        $dst=null; $path=null;
        try {
            $scale=min(1,900/max($w,$h));$nw=max(1,(int)round($w*$scale));$nh=max(1,(int)round($h*$scale));
            $dst=imagecreatetruecolor($nw,$nh);
            if (!$dst) throw new \RuntimeException('Allocation failed');
            imagealphablending($dst,false);imagesavealpha($dst,true);
            $transparent=imagecolorallocatealpha($dst,0,0,0,127);imagefill($dst,0,0,$transparent);
            if (!imagecopyresampled($dst,$src,0,0,0,0,$nw,$nh,$w,$h)) throw new \RuntimeException('Resample failed');
            $public=dirname(__DIR__,2).'/public';$dir=$public.'/uploads/sales';
            if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new \RuntimeException('Directory failed');
            $path=$dir.'/1-'.bin2hex(random_bytes(8)).'.webp';
            if (!imagewebp($dst,$path,85)) throw new \RuntimeException('WebP encoding failed');
            chmod($path,0644);
            $old=$this->db->query('SELECT image_override_url FROM sales_banner_settings WHERE id=1')->fetchColumn();
            $url='/uploads/sales/'.basename($path);
            $stmt=$this->db->prepare('UPDATE sales_banner_settings SET image_override_url=? WHERE id=1');
            $stmt->execute([$url]);
            $this->auth->audit((int)$admin['id'],'sales_banner.image','sales_banner',1,[],$request->clientIp());
            if (is_string($old) && preg_match('#^/uploads/sales/1-[a-f0-9]{16}\.webp$#D',$old)) @unlink($public.$old);
        } catch (Throwable $e) {
            if ($path) @unlink($path);
            error_log('[sales banner image] '.$e::class);
            Response::html('Не удалось сохранить изображение.',500);
        } finally {
            if ($dst) imagedestroy($dst);
            imagedestroy($src);
        }
        Response::redirect('/admin/sales-banner');
    }

    public function removeImage(Request $request): never
    {
        $admin=$this->auth->requireRole('admin');
        if (!$this->auth->verifyCsrf($request->post()['csrf_token']??null)) Response::html('Сессия формы устарела.',419);
        $old=$this->db->query('SELECT image_override_url FROM sales_banner_settings WHERE id=1')->fetchColumn();
        $this->db->exec('UPDATE sales_banner_settings SET image_override_url=NULL WHERE id=1');
        $this->auth->audit((int)$admin['id'],'sales_banner.image.remove','sales_banner',1,[],$request->clientIp());
        if (is_string($old) && preg_match('#^/uploads/sales/1-[a-f0-9]{16}\.webp$#D',$old)) @unlink(dirname(__DIR__,2).'/public'.$old);
        Response::redirect('/admin/sales-banner');
    }
}
