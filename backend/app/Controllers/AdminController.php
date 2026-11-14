<?php
declare(strict_types=1);

namespace Svet22\Controllers;

use PDO;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Services\AdminAuthService;
use Svet22\Services\XlsxImportService;
use Svet22\Views\AdminView;
use Throwable;

final class AdminController
{
    private AdminAuthService $auth;

    public function __construct(private PDO $db)
    {
        $this->auth = new AdminAuthService($db);
    }

    public function loginPage(Request $request): never
    {
        if ($this->auth->user() !== null) {
            Response::redirect('/admin');
        }
        Response::html(AdminView::login($this->auth->csrfToken()));
    }

    public function login(Request $request): never
    {
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            Response::html(AdminView::login($this->auth->csrfToken(), 'Сессия формы устарела. Обновите страницу и повторите вход.'), 419);
        }

        $ok = $this->auth->login(
            (string) ($data['username'] ?? ''),
            (string) ($data['password'] ?? ''),
            $request->clientIp()
        );
        if (!$ok) {
            // Небольшая пауза снижает скорость перебора паролей.
            usleep(350000);
            Response::html(AdminView::login($this->auth->csrfToken(), 'Не удалось войти. Проверьте логин и пароль или попробуйте позже.'), 401);
        }
        Response::redirect('/admin');
    }

    public function logout(Request $request): never
    {
        $admin = $this->auth->requireUser();
        if (!$this->auth->verifyCsrf($request->post()['csrf_token'] ?? null)) {
            Response::html('<!doctype html><meta charset="utf-8"><h1>419 — Обновите страницу</h1>', 419);
        }
        $this->auth->logout();
        Response::redirect('/admin/login');
    }

    public function dashboard(Request $request): never
    {
        $admin = $this->currentAdmin();
        $stats = [
            'Товары' => (int) $this->db->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn(),
            'Филиалы' => (int) $this->db->query('SELECT COUNT(*) FROM branches WHERE is_active = 1')->fetchColumn(),
            'Категории' => (int) $this->db->query('SELECT COUNT(*) FROM categories WHERE is_active = 1')->fetchColumn(),
            'Загрузки' => (int) $this->db->query('SELECT COUNT(*) FROM upload_log')->fetchColumn(),
        ];
        $content = '<div class="grid">';
        foreach ($stats as $label => $value) {
            $content .= '<section class="card"><div class="muted">' . AdminView::e($label) . '</div><div class="metric">' . $value . '</div></section>';
        }
        $content .= '</div><section class="card"><h2>Быстрые действия</h2><p><a class="button" href="/admin/import">Загрузить Excel</a> '
            . '<a class="button" href="/admin/imports">История загрузок</a></p>'
            . '<p class="muted">Для начала работы создайте учётную запись администратора через серверную CLI-команду и добавьте филиалы.</p></section>';
        $this->page('Обзор', $content, $admin);
    }

    public function importPage(Request $request): never
    {
        $admin = $this->currentAdmin();
        $branches = $this->activeBranches();
        $content = $this->flashHtml();
        if (!$branches) {
            $content .= AdminView::alert('Нет активных филиалов. Сначала добавьте филиал в разделе «Филиалы».', true);
        } else {
            $content .= '<section class="card"><p>Выберите филиал, к которому относится загружаемый файл. Для каждого филиала используется отдельная выгрузка.</p>'
                . '<form method="post" action="/admin/import/preview" enctype="multipart/form-data">'
                . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'
                . '<label>Филиал<select name="branch_id" required><option value="">Выберите филиал</option>';
            foreach ($branches as $branch) {
                $content .= '<option value="' . (int) $branch['id'] . '">' . AdminView::e($branch['name']) . '</option>';
            }
            $content .= '</select></label><label>Файл Excel (.xlsx)<input type="file" name="xlsx_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></label>'
                . '<p class="muted">Максимальный размер — 5 МБ. Обязательны: Название, Цена, Остаток и хотя бы один идентификатор — Штрихкод или Артикул. Дополнительно поддерживаются: Единица измерения, Категория, Производитель. Для нестандартных выгрузок сначала настройте соответствие колонок ниже. Одинаковое содержимое в тот же филиал и день повторно не обрабатывается, даже под другим именем.</p>'
                . '<button type="submit">Проверить файл</button></form></section>';
        }
        if (($admin['role'] ?? '') === 'admin' && $branches) {
            $mappingLinks = '<section class="card"><h2>Соответствие колонок по филиалам</h2><ul>';
            foreach ($branches as $branchRow) {
                $mappingLinks .= '<li><a href="/admin/import/mapping?branch_id=' . (int) $branchRow['id'] . '">Настроить: '
                    . AdminView::e($branchRow['name']) . '</a></li>';
            }
            $mappingLinks .= '</ul></section>';
            $content .= $mappingLinks;
        }
        $this->page('Импорт Excel', $content, $admin);
    }

    public function mappingPage(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $branches = $this->activeBranches();
        $selectedId = filter_var($request->query()['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $selectedId = $selectedId === false ? 0 : (int) $selectedId;
        $content = $this->flashHtml()
            . '<section class="card"><h2>Настройка колонок Excel</h2><p class="muted">Настройка сохраняется отдельно для каждого филиала. Сначала загрузите пример выгрузки, затем укажите, где находятся нужные данные.</p>'
            . '<form method="get" action="/admin/import/mapping"><label>Филиал<select name="branch_id" required><option value="">Выберите филиал</option>';
        foreach ($branches as $branchRow) {
            $selected = (int) $branchRow['id'] === $selectedId ? ' selected' : '';
            $content .= '<option value="' . (int) $branchRow['id'] . '"' . $selected . '>' . AdminView::e($branchRow['name']) . '</option>';
        }
        $content .= '</select></label><button type="submit">Выбрать филиал</button></form></section>';

        if ($selectedId > 0) {
            $branchCheck = $this->db->prepare('SELECT name FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
            $branchCheck->execute([$selectedId]);
            $branchName = $branchCheck->fetchColumn();
            if ($branchName !== false) {
                $service = new XlsxImportService($this->db);
                $saved = $service->loadMapping($selectedId);
                $content .= '<section class="card"><h2>Пример файла: ' . AdminView::e($branchName) . '</h2>'
                    . '<form method="post" action="/admin/import/mapping/preview" enctype="multipart/form-data">'
                    . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'
                    . '<input type="hidden" name="branch_id" value="' . $selectedId . '">'
                    . '<label>Файл Excel (.xlsx)<input type="file" name="xlsx_file" accept=".xlsx" required></label>'
                    . '<button type="submit">Показать колонки файла</button></form>'
                    . ($saved ? '<p class="muted">Для этого филиала уже сохранено соответствие колонок. Новая настройка заменит предыдущую.</p>' : '<p class="muted">Соответствие колонок ещё не настроено.</p>')
                    . '</section>';
            } else {
                $content .= AdminView::alert('Филиал не найден или отключён.', true);
            }
        }
        $this->page('Настройка колонок Excel', $content, $admin);
    }

    public function previewMapping(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            $this->flash('Форма устарела. Обновите страницу.', true);
            Response::redirect('/admin/import/mapping');
        }
        $branchId = filter_var($data['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $file = $request->file('xlsx_file');
        if ($branchId === false || $branchId < 1 || !$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $this->flash('Выберите филиал и корректный XLSX-файл.', true);
            Response::redirect('/admin/import/mapping');
        }

        try {
            $service = new XlsxImportService($this->db);
            $preview = $service->previewColumns(
                (string) $file['tmp_name'],
                (string) $file['name'],
                (int) $branchId
            );
            $saved = $service->loadMapping((int) $branchId) ?? [];
            $fields = [
                'barcode' => 'Штрихкод',
                'article' => 'Артикул',
                'name' => 'Название товара',
                'price' => 'Цена',
                'quantity' => 'Остаток',
                'unit' => 'Единица измерения',
                'category' => 'Категория',
                'manufacturer' => 'Производитель',
            ];
            $required = ['name', 'price', 'quantity'];
            $content = '<section class="card"><p>Выберите номер колонки для каждого поля. Штрихкод и артикул необязательны по отдельности, но нужно выбрать хотя бы один из них.</p>'
                . '<form method="post" action="/admin/import/mapping/save">'
                . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'
                . '<input type="hidden" name="branch_id" value="' . (int) $branchId . '">'
                . '<input type="hidden" name="column_count" value="' . (int) $preview['column_count'] . '">';
            foreach ($fields as $key => $label) {
                $content .= '<label>' . AdminView::e($label) . ($key === 'barcode' || $key === 'article' ? ' <span class="muted">(одно из двух обязательно)</span>' : (in_array($key, $required, true) ? ' *' : ''))
                    . '<select name="mapping[' . $key . ']"><option value="">— не использовать —</option>';
                foreach ($preview['headers'] as $index => $header) {
                    $selected = isset($saved[$key]) && (int) $saved[$key] === $index ? ' selected' : '';
                    $caption = 'Колонка ' . ($index + 1) . ': ' . ($header !== '' ? $header : '(без заголовка)');
                    $content .= '<option value="' . (int) $index . '"' . $selected . '>' . AdminView::e($caption) . '</option>';
                }
                $content .= '</select></label>';
            }
            $content .= '<button type="submit">Сохранить соответствие колонок</button> <a href="/admin/import/mapping?branch_id=' . (int) $branchId . '">Отмена</a></form></section>';

            $sampleRows = [];
            foreach ($preview['samples'] as $sample) {
                $cells = [];
                foreach ($preview['headers'] as $index => $_header) {
                    $cells[] = AdminView::e($sample[$index] ?? '');
                }
                $sampleRows[] = $cells;
            }
            $content .= '<section class="card"><h2>Первые строки файла</h2>'
                . AdminView::table(array_map(
                    static fn(int $index, string $header): string => 'Колонка ' . ($index + 1) . ': ' . ($header !== '' ? $header : '—'),
                    array_keys($preview['headers']),
                    $preview['headers']
                ), $sampleRows, 'В файле нет строк с данными.')
                . '</section>';
            $this->page('Сопоставление колонок', $content, $admin);
        } catch (\InvalidArgumentException $e) {
            $this->flash($e->getMessage(), true);
            Response::redirect('/admin/import/mapping?branch_id=' . (int) $branchId);
        } catch (Throwable $e) {
            error_log('[svet22-mapping-preview] ' . $e::class . ': ' . $e->getMessage());
            $this->flash('Не удалось прочитать Excel-файл. Подробности записаны в журнал сервера.', true);
            Response::redirect('/admin/import/mapping?branch_id=' . (int) $branchId);
        }
    }

    public function saveMapping(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/import/mapping');
        }
        $branchId = filter_var($data['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $columnCount = filter_var($data['column_count'] ?? null, FILTER_VALIDATE_INT);
        $mapping = $data['mapping'] ?? null;
        if ($branchId === false || $branchId < 1 || $columnCount === false || $columnCount < 1
            || $columnCount > 16384 || !is_array($mapping)) {
            $this->flash('Данные сопоставления некорректны. Повторите настройку.', true);
            Response::redirect('/admin/import/mapping');
        }
        try {
            $service = new XlsxImportService($this->db);
            $validated = $service->saveMapping((int) $branchId, $mapping, (int) $columnCount);
            $this->auth->audit((int) $admin['id'], 'import.mapping.save', 'branch', (int) $branchId, $validated, $request->clientIp());
            $this->flash('Соответствие колонок сохранено для выбранного филиала.');
        } catch (\InvalidArgumentException $e) {
            $this->flash($e->getMessage(), true);
        }
        Response::redirect('/admin/import/mapping?branch_id=' . (int) $branchId);
    }

    public function previewImport(Request $request): never
    {
        $admin = $this->currentAdmin();
        // Starting a new preview invalidates any earlier confirmation.
        $this->auth->start();
        unset($_SESSION['excel_import_preview']);
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/import');
        }
        $branchId = filter_var($data['branch_id'] ?? null, FILTER_VALIDATE_INT);
        $file = $request->file('xlsx_file');
        if ($branchId === false || $branchId < 1 || !$file
            || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $this->flash('Выберите филиал и корректный XLSX-файл.', true);
            Response::redirect('/admin/import');
        }
        try {
            $preview = (new XlsxImportService($this->db))->previewImport(
                (string) $file['tmp_name'], (string) $file['name'], (int) $branchId
            );
            // Bind confirmation to a successful preview in this admin session.
            $this->auth->start();
            unset($_SESSION['excel_import_preview']);
            if ((int) $preview['rows_failed'] === 0 && (int) $preview['rows_valid'] > 0) {
                $mapping = $preview['mapping'];
                ksort($mapping);
                $_SESSION['excel_import_preview'] = [
                    'admin_id' => (int) $admin['id'],
                    'branch_id' => (int) $branchId,
                    'file_sha256' => (string) $preview['sha256'],
                    'mapping_sha256' => hash('sha256', json_encode($mapping, JSON_THROW_ON_ERROR)),
                    'expires_at' => time() + 900,
                ];
            }
            $branchStmt = $this->db->prepare('SELECT name FROM branches WHERE id = ?');
            $branchStmt->execute([(int) $branchId]);
            $branchName = (string) $branchStmt->fetchColumn();
            $content = '<section class="card"><h2>Проверка Excel без изменения базы</h2>'
                . '<p>Филиал: <strong>' . AdminView::e($branchName) . '</strong></p>'
                . '<p>Файл: ' . AdminView::e((string) $file['name']) . '</p>'
                . '<p>Всего строк: ' . (int) $preview['rows_total']
                . '; корректных: ' . (int) $preview['rows_valid']
                . '; ошибок: ' . (int) $preview['rows_failed'] . '.</p>';
            $mappingRows = [];
            foreach ($preview['mapping'] as $field => $index) {
                $mappingRows[] = [AdminView::e($field), AdminView::e((string) ($preview['headers'][$index] ?? ''))];
            }
            $content .= '<h3>Распознанные колонки</h3>'
                . AdminView::table(['Поле', 'Колонка Excel'], $mappingRows, 'Нет колонок');
            $sampleRows = [];
            foreach ($preview['samples'] as $sample) {
                $sampleRows[] = [(string) $sample['line'], AdminView::e($sample['name']),
                    AdminView::e($sample['price']), AdminView::e($sample['quantity'])];
            }
            $content .= '<h3>Первые товары</h3>'
                . AdminView::table(['Строка', 'Название', 'Цена', 'Остаток'], $sampleRows, 'Нет товаров');
            if ($preview['rows_failed'] > 0) {
                $errorRows = [];
                foreach ($preview['errors'] as $error) {
                    $errorRows[] = [(string) $error['line'], AdminView::e($error['message'])];
                }
                $content .= '<h3>Ошибки (первые 20)</h3>'
                    . AdminView::table(['Строка', 'Ошибка'], $errorRows, '');
                $content .= AdminView::alert('Исправьте ошибки перед импортом.', true);
            } elseif ($preview['rows_valid'] > 0) {
                // Require a fresh upload of the identical file. No persistent temporary files.
                $content .= '<p class="muted">Проверка прошла успешно. Для подтверждения выберите тот же файл ещё раз. Сервер проверит его контрольную сумму.</p>'
                    . '<form method="post" action="/admin/import/confirm" enctype="multipart/form-data">'
                    . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'
                    . '<input type="hidden" name="branch_id" value="' . (int) $branchId . '">'
                    . '<input type="hidden" name="expected_sha256" value="' . AdminView::e($preview['sha256']) . '">'
                    . '<label>Тот же Excel-файл<input type="file" name="xlsx_file" accept=".xlsx" required></label>'
                    . '<button type="submit">Подтвердить и импортировать</button></form>';
            } else {
                $content .= AdminView::alert('В файле нет товаров для импорта.', true);
            }
            $content .= '<p><a href="/admin/import">Вернуться к выбору файла</a></p></section>';
            $this->page('Предпросмотр импорта', $content, $admin);
        } catch (\InvalidArgumentException $e) {
            $this->flash($e->getMessage(), true);
            Response::redirect('/admin/import');
        } catch (Throwable $e) {
            error_log('[svet22-import-preview] ' . $e::class . ': ' . $e->getMessage());
            $this->flash('Не удалось проверить файл. Подробности в журнале сервера.', true);
            Response::redirect('/admin/import');
        }
    }

    public function import(Request $request): never
    {
        $admin = $this->currentAdmin();
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            $this->flash('Форма устарела. Обновите страницу и повторите загрузку.', true);
            Response::redirect('/admin/import');
        }

        $branchId = filter_var($data['branch_id'] ?? null, FILTER_VALIDATE_INT);
        if ($branchId === false || $branchId < 1) {
            $this->flash('Необходимо выбрать филиал.', true);
            Response::redirect('/admin/import');
        }

        $file = $request->file('xlsx_file');
        if (!$file || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
            $this->flash('Файл не был передан серверу.', true);
            Response::redirect('/admin/import');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            $message = match ((int) $file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает разрешённый сервером размер.',
                UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью.',
                UPLOAD_ERR_NO_FILE => 'Выберите файл для загрузки.',
                default => 'Ошибка загрузки файла. Проверьте настройки PHP на хостинге.',
            };
            $this->flash($message, true);
            Response::redirect('/admin/import');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            $this->flash('Загруженный файл не прошёл проверку безопасности.', true);
            Response::redirect('/admin/import');
        }
        if ((int) $file['size'] > 5 * 1024 * 1024) {
            $this->flash('Размер файла не должен превышать 5 МБ.', true);
            Response::redirect('/admin/import');
        }

        $expected = (string) ($data['expected_sha256'] ?? '');
        $actual = hash_file('sha256', (string) $file['tmp_name']);
        if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !is_string($actual)
            || !hash_equals($expected, $actual)) {
            $this->flash('Файл отличается от проверенного. Повторите предпросмотр.', true);
            Response::redirect('/admin/import');
        }
        // A hidden form field is not proof of a prior preview.
        $this->auth->start();
        $approval = $_SESSION['excel_import_preview'] ?? null;
        unset($_SESSION['excel_import_preview']); // single use, including failed attempts
        if (!is_array($approval)
            || (int) ($approval['admin_id'] ?? 0) !== (int) $admin['id']
            || (int) ($approval['branch_id'] ?? 0) !== (int) $branchId
            || (int) ($approval['expires_at'] ?? 0) < time()
            || !is_string($approval['file_sha256'] ?? null)
            || !hash_equals($approval['file_sha256'], $actual)) {
            $this->flash('Подтверждение истекло или не было выполнено. Проверьте файл заново.', true);
            Response::redirect('/admin/import');
        }
        try {
            $check = (new XlsxImportService($this->db))->previewImport(
                (string) $file['tmp_name'], (string) $file['name'], (int) $branchId
            );
            $effectiveMapping = $check['mapping'];
            ksort($effectiveMapping);
            $currentMappingHash = hash('sha256', json_encode($effectiveMapping, JSON_THROW_ON_ERROR));
            if (!is_string($approval['mapping_sha256'] ?? null)
                || !hash_equals($approval['mapping_sha256'], $currentMappingHash)) {
                $this->flash('Сопоставление колонок изменилось. Проверьте файл заново.', true);
                Response::redirect('/admin/import');
            }
            if ($check['rows_failed'] > 0 || $check['rows_valid'] === 0) {
                $this->flash('В файле обнаружены ошибки. Повторите проверку.', true);
                Response::redirect('/admin/import');
            }
        } catch (Throwable $e) {
            $this->flash('Проверка файла не прошла. Повторите предпросмотр.', true);
            Response::redirect('/admin/import');
        }

        try {
            $result = (new XlsxImportService($this->db))->import(
                (string) $file['tmp_name'],
                (string) $file['name'],
                (int) $branchId,
                (int) $admin['id']
            );
            $this->auth->audit((int) $admin['id'], 'import.xlsx', 'branch', (int) $branchId, $result, $request->clientIp());
            if (($result['duplicate'] ?? false) === true) {
                $this->flash('Этот файл уже был успешно загружен сегодня. Повторная обработка не выполнялась.');
            } else {
                $this->flash(sprintf(
                    'Импорт завершён. Строк: %d; добавлено: %d; изменено: %d; без изменений: %d; с ошибками: %d.',
                    $result['rows_total'] ?? 0,
                    $result['rows_created'] ?? 0,
                    $result['rows_updated'] ?? 0,
                    $result['rows_skipped'] ?? 0,
                    $result['rows_failed'] ?? 0
                ));
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash($e->getMessage(), true);
        } catch (Throwable $e) {
            error_log('[svet22-admin-import] ' . $e::class . ': ' . $e->getMessage());
            $this->flash('Не удалось обработать файл. Подробности записаны в журнал сервера.', true);
        }
        Response::redirect('/admin/imports');
    }

    public function imports(Request $request): never
    {
        $admin = $this->currentAdmin();
        $stmt = $this->db->query(
            'SELECT u.id, b.name AS branch_name, u.original_filename, u.recorded_date, u.status,
                    u.rows_total, u.rows_created, u.rows_updated, u.rows_skipped, u.rows_failed, u.started_at
             FROM upload_log u JOIN branches b ON b.id = u.branch_id
             ORDER BY u.id DESC LIMIT 100'
        );
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                '<a href="/admin/imports/' . (int) $row['id'] . '">#' . (int) $row['id'] . '</a>',
                AdminView::e($row['branch_name']),
                AdminView::e($row['original_filename']),
                AdminView::e($row['recorded_date']),
                AdminView::e($this->statusLabel((string) $row['status'])),
                (int) $row['rows_total'],
                (int) $row['rows_created'],
                (int) $row['rows_updated'],
                (int) $row['rows_failed'],
            ];
        }
        $content = $this->flashHtml()
            . AdminView::table(['Загрузка', 'Филиал', 'Файл', 'Дата', 'Статус', 'Строк', 'Добавлено', 'Изменено', 'Ошибки'], $rows, 'Загрузок пока нет.');
        $this->page('История загрузок', $content, $admin);
    }

    public function importDetail(Request $request, array $params): never
    {
        $admin = $this->currentAdmin();
        $stmt = $this->db->prepare(
            'SELECT u.*, b.name AS branch_name, a.username AS admin_name
             FROM upload_log u JOIN branches b ON b.id = u.branch_id
             LEFT JOIN admins a ON a.id = u.admin_id WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$params['id']]);
        $upload = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$upload) {
            Response::html('<!doctype html><meta charset="utf-8"><h1>Загрузка не найдена</h1><a href="/admin/imports">Назад</a>', 404);
        }
        $content = '<section class="card"><p><b>Филиал:</b> ' . AdminView::e($upload['branch_name']) . '</p>'
            . '<p><b>Файл:</b> ' . AdminView::e($upload['original_filename']) . '</p>'
            . '<p><b>Дата выгрузки:</b> ' . AdminView::e($upload['recorded_date']) . '</p>'
            . '<p><b>Кто загрузил:</b> ' . AdminView::e($upload['admin_name'] ?? '—') . '</p>'
            . '<p><b>Статус:</b> ' . AdminView::e($this->statusLabel((string) $upload['status'])) . '</p>'
            . '<p>Всего строк: ' . (int) $upload['rows_total'] . '; добавлено: ' . (int) $upload['rows_created']
            . '; изменено: ' . (int) $upload['rows_updated'] . '; без изменений: ' . (int) $upload['rows_skipped']
            . '; ошибок: ' . (int) $upload['rows_failed'] . '</p>'
            . (!empty($upload['error_text']) ? AdminView::alert((string) $upload['error_text'], true) : '') . '</section>';
        $errors = $this->db->prepare('SELECT source_row_number, error_code, error_message, row_data FROM import_errors WHERE upload_id = ? ORDER BY source_row_number LIMIT 500');
        $errors->execute([$params['id']]);
        $errorRows = [];
        foreach ($errors->fetchAll(PDO::FETCH_ASSOC) as $error) {
            $errorRows[] = [
                (int) $error['source_row_number'],
                AdminView::e($error['error_code']),
                AdminView::e($error['error_message']),
                '<code>' . AdminView::e($error['row_data']) . '</code>',
            ];
        }
        $content .= '<h2>Ошибки строк</h2>' . AdminView::table(['Строка Excel', 'Код', 'Описание', 'Данные строки'], $errorRows, 'Ошибок нет.');
        $this->page('Загрузка #' . (int) $upload['id'], $content, $admin);
    }

    public function products(Request $request): never
    {
        $admin = $this->currentAdmin();
        $q = trim((string) ($request->query()['q'] ?? ''));
        $sql = 'SELECT p.id, p.name, p.barcode, p.article, c.name AS category_name,
                       (SELECT COUNT(*) FROM stock s WHERE s.product_id = p.id) AS branches_count
                FROM products p LEFT JOIN categories c ON c.id = p.category_id
                WHERE p.is_active = 1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (p.name LIKE ? OR p.barcode LIKE ? OR p.article LIKE ?)';
            $needle = '%' . mb_substr($q, 0, 100) . '%';
            $params = [$needle, $needle, $needle];
        }
        $sql .= ' ORDER BY p.name LIMIT 100';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                $admin['role'] === 'admin' ? '<a href="/admin/products/' . (int) $row['id'] . '">#' . (int) $row['id'] . '</a>' : (int) $row['id'],
                AdminView::e($row['name']),
                AdminView::e($row['barcode']),
                AdminView::e($row['article']),
                AdminView::e($row['category_name']),
                (int) $row['branches_count'],
            ];
        }
        $content = '<section class="card"><form method="get" action="/admin/products"><label>Поиск по названию, штрихкоду или артикулу'
            . '<input name="q" value="' . AdminView::e($q) . '" placeholder="Например, молоко или 460..."></label><button>Найти</button></form></section>'
            . AdminView::table(['ID', 'Название', 'Штрихкод', 'Артикул', 'Категория', 'Филиалов с остатками'], $rows, 'Товары не найдены.');
        $this->page('Товары', $content, $admin);
    }


    public function productEdit(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        $stmt = $this->db->prepare('SELECT id, barcode, article, name, category_id, manufacturer, unit, is_active, image_url, description FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$params['id']]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            Response::html('<!doctype html><meta charset="utf-8"><h1>Товар не найден</h1><a href="/admin/products">Назад</a>', 404);
        }

        $categories = $this->db->query('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        $branches = $this->db->query(
            'SELECT b.id, b.name, s.price, s.quantity
             FROM branches b LEFT JOIN stock s ON s.branch_id = b.id AND s.product_id = ' . (int) $product['id'] . '
             WHERE b.is_active = 1 ORDER BY b.name'
        )->fetchAll(PDO::FETCH_ASSOC);

        $content = $this->flashHtml() . '<section class="card"><form method="post" action="/admin/products/' . (int) $product['id'] . '">'
            . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'
            . '<label>Название<input name="name" maxlength="500" value="' . AdminView::e($product['name']) . '" required></label>'
            . '<label>Штрихкод<input name="barcode" maxlength="64" value="' . AdminView::e($product['barcode']) . '"></label>'
            . '<label>Артикул<input name="article" maxlength="100" value="' . AdminView::e($product['article']) . '"></label>'
            . '<label>Категория<select name="category_id"><option value="">Без категории</option>';
        foreach ($categories as $category) {
            $selected = (int) $product['category_id'] === (int) $category['id'] ? ' selected' : '';
            $content .= '<option value="' . (int) $category['id'] . '"' . $selected . '>' . AdminView::e($category['name']) . '</option>';
        }
        $content .= '</select></label><label>Производитель<input name="manufacturer" maxlength="190" value="' . AdminView::e($product['manufacturer']) . '"></label>'
            . '<label>Единица измерения<input name="unit" maxlength="32" value="' . AdminView::e($product['unit']) . '"></label>'
            . '<label>Описание товара<textarea name="description" rows="6" maxlength="10000">' . AdminView::e($product['description'] ?? '') . '</textarea></label>'
            . '<label><input type="checkbox" name="is_active" value="1" style="display:inline;width:auto" ' . ((int) $product['is_active'] === 1 ? 'checked' : '') . '> Товар активен</label>'
            . '<h2>Цена и остаток по филиалам</h2><p class="muted">Оставьте оба поля пустыми, если не нужно менять данные филиала.</p>';
        foreach ($branches as $branch) {
            $id = (int) $branch['id'];
            $content .= '<div class="card"><b>' . AdminView::e($branch['name']) . '</b>'
                . '<label>Цена<input name="stock[' . $id . '][price]" inputmode="decimal" placeholder="Например, 89.90" value="' . AdminView::e($branch['price']) . '"></label>'
                . '<label>Остаток<input name="stock[' . $id . '][quantity]" inputmode="decimal" placeholder="Например, 12.000" value="' . AdminView::e($branch['quantity']) . '"></label></div>';
        }
        $content .= '<button type="submit">Сохранить изменения</button> <a href="/admin/products">Отмена</a></form></section>';
        $photoUrl = (string) ($product['image_url'] ?? '');

        $photoHtml = $photoUrl !== ''

            ? '<p><img src="' . AdminView::e($photoUrl) . '" alt="Фото товара" style="max-width:250px;width:100%;height:auto;border-radius:8px"></p>'

            : '<p class="muted">Фотография пока не загружена.</p>';



        $content .= '<section class="card"><h2>Фотография товара</h2>'

            . $photoHtml

            . '<form id="product-photo-form" method="post" enctype="multipart/form-data" action="/admin/products/' . (int) $product['id'] . '/photo">'

            . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'

            . '<label>Выберите фотографию<input type="file" name="product_photo" accept="image/jpeg,image/png,image/webp,image/*" required></label>'

            . '<button type="submit">Загрузить фотографию</button>'

            . '<p id="product-photo-status" class="muted"></p>'

            . '</form></section>'

            . '<script src="/assets/product-photo.js" defer></script>';



        $admin['csrf'] = $this->auth->csrfToken();
        $this->page('Редактирование товара #' . (int) $product['id'], $content, $admin);
    }

    public function updateProduct(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/products/' . (int) $params['id']);
        }

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
        $stmt->execute([$params['id']]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            Response::html('<!doctype html><meta charset="utf-8"><h1>Товар не найден</h1>', 404);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $barcode = trim((string) ($data['barcode'] ?? ''));
        $article = trim((string) ($data['article'] ?? ''));
        $manufacturer = trim((string) ($data['manufacturer'] ?? ''));
        $unit = trim((string) ($data['unit'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $categoryRaw = (string) ($data['category_id'] ?? '');
        if ($name === '' || mb_strlen($name) > 500 || strlen($barcode) > 64 || strlen($article) > 100
            || mb_strlen($manufacturer) > 190 || mb_strlen($unit) > 32
            || mb_strlen($description) > 10000) {
            $this->flash('Проверьте название и длину дополнительных полей товара.', true);
            Response::redirect('/admin/products/' . (int) $params['id']);
        }
        $categoryId = null;
        if ($categoryRaw !== '') {
            $categoryId = filter_var($categoryRaw, FILTER_VALIDATE_INT);
            $checkCategory = $this->db->prepare('SELECT id FROM categories WHERE id = ? AND is_active = 1 LIMIT 1');
            if ($categoryId === false || $categoryId < 1) {
                $this->flash('Выбрана некорректная категория.', true);
                Response::redirect('/admin/products/' . (int) $params['id']);
            }
            $checkCategory->execute([$categoryId]);
            if (!$checkCategory->fetchColumn()) {
                $this->flash('Выбранная категория недоступна.', true);
                Response::redirect('/admin/products/' . (int) $params['id']);
            }
        }

        $newValues = [
            'name' => $name,
            'barcode' => $barcode !== '' ? $barcode : null,
            'article' => $article !== '' ? $article : null,
            'category_id' => $categoryId,
            'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
            'unit' => $unit !== '' ? $unit : null,
            'description' => $description !== '' ? $description : null,
            'is_active' => isset($data['is_active']) ? 1 : 0,
        ];
        $changes = [];
        foreach ($newValues as $field => $value) {
            if ((string) ($product[$field] ?? '') !== (string) ($value ?? '')) {
                $changes[$field] = $value;
            }
        }

        try {
            $this->db->beginTransaction();
            if ($changes) {
                $sets = implode(', ', array_map(static fn(string $field): string => $field . ' = ?', array_keys($changes)));
                $update = $this->db->prepare('UPDATE products SET ' . $sets . ' WHERE id = ?');
                $update->execute([...array_values($changes), (int) $product['id']]);
            }

            $stockChanges = [];
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Barnaul')))->format('Y-m-d');
            foreach ((is_array($data['stock'] ?? null) ? $data['stock'] : []) as $branchKey => $values) {
                $branchId = filter_var($branchKey, FILTER_VALIDATE_INT);
                if ($branchId === false || $branchId < 1 || !is_array($values)) {
                    continue;
                }
                $priceRaw = trim((string) ($values['price'] ?? ''));
                $quantityRaw = trim((string) ($values['quantity'] ?? ''));
                if ($priceRaw === '' && $quantityRaw === '') {
                    continue;
                }
                if ($priceRaw === '' || $quantityRaw === '') {
                    throw new \InvalidArgumentException('Для филиала укажите и цену, и остаток либо оставьте оба поля пустыми.');
                }
                $price = $this->normalizeDecimal($priceRaw, 2, 10, 'Цена');
                $quantity = $this->normalizeDecimal($quantityRaw, 3, 9, 'Остаток');
                $branchCheck = $this->db->prepare('SELECT id FROM branches WHERE id = ? AND is_active = 1 LIMIT 1');
                $branchCheck->execute([$branchId]);
                if (!$branchCheck->fetchColumn()) {
                    throw new \InvalidArgumentException('Один из филиалов не найден или отключён.');
                }

                $stockFind = $this->db->prepare('SELECT price, quantity FROM stock WHERE product_id = ? AND branch_id = ? FOR UPDATE');
                $stockFind->execute([(int) $product['id'], $branchId]);
                $stock = $stockFind->fetch(PDO::FETCH_ASSOC);
                if (!$stock) {
                    $insertStock = $this->db->prepare('INSERT INTO stock (product_id, branch_id, price, quantity, imported_on) VALUES (?, ?, ?, ?, ?)');
                    $insertStock->execute([(int) $product['id'], $branchId, $price, $quantity, $today]);
                    $priceChanged = true;
                    $stockChanges[] = ['branch_id' => $branchId, 'created' => true, 'price_changed' => true];
                } else {
                    $priceChanged = number_format((float) $stock['price'], 2, '.', '') !== $price;
                    $quantityChanged = number_format((float) $stock['quantity'], 3, '.', '') !== $quantity;
                    if ($priceChanged || $quantityChanged) {
                        $updateStock = $this->db->prepare('UPDATE stock SET price = ?, quantity = ? WHERE product_id = ? AND branch_id = ?');
                        $updateStock->execute([$price, $quantity, (int) $product['id'], $branchId]);
						// Уведомляем только при поступлении ранее отсутствовавшего товара.
// Первоначальное создание записи stock не считается поступлением.
if ((float) $stock['quantity'] <= 0 && (float) $quantity > 0) {
    (new \Svet22\Services\RestockNotificationService($this->db))
        ->queueTransition(
            (int) $product['id'],
            (int) $branchId,
            (string) $stock['quantity'],
            (string) $quantity
        );
}
                        $stockChanges[] = ['branch_id' => $branchId, 'created' => false, 'price_changed' => $priceChanged];
                    }
                }
                if ($priceChanged) {
                    $history = $this->db->prepare(
                        'INSERT INTO price_history (product_id, branch_id, price, recorded_date)
                         VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)'
                    );
                    $history->execute([(int) $product['id'], $branchId, $price, $today]);
                }
            }
            $this->auth->audit((int) $admin['id'], 'product.update', 'product', (int) $product['id'], [
                'product_fields' => array_keys($changes),
                'stock_changes' => $stockChanges,
            ], $request->clientIp());
            $this->db->commit();
        } catch (\InvalidArgumentException $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            $this->flash($e->getMessage(), true);
            Response::redirect('/admin/products/' . (int) $params['id']);
        } catch (\PDOException $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ((string) $e->getCode() === '23000') {
                $this->flash('Такой штрихкод уже привязан к другому товару.', true);
                Response::redirect('/admin/products/' . (int) $params['id']);
            }
            throw $e;
        }

        $this->flash('Изменения товара сохранены.');
        Response::redirect('/admin/products/' . (int) $params['id']);
    }

    private function normalizeDecimal(string $value, int $scale, int $maxIntegerDigits, string $label): string
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/^\\d+(?:\\.\\d+)?$/', $value)) {
            throw new \InvalidArgumentException($label . ': укажите неотрицательное число.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > $maxIntegerDigits || strlen($fraction) > $scale) {
            throw new \InvalidArgumentException($label . ': превышена допустимая точность.');
        }
        return $whole . '.' . str_pad($fraction, $scale, '0');
    }

    public function categories(Request $request): never
    {
        $admin = $this->currentAdmin();
        $rows = [];
        foreach ($this->db->query('SELECT id, name, slug, sort_order, is_active FROM categories ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [(int) $row['id'], AdminView::e($row['name']), AdminView::e($row['slug']), (int) $row['sort_order'], (int) $row['is_active'] ? 'Активна' : 'Отключена', $admin['role'] === 'admin' ? '<form method="post" action="/admin/categories/' . (int) $row['id'] . '/toggle"><input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '"><button class="link" type="submit">' . ((int) $row['is_active'] ? 'Отключить' : 'Включить') . '</button></form>' : '—'];
        }
        $content = $this->flashHtml();
        if ($admin['role'] === 'admin') {
            $content .= '<section class="card"><h2>Добавить категорию</h2><form method="post" action="/admin/categories">'
                . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'
                . '<label>Название<input name="name" maxlength="190" required></label><button>Добавить</button></form></section>';
        }
        $content .= AdminView::table(['ID', 'Название', 'URL-ключ', 'Порядок', 'Статус', 'Действие'], $rows, 'Категорий пока нет.');
        $this->page('Категории', $content, $admin);
    }

    public function createCategory(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/categories');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 190) {
            $this->flash('Название категории должно содержать от 1 до 190 символов.', true);
            Response::redirect('/admin/categories');
        }
        $baseSlug = $this->slug($name);
        $slug = $baseSlug;
        $suffix = substr(hash('sha256', $name), 0, 8);
        $check = $this->db->prepare('SELECT id FROM categories WHERE parent_id IS NULL AND slug = ? LIMIT 1');
        $attempt = 0;
        while (true) {
            $check->execute([$slug]);
            if (!$check->fetchColumn()) {
                break;
            }
            $attempt++;
            $slug = $baseSlug . '-' . $suffix . ($attempt > 1 ? '-' . $attempt : '');
        }
        $insert = $this->db->prepare('INSERT INTO categories (parent_id, name, slug) VALUES (NULL, ?, ?)');
        $insert->execute([$name, $slug]);
        $id = (int) $this->db->lastInsertId();
        $this->auth->audit((int) $admin['id'], 'category.create', 'category', $id, ['name' => $name], $request->clientIp());
        $this->flash('Категория добавлена.');
        Response::redirect('/admin/categories');
    }

    public function branches(Request $request): never
    {
        $admin = $this->currentAdmin();
        $rows = [];
        foreach ($this->db->query('SELECT id, code, name, address, is_active FROM branches ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [(int) $row['id'], AdminView::e($row['code']), AdminView::e($row['name']), AdminView::e($row['address']), (int) $row['is_active'] ? 'Активен' : 'Отключён', $admin['role'] === 'admin' ? '<form method="post" action="/admin/branches/' . (int) $row['id'] . '/toggle"><input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '"><button class="link" type="submit">' . ((int) $row['is_active'] ? 'Отключить' : 'Включить') . '</button></form>' : '—'];
        }
        $content = $this->flashHtml();
        if ($admin['role'] === 'admin') {
            $content .= '<section class="card"><h2>Добавить филиал</h2><form method="post" action="/admin/branches">'
                . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'
                . '<label>Код (латиница, цифры, дефис)<input name="code" maxlength="50" pattern="[a-zA-Z0-9_-]+" required></label>'
                . '<label>Название<input name="name" maxlength="190" required></label>'
                . '<label>Адрес<input name="address" maxlength="500"></label><button>Добавить филиал</button></form></section>';
        }
        $content .= AdminView::table(['ID', 'Код', 'Название', 'Адрес', 'Статус', 'Действие'], $rows, 'Филиалов пока нет.');
        $this->page('Филиалы', $content, $admin);
    }

    public function createBranch(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/branches');
        }
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $address = trim((string) ($data['address'] ?? ''));
        if (!preg_match('/^[a-zA-Z0-9_-]{2,50}$/', $code) || $name === '' || mb_strlen($name) > 190 || mb_strlen($address) > 500) {
            $this->flash('Проверьте код, название и адрес филиала.', true);
            Response::redirect('/admin/branches');
        }
        try {
            $insert = $this->db->prepare('INSERT INTO branches (code, name, address) VALUES (?, ?, ?)');
            $insert->execute([$code, $name, $address !== '' ? $address : null]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->flash('Филиал с таким кодом уже существует.', true);
                Response::redirect('/admin/branches');
            }
            throw $e;
        }
        $id = (int) $this->db->lastInsertId();
        $this->auth->audit((int) $admin['id'], 'branch.create', 'branch', $id, ['code' => $code], $request->clientIp());
        $this->flash('Филиал добавлен.');
        Response::redirect('/admin/branches');
    }


    public function toggleCategory(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        if (!$this->auth->verifyCsrf($request->post()['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/categories');
        }
        $stmt = $this->db->prepare('UPDATE categories SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
        $stmt->execute([$params['id']]);
        if ($stmt->rowCount() > 0) {
            $this->auth->audit((int) $admin['id'], 'category.toggle', 'category', (int) $params['id'], [], $request->clientIp());
        }
        $this->flash('Статус категории изменён.');
        Response::redirect('/admin/categories');
    }

    public function toggleBranch(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        if (!$this->auth->verifyCsrf($request->post()['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/branches');
        }
        $stmt = $this->db->prepare('UPDATE branches SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
        $stmt->execute([$params['id']]);
        if ($stmt->rowCount() > 0) {
            $this->auth->audit((int) $admin['id'], 'branch.toggle', 'branch', (int) $params['id'], [], $request->clientIp());
        }
        $this->flash('Статус филиала изменён.');
        Response::redirect('/admin/branches');
    }

    public function users(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $rows = [];
        foreach ($this->db->query('SELECT id, username, role, is_active, last_login_at FROM admins ORDER BY username')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                (int) $row['id'],
                AdminView::e($row['username']),
                AdminView::e($row['role'] === 'admin' ? 'Администратор' : 'Менеджер'),
                (int) $row['is_active'] ? 'Активен' : 'Отключён',
                AdminView::e($row['last_login_at'] ?? '—'),
                (int) $row['id'] !== (int) $admin['id']
                    ? '<form method="post" action="/admin/users/' . (int) $row['id'] . '/toggle"><input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '"><button class="link" type="submit">' . ((int) $row['is_active'] ? 'Отключить' : 'Включить') . '</button></form>'
                    : 'Текущая учётная запись',
            ];
        }
        $content = $this->flashHtml() . '<section class="card"><h2>Добавить пользователя</h2><form method="post" action="/admin/users">'
            . '<input type="hidden" name="csrf_token" value="' . AdminView::e($this->auth->csrfToken()) . '">'
            . '<label>Логин<input name="username" maxlength="100" pattern="[a-zA-Z0-9._-]{3,100}" required></label>'
            . '<label>Пароль (не менее 12 символов)<input type="password" name="password" minlength="12" maxlength="4096" autocomplete="new-password" required></label>'
            . '<label>Роль<select name="role"><option value="manager">Менеджер</option><option value="admin">Администратор</option></select></label>'
            . '<button type="submit">Создать пользователя</button></form></section>'
            . AdminView::table(['ID', 'Логин', 'Роль', 'Статус', 'Последний вход', 'Действие'], $rows);
        $this->page('Пользователи', $content, $admin);
    }

    public function createUser(Request $request): never
    {
        $admin = $this->auth->requireRole('admin');
        $data = $request->post();
        if (!$this->auth->verifyCsrf($data['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/users');
        }
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = (string) ($data['role'] ?? 'manager');
        if (!preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $username) || strlen($password) < 12 || strlen($password) > 4096
            || !in_array($role, ['admin', 'manager'], true)) {
            $this->flash('Проверьте логин, пароль и роль.', true);
            Response::redirect('/admin/users');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $this->db->prepare('INSERT INTO admins (username, password_hash, role, is_active) VALUES (?, ?, ?, 1)');
            $stmt->execute([$username, $hash, $role]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                $this->flash('Пользователь с таким логином уже существует.', true);
                Response::redirect('/admin/users');
            }
            throw $e;
        }
        $id = (int) $this->db->lastInsertId();
        $this->auth->audit((int) $admin['id'], 'admin.create', 'admin', $id, ['username' => $username, 'role' => $role], $request->clientIp());
        $this->flash('Пользователь создан.');
        Response::redirect('/admin/users');
    }

    public function toggleUser(Request $request, array $params): never
    {
        $admin = $this->auth->requireRole('admin');
        if ((int) $params['id'] === (int) $admin['id']) {
            $this->flash('Нельзя отключить собственную учётную запись.', true);
            Response::redirect('/admin/users');
        }
        if (!$this->auth->verifyCsrf($request->post()['csrf_token'] ?? null)) {
            $this->flash('Форма устарела.', true);
            Response::redirect('/admin/users');
        }

        $error = null;
        try {
            $this->db->beginTransaction();
            // Блокируем активные записи администраторов, чтобы два параллельных запроса
            // не смогли одновременно отключить друг друга и оставить систему без admin.
            $activeAdmins = $this->db->query(
                "SELECT id FROM admins WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
            )->fetchAll(PDO::FETCH_COLUMN);

            $target = $this->db->prepare('SELECT role, is_active FROM admins WHERE id = ? LIMIT 1 FOR UPDATE');
            $target->execute([$params['id']]);
            $targetUser = $target->fetch(PDO::FETCH_ASSOC);
            if (!$targetUser) {
                $error = 'Пользователь не найден.';
            } elseif ($targetUser['role'] === 'admin' && (int) $targetUser['is_active'] === 1 && count($activeAdmins) <= 1) {
                $error = 'Нельзя отключить последнего активного администратора.';
            } else {
                $stmt = $this->db->prepare('UPDATE admins SET is_active = IF(is_active = 1, 0, 1) WHERE id = ?');
                $stmt->execute([$params['id']]);
                if ($stmt->rowCount() > 0) {
                    $this->auth->audit((int) $admin['id'], 'admin.toggle', 'admin', (int) $params['id'], [], $request->clientIp());
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        if ($error !== null) {
            $this->flash($error, true);
        } else {
            $this->flash('Статус пользователя изменён.');
        }
        Response::redirect('/admin/users');
    }

    private function currentAdmin(): array
    {
        $admin = $this->auth->requireUser();
        $admin['csrf'] = $this->auth->csrfToken();
        return $admin;
    }

    private function page(string $title, string $content, array $admin): never
    {
        Response::html(AdminView::layout($title, $content, $admin));
    }

    private function activeBranches(): array
    {
        return $this->db->query('SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function flash(string $message, bool $error = false): void
    {
        $this->auth->start();
        $_SESSION['flash'] = ['message' => $message, 'error' => $error];
    }

    private function flashHtml(): string
    {
        $this->auth->start();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        if (!is_array($flash) || !isset($flash['message'])) {
            return '';
        }
        return AdminView::alert((string) $flash['message'], (bool) ($flash['error'] ?? false));
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'processing' => 'Выполняется',
            'completed' => 'Завершена',
            'failed' => 'Ошибка',
            default => $status,
        };
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', (string) $transliterated) ?? '');
        return trim($slug, '-') ?: 'category-' . substr(hash('sha256', $value), 0, 10);
    }
}
