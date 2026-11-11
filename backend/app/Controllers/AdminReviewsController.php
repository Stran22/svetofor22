<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;

final class AdminReviewsController
{
    private AdminAuthService $auth;

    public function __construct(private PDO $db)
    {
        $this->auth = new AdminAuthService($db);
    }

    public function index(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $status = (string) ($request->query()['status'] ?? 'pending');
        if (!in_array($status, ['pending', 'published', 'rejected', 'all'], true)) {
            $status = 'pending';
        }
        $counts = $this->db->query("SELECT status, COUNT(*) AS total FROM reviews GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $sql = "SELECT r.id, r.product_id, r.user_id, r.rating, r.review_text, r.status, r.created_at,
                       p.name AS product_name, u.display_name AS author_name
                FROM reviews r JOIN products p ON p.id = r.product_id
                JOIN users u ON u.id = r.user_id";
        if ($status !== 'all') {
            $sql .= ' WHERE r.status = ?';
        }
        $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 100';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($status === 'all' ? [] : [$status]);
        $rows = [];
        $csrf = AdminView::e($this->auth->csrfToken());
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $review) {
            $id = (int) $review['id'];
            $actions = '';
            if ($review['status'] !== 'published') {
                $actions .= $this->actionForm($id, 'published', 'Опубликовать', $csrf);
            }
            if ($review['status'] !== 'rejected') {
                $actions .= ' ' . $this->actionForm($id, 'rejected', 'Отклонить', $csrf);
            }
            $rows[] = [
                '#' . $id,
                AdminView::e($review['product_name']) . ' (#' . (int) $review['product_id'] . ')',
                AdminView::e($review['author_name'] ?: ('Пользователь #' . $review['user_id'])),
                (int) $review['rating'] . ' / 5',
                nl2br(AdminView::e($review['review_text'] ?: 'Без текста')),
                AdminView::e($review['created_at']),
                AdminView::e($review['status']),
                $actions,
            ];
        }
        $tabs = '<section class="card"><p>';
        foreach (['pending' => 'Ожидают', 'published' => 'Опубликованы', 'rejected' => 'Отклонены', 'all' => 'Все'] as $key => $label) {
            $count = $key === 'all' ? array_sum(array_map('intval', $counts)) : (int) ($counts[$key] ?? 0);
            $tabs .= '<a class="button" style="margin:0 8px 8px 0;' . ($key === $status ? 'background:#17633a;' : '') .
                '" href="/admin/reviews?status=' . $key . '">' . AdminView::e($label) . ' (' . $count . ')</a>';
        }
        $tabs .= '</p><p class="muted">Публикация делает отзыв видимым в приложении и учитывает оценку в рейтинге товара.</p></section>';
        $content = $tabs . AdminView::table(
            ['ID', 'Товар', 'Автор', 'Оценка', 'Текст', 'Дата', 'Статус', 'Действия'],
            $rows,
            'Отзывов с выбранным статусом нет.'
        );
        $admin['csrf'] = $this->auth->csrfToken();
        Response::html(AdminView::layout('Модерация отзывов', $content, $admin));
    }

    public function moderate(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            Response::html('<!doctype html><meta charset="utf-8"><h1>419 — Форма устарела</h1>', 419);
        }
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string) ($data['status'] ?? '');
        if ($id === false || $id < 1 || !in_array($status, ['published', 'rejected'], true)) {
            Response::html('<!doctype html><meta charset="utf-8"><h1>Некорректные данные</h1>', 422);
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT status FROM reviews WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $old = $stmt->fetchColumn();
            if ($old === false) {
                $this->db->rollBack();
                Response::html('<!doctype html><meta charset="utf-8"><h1>Отзыв не найден</h1>', 404);
            }
            if ($old !== $status) {
                $update = $this->db->prepare('UPDATE reviews SET status = ? WHERE id = ?');
                $update->execute([$status, $id]);
                $this->auth->audit((int) $admin['id'], 'review.moderate', 'review', $id, [
                    'from' => $old, 'to' => $status
                ], $request->clientIp());
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        Response::redirect('/admin/reviews?status=pending');
    }

    private function actionForm(int $id, string $status, string $label, string $csrf): string
    {
        return '<form method="post" action="/admin/reviews/' . $id . '/moderate" class="inline">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="status" value="' . $status . '">'
            . '<button type="submit">' . AdminView::e($label) . '</button></form>';
    }
}