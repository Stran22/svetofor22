<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use PDOException;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\TokenService;

final class ContestController
{
    public function __construct(private PDO $db) {}

    public function home(Request $request): never
    {
        $banner = $this->db->query("SELECT title, description, button_text, image_url FROM home_banners WHERE banner_key='reviews_login' AND is_enabled=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
        $contests = $this->db->query("SELECT id, title, banner_url, starts_at, ends_at FROM contests WHERE is_published=1 AND starts_at<=NOW() AND ends_at>NOW() ORDER BY starts_at DESC,id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        Response::success(['reviews_banner' => $banner, 'contests' => $contests]);
    }

    public function index(Request $request): never
    {
        $stmt = $this->db->query("SELECT id,title,banner_url,starts_at,ends_at FROM contests WHERE is_published=1 AND starts_at<=NOW() AND ends_at>NOW() ORDER BY starts_at DESC,id DESC LIMIT 100");
        Response::success($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function show(Request $request, array $params): never
    {
        $id = $this->id($params);
        $stmt = $this->db->prepare("SELECT id,title,description,rules,prize_description,banner_url,detail_image_url,starts_at,ends_at FROM contests WHERE id=? AND is_published=1 AND starts_at<=NOW() AND ends_at>NOW() LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) Response::error('CONTEST_NOT_FOUND', 'Конкурс не найден или завершён.', 404);
        $userId = (new TokenService($this->db))->userId($request->bearerToken());
        $row['is_participant'] = false;
        if ($userId !== null) {
            $check = $this->db->prepare('SELECT 1 FROM contest_participants WHERE contest_id=? AND user_id=? LIMIT 1');
            $check->execute([$id,$userId]);
            $row['is_participant'] = $check->fetchColumn() !== false;
        }
        Response::success($row);
    }

    public function join(Request $request, array $params): never
    {
        $id = $this->id($params);
        $userId = (new TokenService($this->db))->userId($request->bearerToken());
        if ($userId === null) Response::error('UNAUTHENTICATED','Войдите через VK.',401);
        $body = $request->json();
        if (($body['accept_rules'] ?? null) !== true) Response::error('RULES_REQUIRED','Подтвердите согласие с правилами конкурса.',422);

        // The user identity comes from the server-validated token, never from request JSON.
        $this->db->beginTransaction();
        try {
            $check = $this->db->prepare("SELECT u.id FROM users u WHERE u.id=? AND u.is_guest=0 AND EXISTS (SELECT 1 FROM social_accounts sa WHERE sa.user_id=u.id AND sa.provider='vk') LIMIT 1");
            $check->execute([$userId]);
            if ($check->fetchColumn() === false) {
                $this->db->rollBack();
                Response::error('VK_LOGIN_REQUIRED','Для участия войдите через ВКонтакте.',403);
            }
            $contest = $this->db->prepare("SELECT id FROM contests WHERE id=? AND is_published=1 AND starts_at<=NOW() AND ends_at>NOW() FOR UPDATE");
            $contest->execute([$id]);
            if ($contest->fetchColumn() === false) {
                $this->db->rollBack();
                Response::error('CONTEST_NOT_ACTIVE','Конкурс недоступен для участия.',409);
            }
            $insert = $this->db->prepare('INSERT INTO contest_participants (contest_id,user_id) VALUES (?,?)');
            $insert->execute([$id,$userId]);
            $this->db->commit();
        } catch (PDOException $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e->getCode() === '23000' && ($e->errorInfo[1] ?? null) === 1062) {
                Response::error('ALREADY_JOINED','Вы уже участвуете в этом конкурсе.',409);
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        Response::success(['contest_id'=>$id,'is_participant'=>true],[],201);
    }

    private function id(array $params): int
    {
        $id = filter_var($params['id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id === false || $id === null) Response::error('VALIDATION_ERROR','Неверный ID конкурса.',422);
        return (int)$id;
    }
}
