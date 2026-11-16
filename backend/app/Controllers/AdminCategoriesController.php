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
        $query = $request->query();
        $sortBy = in_array($query['sort'] ?? '', ['id', 'name'], true) ? $query['sort'] : 'id';
        $direction = ($query['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        usort($categories, static function (array $a, array $b) use ($sortBy, $direction): int {
            $result = $sortBy === 'name'
                ? strcmp(mb_strtolower($a['name']), mb_strtolower($b['name']))
                : ((int)$a['id'] <=> (int)$b['id']);
            if ($result === 0) $result = (int)$a['id'] <=> (int)$b['id'];
            return $direction === 'desc' ? -$result : $result;
        });
        // Сортировка применяется к соседним узлам, а не разрушает дерево.
        $childrenByParent = [];
        $knownIds = [];
        foreach ($categories as $category) $knownIds[(int)$category['id']] = true;
        foreach ($categories as $category) {
            $parent = $category['parent_id'] === null ? 0 : (int)$category['parent_id'];
            if ($parent !== 0 && !isset($knownIds[$parent])) $parent = 0;
            $childrenByParent[$parent][] = $category;
        }
        $tree = [];
        $visited = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$tree, &$visited, $childrenByParent): void {
            foreach ($childrenByParent[$parent] ?? [] as $node) {
                $id = (int)$node['id'];
                if (isset($visited[$id])) continue;
                $visited[$id] = true;
                $node['_depth'] = $depth;
                $tree[] = $node;
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);
        foreach ($categories as $category) {
            if (isset($visited[(int)$category['id']])) continue;
            $walk((int)$category['id'], 0);
            $category['_depth'] = 0;
            $tree[] = $category;
        }
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
        foreach ($tree as $category) {
            $id = (int) $category['id'];
            $edit = $admin['role'] === 'admin'
                ? '<a href="/admin/categories/' . $id . '/edit">Редактировать</a> '
                  . '<form class="inline" method="post" action="/admin/categories/' . $id . '/toggle">'
                  . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                  . '<button class="link" type="submit">' . ((int)$category['is_active'] ? 'Отключить' : 'Включить') . '</button></form>'
                  . ' <form class="inline" method="post" action="/admin/categories/' . $id . '/delete" onsubmit="return confirm(\'Удалить категорию «' . AdminView::e($category['name']) . '»? Действие нельзя отменить.\')">'
                  . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                  . '<button class="link" type="submit">Удалить</button></form>'
                : '—';
            $rows[] = [
                $id,
                '<span style="display:inline-block;padding-left:' . (min((int)$category['_depth'], 12) * 22) . 'px">'
                    . ((int)$category['_depth'] === 0 ? '📁 <strong>' : '↳ 📂 ')
                    . AdminView::e($category['name'])
                    . ((int)$category['_depth'] === 0 ? '</strong>' : '') . '</span>',
                $category['parent_id'] === null ? '—' : (int)$category['parent_id'],
                (int)$category['sort_order'],
                (int)$category['is_active'] ? 'Активна' : 'Отключена',
                $edit,
            ];
        }
        $content .= '<section class="card"><strong>Дерево категорий — сортировка внутри каждого уровня:</strong> '
            . '<a href="/admin/categories?sort=id&amp;dir=asc">ID ↑</a> · '
            . '<a href="/admin/categories?sort=id&amp;dir=desc">ID ↓</a> · '
            . '<a href="/admin/categories?sort=name&amp;dir=asc">Название А–Я</a> · '
            . '<a href="/admin/categories?sort=name&amp;dir=desc">Название Я–А</a>'
            . ' <span class="muted">(сейчас: ' . ($sortBy === 'id' ? 'ID' : 'Название') . ', ' . ($direction === 'asc' ? 'по возрастанию' : 'по убыванию') . ')</span></section>';
        if ($admin['role'] === 'admin') {
            $content .= '<section class="card"><h2>Отображение в карточке товара</h2>'
                . '<p class="muted">Отметьте категории, для которых нужно скрыть функции. Настройки распространяются на все вложенные подкатегории. Цены в каталоге остаются видимыми. Кнопка отслеживания управляется автоматически по остаткам.</p>'
                . '<form method="post" action="/admin/categories/visibility">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<div style="overflow-x:auto"><table><thead><tr><th>Категория / подкатегория</th><th>Скрыть цены и остатки</th></tr></thead><tbody>';
            foreach ($tree as $category) {
                $id = (int)$category['id'];
                $label = str_repeat('— ', min((int)$category['_depth'], 12)) . $category['name'];
                $content .= '<tr><td>' . AdminView::e($label) . '</td>'
                    . '<td><input type="checkbox" name="hide_store_stock[]" value="' . $id . '"' . ((int)$category['hide_store_stock'] ? ' checked' : '') . '></td></tr>';
            }
            $content .= '</tbody></table></div><button type="submit">Сохранить настройки отображения</button></form></section>';
        }
        $content .= AdminView::table(['ID', 'Название', 'Родитель ID', 'Порядок', 'Статус', 'Действия'], $rows);
        Response::html(AdminView::layout('Категории', $content, $this->adminLayout($admin)));
    }

    public function saveVisibility(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        $this->csrf($data);
        $categories = $this->all();
        $known = array_fill_keys(array_map(static fn(array $row): int => (int)$row['id'], $categories), true);
        $selected = [];
        foreach (['hide_store_stock'] as $field) {
            $raw = $data[$field] ?? [];
            if (!is_array($raw)) Response::html('Некорректные настройки категорий.', 422);
            $selected[$field] = [];
            foreach ($raw as $value) {
                $id = filter_var($value, FILTER_VALIDATE_INT);
                if ($id === false || !isset($known[$id])) Response::html('Категория не найдена.', 422);
                $selected[$field][$id] = true;
            }
        }
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE categories SET hide_store_stock=? WHERE id=?');
            foreach ($categories as $category) {
                $id = (int)$category['id'];
                $stmt->execute([
                    isset($selected['hide_store_stock'][$id]) ? 1 : 0,
                    $id
                ]);
            }
            $this->auth->audit((int)$admin['id'], 'category.visibility', 'category', 0, [
                'hide_store_stock' => array_keys($selected['hide_store_stock'])
            ], $request->clientIp());
            $this->db->commit();
        } catch (\\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        Response::redirect('/admin/categories');
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

    public function delete(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        $this->csrf($request->post());
        $id = (int)$params['id'];
        if ($id < 1) Response::html('Некорректный ID категории.', 422);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, name FROM categories WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $category = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$category) {
                $this->db->rollBack();
                Response::html('Категория не найдена.', 404);
            }

            $stmt = $this->db->prepare('SELECT COUNT(*) FROM products WHERE category_id=?');
            $stmt->execute([$id]);
            $products = (int)$stmt->fetchColumn();

            $stmt = $this->db->prepare('SELECT COUNT(*) FROM categories WHERE parent_id=?');
            $stmt->execute([$id]);
            $children = (int)$stmt->fetchColumn();

            if ($products > 0 || $children > 0) {
                $this->db->rollBack();
                Response::html('Удаление запрещено: товаров — ' . $products . ', подкатегорий — ' . $children . '. Сначала перенесите связанные записи.', 409);
            }

            $stmt = $this->db->prepare('DELETE FROM categories WHERE id=?');
            $stmt->execute([$id]);
            $this->auth->audit((int)$admin['id'], 'category.delete', 'category', $id, ['name'=>$category['name']], $request->clientIp());
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        Response::redirect('/admin/categories');
    }

    private function all(): array
    {
        return $this->db->query('SELECT id, parent_id, name, slug, sort_order, image_url, is_active, hide_store_stock FROM categories ORDER BY sort_order, name, id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(int $id): array|false
    {
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function options(array $rows, ?int $selected, ?int $exclude = null): string
    {
        $byParent = [];
        $known = [];
        foreach ($rows as $row) {
            $known[(int)$row['id']] = true;
        }
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
            if ($parentId !== 0 && !isset($known[$parentId])) $parentId = 0;
            $byParent[$parentId][] = $row;
        }

        $html = '';
        $visited = [];
        $append = function (int $parentId, int $depth) use (&$append, &$html, &$visited, $byParent, $exclude, $selected): void {
            foreach ($byParent[$parentId] ?? [] as $row) {
                $id = (int)$row['id'];
                if (isset($visited[$id])) continue;
                $visited[$id] = true;
                if ($id !== $exclude && !($exclude !== null && $this->isDescendant($id, $exclude))) {
                    $prefix = str_repeat('— ', min($depth, 12));
                    $html .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>'
                        . AdminView::e($prefix . $row['name']) . ' (ID ' . $id . ')</option>';
                }
                $append($id, $depth + 1);
            }
        };
        $append(0, 0);
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if (!isset($visited[$id])) $append($id, 0);
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
