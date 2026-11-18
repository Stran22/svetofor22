# Светофор — таблицы MySQL и миграции

Источник: `database/migrations/*.sql`, ветка `feature/excel-column-mapping`, GitHub HEAD аудита `f24584ff7bc1a6be9f25c962d55d12556b594021` (18.11.2026). **Схема файлов не гарантирует, что все изменения уже применены на production.**

## Таблицы
| Таблица | Назначение и основные поля |
|---|---|
| `schema_migrations` | журнал имён применённых SQL файлов, `batch`, `applied_at` |
| `branches` | филиалы: `code` (unique), `name`, `address`, `is_active` |
| `categories` | дерево: `parent_id`, `name`, `slug`, `sort_order`, `is_active`, `image_url` |
| `products` | `barcode` (unique), `article`, `name`, `category_id`, `manufacturer`, `unit`, `image_url`, `description` |
| `stock` | `product_id`, `branch_id`, `price DECIMAL(12,2)`, `quantity DECIMAL(12,3)`, `imported_on`; UNIQUE(product_id,branch_id) |
| `price_history` | история цены по товару+филиалу+дню; UNIQUE(product_id,branch_id,recorded_date) |
| `users` | `device_id`, `display_name`, `is_guest`, `push_enabled` |
| `api_tokens` | `user_id`, `token_hash`, `expires_at`, `revoked_at` |
| `social_accounts` | `user_id`, `provider` (vk/ok/yandex), `provider_user_id`; уникальность по provider+provider_user_id |
| `oauth_states`, `oauth_handoff_codes` | состояние OAuth и одноразовые handoff коды |
| `subscriptions` | `user_id`, `product_id`, `branch_id`, `status`, `notified_at`, `push_attempts` |
| `admins` | `username`, `password_hash`, `role`, `is_active` |
| `upload_log` | журнал XLSX по `branch_id`, `file_sha256`, `recorded_date`, `status`, счетчики строк |
| `import_errors` | ошибки по `upload_id`, `source_row_number`, `error_code`, `row_data` |
| `reviews` | `user_id`, `product_id`, `rating` (1..5), `review_text` (до 500), `status`; UNIQUE(user_id,product_id) |
| `api_rate_limits` | агрегат ограничений по запросам |
| `admin_login_attempts`, `admin_audit_log` | защита входа и действия администраторов |
| `user_push_tokens` | FCM токены пользователей |
| `push_delivery_log` | события постановки push в очередь и статусы |
| `push_device_deliveries` | статус push на конкретном устройстве |
| `excel_column_mappings` | JSON-сопоставление столбцов XLSX, UNIQUE(branch_id) |
| `search_events` | текст запроса, филиал, пользователь, число результатов, время |
| `featured_products` | «Супер выгода», порядок/активность |
| `home_banners` | баннеры главной |
| `contests`, `contest_participants` | конкурсы и участники |

## Миграции по сортировке полного имени
```text
001_initial_schema.sql
002_admin_panel.sql
003_firebase_push.sql
004_push_device_deliveries.sql
005_excel_column_mappings.sql
006_category_display_settings.sql
006_import_mapping_fingerprint.sql
006_search_analytics.sql
006_upload_log_mapping_hash.sql
007_product_wide_subscriptions.sql
007_super_value_products.sql
008_home_banners_contests.sql
```
- `006_category_display_settings.sql`: `ALTER categories ADD hide_store_stock TINYINT(1) DEFAULT 0`. Наследование через `CategoryVisibilityService`.
- `006_import_mapping_fingerprint.sql`: `ALTER upload_log ADD mapping_sha256 CHAR(64)` + индекс `idx_upload_duplicate_mapping`.
- `006_search_analytics.sql`: создаёт `search_events`.
- `006_upload_log_mapping_hash.sql`: no-op `SELECT 1`, сохранён для совместимости.
- **`007_product_wide_subscriptions.sql` сначала делает `DELETE FROM subscriptions;`**, затем меняет `branch_id` на NULL, UNIQUE(user_id, product_id), добавляет `push_delivery_log.branch_id`. Выполнять только после проверки производственной БД и резервной копии.
- `007_super_value_products.sql` и `008_home_banners_contests.sql` добавляют промо и конкурсы.
- `bin/migrate.php` сортирует **имена файлов**, сравнивает их с `schema_migrations.migration`, не с номером. Не переименовывать старые миграции.

