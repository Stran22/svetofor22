<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;

final class AdminContestsController
{
    private AdminAuthService $auth;
    public function __construct(private PDO $db) { $this->auth = new AdminAuthService($db); }

    private function adminLayout(string $title, string $html, array $admin): never
    {
        Response::html(AdminView::layout($title, $html, [
            'username'=>$admin['username'], 'role'=>$admin['role'], 'csrf'=>$this->auth->csrfToken()
        ]));
    }

    public function index(Request $request): never
    {
        $admin = $this->auth->requireUser();
        $rows = $this->db->query('SELECT c.id,c.title,c.starts_at,c.ends_at,c.is_published,COUNT(p.id) AS participants FROM contests c LEFT JOIN contest_participants p ON p.contest_id=c.id GROUP BY c.id ORDER BY c.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        $table = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $table[] = [$id,AdminView::e($r['title']),AdminView::e($r['starts_at']).' — '.AdminView::e($r['ends_at']),
                (int)$r['is_published'] ? 'Опубликован' : 'Черновик',
                '<a href="/admin/contests/'.$id.'/participants">'.(int)$r['participants'].' участников</a>',
                '<a href="/admin/contests/'.$id.'/edit">Редактировать</a>'];
        }
        $html = '<p><a class="button" href="/admin/contests/new">Добавить конкурс</a></p>'
            .AdminView::table(['ID','Название','Сроки','Статус','Участники','Действие'],$table,'Конкурсов пока нет.');
        $this->adminLayout('Конкурсы',$html,$admin);
    }

    public function edit(Request $request, array $params = []): never
    {
        $admin = $this->auth->requireRole('admin');
        $id = (int)($params['id'] ?? 0);
        $item = ['title'=>'','description'=>'','rules'=>'','prize_description'=>'','banner_url'=>'','detail_image_url'=>'','starts_at'=>'','ends_at'=>'','is_published'=>0];
        if ($id) {
            $stmt=$this->db->prepare('SELECT * FROM contests WHERE id=?');
            $stmt->execute([$id]);
            $item=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) Response::html('Конкурс не найден',404);
        }
        $fields = [
            'title'=>['Название конкурса','text',190],
            'description'=>['Описание','textarea',20000],
            'rules'=>['Правила участия','textarea',20000],
            'prize_description'=>['Призы','textarea',20000],
            'banner_url'=>['URL баннера (600×200 WebP)','url',1000],
            'detail_image_url'=>['URL изображения (800×450 WebP)','url',1000],
            'starts_at'=>['Начало','datetime-local',30],
            'ends_at'=>['Окончание','datetime-local',30],
        ];
        $html='<section class="card"><form method="post" action="/admin/contests/save">'
            .'<input type="hidden" name="csrf_token" value="'.AdminView::e($this->auth->csrfToken()).'">'
            .'<input type="hidden" name="id" value="'.$id.'">';
        foreach ($fields as $key=>$spec) {
            [$label,$type,$max]=$spec;
            $value=(string)($item[$key] ?? '');
            if ($type==='datetime-local' && $value!=='') $value=str_replace(' ','T',substr($value,0,16));
            $html.='<label>'.AdminView::e($label);
            $html.=$type==='textarea'
                ? '<textarea name="'.$key.'" maxlength="'.$max.'" rows="5" style="display:block;width:100%;max-width:760px">'.AdminView::e($value).'</textarea>'
                : '<input type="'.$type.'" name="'.$key.'" maxlength="'.$max.'" value="'.AdminView::e($value).'" '.(in_array($key,['title','starts_at','ends_at'],true)?'required':'').'>';
            $html.='</label>';
        }
        $html.='<label><input type="checkbox" name="is_published" value="1" style="display:inline;width:auto"'.((int)$item['is_published']?' checked':'').'> Опубликовать</label>'
            .'<button type="submit">Сохранить конкурс</button></form></section>';
        $this->adminLayout($id?'Редактировать конкурс':'Новый конкурс',$html,$admin);
    }

    public function save(Request $request): never
    {
        $admin=$this->auth->requireRole('admin');
        $data=$request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) Response::html('Сессия формы устарела.',419);
        $id=filter_var($data['id'] ?? 0,FILTER_VALIDATE_INT);
        if ($id===false || $id<0) Response::html('Некорректный ID.',422);
        $title=trim((string)($data['title'] ?? ''));
        $description=trim((string)($data['description'] ?? ''));
        $rules=trim((string)($data['rules'] ?? ''));
        $prize=trim((string)($data['prize_description'] ?? ''));
        $banner=trim((string)($data['banner_url'] ?? ''));
        $image=trim((string)($data['detail_image_url'] ?? ''));
        $start=(string)($data['starts_at'] ?? '');
        $end=(string)($data['ends_at'] ?? '');
        $startDt=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$start);
        $endDt=\DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$end);
        if ($title==='' || mb_strlen($title)>190 || mb_strlen($description)>20000 || mb_strlen($rules)>20000 || mb_strlen($prize)>20000
            || !$startDt || !$endDt || $startDt->format('Y-m-d\\TH:i')!==$start || $endDt->format('Y-m-d\\TH:i')!==$end || $endDt<=$startDt) {
            Response::html('Проверьте название, текст и даты конкурса.',422);
        }
        foreach ([$banner,$image] as $url) {
            if ($url!=='' && (strlen($url)>1000 || !filter_var($url,FILTER_VALIDATE_URL) || !str_starts_with($url,'https://'))) {
                Response::html('Ссылки на изображения должны начинаться с https://',422);
            }
        }
        $published=($data['is_published'] ?? '')==='1'?1:0;
        if ($published && ($description==='' || $rules==='')) Response::html('Для публикации заполните описание и правила.',422);
        $values=[$title,$description,$rules,$prize?:null,$banner?:null,$image?:null,$startDt->format('Y-m-d H:i:s'),$endDt->format('Y-m-d H:i:s'),$published];
        if ($id) {
            $stmt=$this->db->prepare('UPDATE contests SET title=?,description=?,rules=?,prize_description=?,banner_url=?,detail_image_url=?,starts_at=?,ends_at=?,is_published=? WHERE id=?');
            $stmt->execute([...$values,$id]);
            if ($stmt->rowCount()===0) {
                $check=$this->db->prepare('SELECT 1 FROM contests WHERE id=?');$check->execute([$id]);
                if (!$check->fetchColumn()) Response::html('Конкурс не найден.',404);
            }
        } else {
            $stmt=$this->db->prepare('INSERT INTO contests (title,description,rules,prize_description,banner_url,detail_image_url,starts_at,ends_at,is_published) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute($values);
            $id=(int)$this->db->lastInsertId();
        }
        $this->auth->audit((int)$admin['id'],'contest.save','contest',(int)$id,['published'=>$published],$request->clientIp());
        Response::redirect('/admin/contests');
    }

    public function participants(Request $request, array $params): never
    {
        $admin=$this->auth->requireUser();
        $id=filter_var($params['id'] ?? null,FILTER_VALIDATE_INT);
        if (!$id || $id<1) Response::html('Неверный ID.',422);
        $stmt=$this->db->prepare('SELECT title FROM contests WHERE id=?');$stmt->execute([$id]);
        $title=$stmt->fetchColumn();
        if ($title===false) Response::html('Конкурс не найден.',404);
        $stmt=$this->db->prepare("SELECT cp.id,cp.joined_at,COALESCE(NULLIF(u.display_name,''),'Покупатель') AS display_name,sa.provider_user_id FROM contest_participants cp JOIN users u ON u.id=cp.user_id LEFT JOIN social_accounts sa ON sa.user_id=u.id AND sa.provider='vk' WHERE cp.contest_id=? ORDER BY cp.joined_at DESC,cp.id DESC LIMIT 500");
        $stmt->execute([$id]);
        $rows=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[]=[(int)$r['id'],AdminView::e($r['display_name']),AdminView::e($r['provider_user_id'] ?? ''),AdminView::e($r['joined_at'])];
        $this->adminLayout('Участники: '.(string)$title,'<p><a href="/admin/contests">← К конкурсам</a></p>'.AdminView::table(['№ заявки','Имя','VK ID','Дата участия'],$rows,'Участников пока нет.'),$admin);
    }
}
