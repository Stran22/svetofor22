<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Views\AdminView;

final class AdminCategoriesController
{
    private AdminAuthService $auth;

    public function __construct(private PDO $db)
    {
        $this->auth = new AdminAuthService($db);
    }

    public function index(Request $request): never
    {
        $admin = $this->auth->requireUser();
        $categories = $this->all();
        $csrf = AdminView::e($this->auth->csrfToken());
        $content = '<section class="card"><p>Разделы и подразделы каталога. Отключение не удаляет товары.</p></section>';
        if ($admin['role'] === 'admin') {
            $content .= '<section class="card"><h2>Добавить категорию или подкатегорию</h2>'
                . '<form method="post" action="/admin/categories"><input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<label>Название<input name="name" maxlength="190" required></label>'
                . '<label>Родительский раздел<select name="parent_id"><option value="">Верхний уровень</option>'
                . $this->options($categories, null)
                . '</select></label><label>Порядок<input type="number" name="sort_order" value="0"></label>'
                . '<label>URL изображения (необязательно)<input name="image_url" maxlength="1000" type="url"></label>'
                . '<button type="submit">Добавить</button></form></section>';
        }
        $rows = [];
        foreach ($categories as $category) {
            $id = (int) $category['id'];
            $edit = $admin['role'] === 'admin'
                ? '<a href="/admin/categories/' . $id . '/edit">Редактировать</a> '
                  . '<form class="inline" method="post" action="/admin/categories/' . $id . '/toggle">'
                  . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                  . '<button class="link" type="submit">' . ((int)$category['is_active'] ? 'Отключить' : 'Включить') . '</button></form>'
                : '—';
            $rows[] = [
                $id,
                AdminView::e($category['name']),
                $category['parent_id'] === null ? '—' : (int)$category['parent_id'],
                (int)$category['sort_order'],
                (int)$category['is_active'] ? 'Активна' : 'Отключена',
                $edit,
            ];
        }
        $content .= AdminView::table(['ID', 'Название', 'Родитель ID', 'Порядок', 'Статус', 'Действия'], $rows);
        Response::html(AdminView::layout('Категории', $content, $this->adminLayout($admin)));
    }

    public function edit(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        $id = (int)$params['id'];
        $category = $this->one($id);
        if (!$category) Response::html('Категория не найдена', 404);
        $categories = $this->all();
        $csrf = AdminView::e($this->auth->csrfToken());
        $content = '<section class="card"><form method="post" action="/admin/categories/' . $id . '/edit">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<label>Название<input name="name" maxlength="190" required value="' . AdminView::e($category['name']) . '"></label>'
            . '<label>Родительский раздел<select name="parent_id"><option value="">Верхний уровень</option>'
            . $this->options($categories, $category['parent_id'] === null ? null : (int)$category['parent_id'], $id)
            . '</select></label>'
            . '<label>Порядок<input type="number" name="sort_order" value="' . (int)$category['sort_order'] . '"></label>'
            . '<label>URL изображения<input type="url" name="image_url" maxlength="1000" value="' . AdminView::e($category['image_url']) . '"></label>'
            . '<p class="muted">URL-ключ сохраняется при переименовании, чтобы не ломать существующие ссылки.</p>'
            . '<button type="submit">Сохранить</button> <a href="/admin/categories">Отмена</a></form></section>';
        Response::html(AdminView::layout('Редактирование категории', $content, $this->adminLayout($admin)));
    }