## Главные инварианты
- Одна строка `stock` на товар и филиал; значение quantity = 0 означает отсутствие; отсутствие строки **не** равно нулевому остатку.
- Подписка создаётся только если у каждого **активного** филиала есть строка stock с quantity = 0; после миграции 007 она уникальна по пользователю+товару, `branch_id=NULL`.
- `push_delivery_log.branch_id` указывает филиал, в котором обнаружено поступление.
- Скрытие блока цен и остатков по категорий задаётся `categories.hide_store_stock`, включая родительское ограничение.

## Проверка на сервере (read-only SQL)
```sql
SELECT migration,batch,applied_at FROM schema_migrations ORDER BY id;
SHOW CREATE TABLE categories;
SHOW CREATE TABLE subscriptions;
SHOW CREATE TABLE upload_log;
SHOW CREATE TABLE push_delivery_log;
```
Без явного согласия не выполнять ALTER, DELETE или запуск мигратора на production.

### Подтверждённая production-схема `categories` (phpMyAdmin, 2026-11-18)
Пользователь выполнил `SHOW COLUMNS FROM categories;` в MySQL базе `prorock_law`. Столбцы: `id bigint unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT`; `parent_id bigint unsigned NULL INDEX`; `name varchar(190) NOT NULL`; `slug varchar(220) NOT NULL`; `image_url varchar(1000) NULL`; `sort_order int NOT NULL DEFAULT 0`; `is_active tinyint(1) NOT NULL DEFAULT 1 INDEX`; **`show_price_stock tinyint(1) NOT NULL DEFAULT 1`**; `created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP`; `updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.
**Критическое расхождение:** production уже имеет `show_price_stock` (1 = показывать цену/остатки; 0 = скрывать), тогда как текущая реализация GitHub использует `hide_store_stock` (инвертированная семантика). **Не добавлять дублирующий столбец и не применять миграцию вслепую**. Перед патчем сверить все места чтения/записи и наследование настройки в категориях; сохранить существующие значения.

### Проверка значений `categories.show_price_stock` (phpMyAdmin, 2026-11-18)
Пользователь выполнил read-only запрос `SELECT show_price_stock, COUNT(*) AS categories_count FROM categories GROUP BY show_price_stock ORDER BY show_price_stock;` в production MySQL `prorock_law`. Единственная строка результата: **`show_price_stock = 1`, `categories_count = 102`**. Следовательно, на момент проверки все 102 категории показывают цены/остатки; строк со значением 0 нет. Это снимок данных, не гарантия неизменности. При адаптации GitHub не создавать второй флаг `hide_store_stock`; сохранить `show_price_stock` и обратную семантику.

### Подтверждение использования поля в production контроллере (2026-11-18)
`AdminCategoriesController::all()` делает `SELECT id, parent_id, name, slug, sort_order, image_url, is_active FROM categories ORDER BY sort_order, name, id` — **не выбирает** `show_price_stock`. `one()` делает `SELECT * FROM categories WHERE id=?` — **получает** существующий столбец. `fields()` валидирует `name`, `parent_id`, `sort_order`, HTTPS `image_url` и возвращает массив из 4 значений, **не читает** чекбокс. В production таблице поле уже существует, все 102 значения = 1; миграция для добавления поля не нужна.

### 2026-11-18 — подтверждена production таблица stock
По выполненному пользователем в phpMyAdmin `SHOW COLUMNS FROM stock` (БД `prorock_law`): `id bigint unsigned NOT NULL PRIMARY KEY AUTO_INCREMENT`, `product_id bigint unsigned NOT NULL INDEX`, `branch_id bigint unsigned NOT NULL INDEX`, `price decimal(12,2) NOT NULL` (без default), `quantity decimal(12,3) NOT NULL DEFAULT 0.000`, `imported_on date NULL`, `updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (индекс). При скрытии цен и остатков **не записывать NULL в stock**; маскировать значения `price`/`quantity` только в JSON API. Не выводить URL с сессионным токеном phpMyAdmin.