    public function create(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        $this->csrf($data);
        [$name, $parent, $sort, $image] = $this->fields($data);
        $slug = $this->uniqueSlug($name, $parent);
        $stmt = $this->db->prepare('INSERT INTO categories (name, parent_id, slug, sort_order, image_url) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $parent, $slug, $sort, $image]);
        $this->auth->audit((int)$admin['id'], 'category.create', 'category', (int)$this->db->lastInsertId(), ['name'=>$name,'parent_id'=>$parent], $request->clientIp());
        Response::redirect('/admin/categories');
    }

    public function update(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        $this->csrf($data);
        $id = (int)$params['id'];
        if (!$this->one($id)) Response::html('Категория не найдена', 404);
        [$name, $parent, $sort, $image] = $this->fields($data);
        if ($parent === $id || ($parent !== null && $this->isDescendant($parent, $id))) {
            Response::html('Нельзя переместить категорию внутрь самой себя или её подраздела.', 422);
        }
        $stmt = $this->db->prepare('UPDATE categories SET name=?, parent_id=?, sort_order=?, image_url=? WHERE id=?');
        $stmt->execute([$name, $parent, $sort, $image, $id]);
        $this->auth->audit((int)$admin['id'], 'category.update', 'category', $id, ['name'=>$name,'parent_id'=>$parent], $request->clientIp());
        Response::redirect('/admin/categories');
    }

    public function toggle(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        $this->csrf($request->post());
        $id = (int)$params['id'];
        $category = $this->one($id);
        if (!$category) Response::html('Категория не найдена', 404);
        if (!(int)$category['is_active'] && $category['parent_id'] !== null) {
            $parent = $this->one((int)$category['parent_id']);
            if (!$parent || !(int)$parent['is_active']) {
                Response::html('Сначала включите родительскую категорию.', 422);
            }
        }
        $stmt = $this->db->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id=?');
        $stmt->execute([$id]);
        $this->auth->audit((int)$admin['id'], 'category.toggle', 'category', $id, [], $request->clientIp());
        Response::redirect('/admin/categories');
    }

    private function all(): array
    {
        return $this->db->query('SELECT id, parent_id, name, slug, sort_order, image_url, is_active FROM categories ORDER BY sort_order, name, id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(int $id): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function options(array $rows, ?int $selected, ?int $exclude = null): string
    {
        $html = '';
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            // В списке родителей показываем только категории верхнего уровня.
            if ($row['parent_id'] !== null) continue;
            if ($id === $exclude || ($exclude !== null && $this->isDescendant($id, $exclude))) continue;
            $html .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>'
                . AdminView::e($row['name']) . ' (ID ' . $id . ')</option>';
        }
        return $html;
    }

    private function fields(array $data): array
    {
        $name = trim((string)($data['name'] ?? ''));
        $rawParent = trim((string)($data['parent_id'] ?? ''));
        $parent = $rawParent === '' ? null : filter_var($rawParent, FILTER_VALIDATE_INT);
        $sort = filter_var($data['sort_order'] ?? 0, FILTER_VALIDATE_INT);
        $image = trim((string)($data['image_url'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190 || $parent === false || ($parent !== null && $parent < 1)
            || $sort === false || strlen($image) > 1000 || ($image !== '' && (!filter_var($image, FILTER_VALIDATE_URL) || !preg_match('~^https://~i', $image)))) {
            Response::html('Проверьте название, родителя, порядок и HTTPS-ссылку изображения.', 422);
        }
        if ($parent !== null && !$this->one($parent)) Response::html('Родительская категория не найдена.', 422);
        return [$name, $parent, $sort, $image === '' ? null : $image];
    }

    private function isDescendant(int $candidate, int $ancestor): bool
    {
        $seen = [];
        while ($candidate > 0 && !isset($seen[$candidate])) {
            if ($candidate === $ancestor) return true;
            $seen[$candidate] = true;
            $row = $this->one($candidate);
            $candidate = $row && $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
        }
        return false;
    }

    private function uniqueSlug(string $name, ?int $parent): string
    {
        $slug = trim(preg_replace('~[^a-z0-9]+~', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: '')), '-');
        if ($slug === '') $slug = 'category';
        $base = substr($slug, 0, 190);
        $stmt = $this->db->prepare('SELECT id FROM categories WHERE slug=? AND parent_id <=> ? LIMIT 1');
        for ($n=0; $n<10000; $n++) {
            $slug = $n === 0 ? $base : $base . '-' . $n;
            $stmt->execute([$slug, $parent]);
            if (!$stmt->fetchColumn()) return $slug;
        }
        Response::html('Не удалось подобрать URL-ключ.', 422);
    }

    private function csrf(array $data): void
    {
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) Response::html('Сессия формы устарела.', 419);
    }

    private function adminLayout(array $admin): array
    {
        $admin['csrf'] = $this->auth->csrfToken();
        return $admin;
    }
}
