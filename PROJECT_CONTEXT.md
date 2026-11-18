# Светофор — оперативный контекст

Дата аудита GitHub: **2026-11-18**. Репозиторий: https://github.com/Stran22/svetofor22 . Ветка: `feature/excel-column-mapping`.
**Исходный проверенный HEAD аудита:** `f24584ff7bc1a6be9f25c962d55d12556b594021` (2026-11-17). Это именно база аудита; для нового чата всегда считывать текущий HEAD.

## Где что находится
- Реестр маршрутов: `backend/bootstrap/app.php`.
- Backend: `backend/app/Controllers/`, `backend/app/Services/`, `backend/app/Core/`.
- Точка входа: `backend/public/index.php`; Apache: `backend/public/.htaccess`.
- Схема: `database/migrations/*.sql`; мигратор: `bin/migrate.php`.
- Cron-скрипты FCM: `bin/process_push_queue.php` и `bin/process_push_queue_v2.php`; выбранный серверный cron следует проверить.
- Android: `android/app/src/main/java/ru/svet22/app/`; API `data/remote/SvetApi.kt`; Retrofit `data/remote/RetrofitClient.kt`; карточка товара `ui/catalog/ProductDetailScreen.kt`.
- CI: `.github/workflows/php-checks.yml`, `excel-import-safety.yml`, `android-build.yml`.

## Реализовано в проверенном коде
- PHP/MySQL каталог с филиалами, категориями, товарами, остатками и ценами; Android Jetpack Compose, Retrofit.
- XLSX import с ручным сопоставлением колонок на филиал, проверкой файла и fingerprint сопоставления, логом/ошибками.
- VK ID, отзывы/модерация, FCM и подписки, поисковая аналитика, «Супер выгода», баннеры и конкурсы.
- Кнопка «Следить за товаром» показывается по `can_track` только при известном **нулевом** остатке во **всех активных филиалах**, а создание подписки перепроверяет условие. Одна подписка на user + product; `branch_id=NULL`.
- Админское управление `categories.hide_store_stock` и наследование на дочерние категории, скрытие блока цен/остатков на Android-карточке.

## Текущая задача и следующий шаг
**Текущий этап — постоянная техдокументация.** Файлы `AGENTS.md`, `PROJECT_CONTEXT.md`, `CHANGELOG.md`, `docs/DATABASE.md`, `docs/API_ROUTES.md`, `docs/SERVER_CONFIG.md`, `docs/FEATURES.md` создаются по коду. После этого: проверить реально применённые миграции и cron на хостинге, проверить статусы GitHub Actions, затем получать конкретное ТЗ на изменения.

## Аудиторские замечания
1. В `database/migrations` несколько файлов с одинаковыми номерными префиксами `006_` и `007_`: порядок определяется **полным именем** (`bin/migrate.php`), журнал — `schema_migrations.migration`.
2. `007_product_wide_subscriptions.sql` **удаляет все подписки** перед перестройкой ограничения. Не запускать повторно/на живой БД без анализа.
3. Исторические `docs/architecture.md` и `docs/api-contract.md` описывают старую модель подписки по филиалу и планировавшиеся несуществующие маршруты; `android/README.md` также содержит устаревший список «следующих шагов».
4. Доступ к коду на GitHub **не доказывает**, что migration/cron уже работают в production.
5. Подтверждения новой сборки Android и тестов PHP **в ходе этого документационного аудита нет** — их не считать пройденными.

## Документация
[БД](docs/DATABASE.md) · [API](docs/API_ROUTES.md) · [Окружение](docs/SERVER_CONFIG.md) · [Функции](docs/FEATURES.md) · [Правила](AGENTS.md) · [Изменения](CHANGELOG.md).

## Дополнение 2026-11-18 — проверка реального сервера
Пользователь предоставил результаты команд ISPmanager и фрагменты `AdminCategoriesController.php`, `ApiController.php`. В серверной версии `AdminCategoriesController` нет формы `hide_store_stock` и метода `saveVisibility`, а в `ApiController` карточка возвращает `branches` и `reviews`, но в предоставленном участке не возвращает `can_track`/`hide_store_stock`. В GitHub эти возможности **уже реализованы по исходникам**. Поэтому не создавать параллельное поле `show_price_stock`: использовать существующий контракт `hide_store_stock` после безопасной синхронизации версий.

Путь сервера: `/var/www/prorock/data/www/baza.altaj-otdyh.ru/`; терминал стартует в `/var/www/prorock/data`. Есть отдельная папка `.deploy/`. На сервере Android `MainActivity.kt` выглядит как более простой клиент, тогда как GitHub содержит `ui/catalog/ProductDetailScreen.kt`. Состояние установленного APK и web-root пока не подтверждено.

**Следующая задача:** установить активную точку входа Apache и фактическую версию PHP без записи на сервер; сравнить миграции и `SHOW COLUMNS FROM categories`, `SHOW CREATE TABLE subscriptions`, `schema_migrations`; проверить актуальный Android-исходник и CI. До этого не запускать `007_product_wide_subscriptions.sql` (удаляет подписки), не заменять production-файлы, не менять дизайн. Отдельно учесть возможную ошибку пагинации в старом серверном `ApiController::products`: `LIMIT` после `LEFT JOIN stock` ограничивает строки, не уникальные товары.

### Подтверждение HTTP-точки входа (18.11.2026)
В ISPmanager для `baza.altaj-otdyh.ru` пользователь подтвердил корень `/backend/public` и PHP 8.5; CLI `/opt/php85/bin/php` = 8.5.12. Активный `backend/public/index.php` содержит `require dirname(__DIR__) . '/bootstrap/app.php';`, то есть загружает **основной** `backend/bootstrap/app.php`, не `.deploy`. Точная версия PHP через HTTP и SAPI ещё не измерены. Следующий read-only шаг — посмотреть маршруты и подключения в `/var/www/prorock/data/www/baza.altaj-otdyh.ru/backend/bootstrap/app.php`. Важные новые подтверждения сразу заносить в `docs/` и оперативный контекст, чтобы не запрашивать повторно.

### Продолжение диагностики (18.11.2026)
Из серверного `backend/bootstrap/app.php` пользователь предоставил grep: Composer autoload из корневого `vendor/autoload.php`, создание `Router`, `ApiController`, `AdminCategoriesController`, `SubscriptionController`. Это подтверждает, что HTTP-точка входа `backend/public/index.php` загружает основное приложение и эти контроллеры, а не `.deploy` (при условии обычного Composer PSR-4 разрешения). Следующий шаг — read-only просмотр маршрутов и сопоставление с GitHub, без деплоя и миграций.

### Маршруты работающего bootstrap (18.11.2026)
Из `backend/bootstrap/app.php` подтверждены API каталога/карточки товара, рекомендуемых товаров, акций, конкурсов, отзывов, подписок и админ-маршруты категорий. В grep отсутствует `/admin/categories/visibility` (есть `/admin/categories`, `toggle`, `delete`, `edit`), хотя GitHub реализует управление скрытием цены/остатка. Следующий безопасный этап: сверить полный bootstrap и конкретные серверные методы/поля, прежде чем менять код или применять миграции. Не считать наличие маршрута подписок подтверждением работы правила `can_track`.

### Следующий результат проверки (18.11.2026)
В серверном `backend/app/Controllers/ApiController.php` grep показал `categories()` на строке 56 и `product()` на строке 188; **`can_track` и `hide_store_stock` не найдены**. Это подтверждает расхождение именованных API-полей с GitHub, но не доказывает отсутствия эквивалентной логики в иных файлах. Следующий read-only шаг — изучить фрагмент `product()` на сервере и определить фактический JSON, затем сравнить с GitHub; без миграций и замены контроллеров.

### Прочитан реальный метод карточки товара (18.11.2026)
В production `backend/app/Controllers/ApiController.php` метод `product()` (начинается на строке 188) читает активный товар, все строки `stock` по product_id (без `branches.is_active`), агрегат опубликованных отзывов, затем `Response::success`. Нет полей `can_track`, `hide_store_stock`. Нельзя заменять файл целиком без сверки других методов: сохранить отзывы, API, импорт, авторизацию и подписки. Следующий этап — проверить реальные столбцы `categories` и активные филиалы, затем разработать минимальный безопасный патч.

### Проверка server grep для настройки категорий (18.11.2026)
Поиск `hide_store_stock|show_price_stock|ALTER TABLE categories` в серверных `database/migrations` и `backend` вернул пустой вывод. Не трактовать как доказательство отсутствия колонки в MySQL: ошибки путей скрывались. Следующий шаг: read-only `SHOW COLUMNS FROM categories;` в phpMyAdmin, затем согласовать минимальную безопасную миграцию при необходимости. Не применять `007_product_wide_subscriptions.sql` без аудита: в GitHub он удаляет существующие subscriptions.

### Критическая находка в реальной MySQL (18.11.2026)
В базе `prorock_law` пользователь выполнил `SHOW COLUMNS FROM categories`: столбец **`show_price_stock tinyint(1) NOT NULL DEFAULT 1` уже существует**. Поля `hide_store_stock` в показанной схеме **нет**. Это противоположная семантика относительно GitHub (`hide_store_stock`). НЕЛЬЗЯ добавлять дубликат или заменять контроллер из GitHub без адаптации: сохранить данные, проверить серверную админку, наследование категорий, API и Android. Следующий шаг — найти фактическое использование `show_price_stock` в коде/админке и проверить сохранённые значения, прежде чем менять что-либо.

### Состояние настроек категорий в БД (18.11.2026)
`SELECT show_price_stock, COUNT(*) ... GROUP BY show_price_stock` в phpMyAdmin вернул **только `1 | 102`**. Все 102 категории имеют показ цен/остатков включённым; скрытых нет. Существующее поле `show_price_stock` использовать как источник истины, не вводить `hide_store_stock` без явной миграционной стратегии. Следующий шаг — read-only аудит реальной серверной админки `AdminCategoriesController` (чтение и сохранение `show_price_stock`) и GitHub-логики, прежде чем писать патч.

### Серверная админка категорий (18.11.2026)
В `backend/app/Controllers/AdminCategoriesController.php` подтверждены методы `index()` (21), `edit()` (109), `create()` (130), `update()` (143). Поиск `show_price_stock|hide_store_stock|checkbox` в файле пуст. Следующий шаг: read-only `sed -n '1,220p' .../AdminCategoriesController.php` (при необходимости порциями), чтобы увидеть сохранение и подключение шаблонов. Не добавлять второй столбец; production уже имеет `show_price_stock`.

### Получен код серверной админки категорий (18.11.2026)
Пользователь прислал фрагмент `AdminCategoriesController.php` с `edit/create/update/toggle/delete`. Подтверждено: edit форма не имеет checkbox `show_price_stock`; `create()` INSERT без этого поля (БД DEFAULT 1), `update()` UPDATE без поля. Есть CSRF, admin role, audit, проверка циклов и ограничения удаления — сохранить. Следующий шаг — проверить начало файла (`index()`/`all()`/`one()`/`fields()`) и/или GitHub код, чтобы сделать минимальный патч с существующим `show_price_stock`, а не дублировать `hide_store_stock`.

### Прочитано начало серверной админки категорий (18.11.2026)
`index()` формирует иерархическое дерево и сортировку, показывает форму создания только admin; в форме нет `show_price_stock`, таблица не имеет столбца видимости. Вместе с ранее присланными `edit/create/update` подтверждена необходимость минимального изменения форм и записи в существующий `categories.show_price_stock`. До патча проверить методы `all()/one()/fields()` ниже по файлу и текущую реализацию GitHub, чтобы не потерять поля или безопасность. На сервер ничего не развёрнуто.

### Расположение вспомогательных методов production AdminCategoriesController (18.11.2026)
По выводу `grep -nE 'function (all|one|fields)\\('`: `all()` — строка 220, `one(int $id)` — 225, `fields(array $data)` — 268. Их тела ещё не прочитаны. Следующий read-only шаг: `sed -n '220,300p'` серверного файла. После сверки с GitHub подготовить минимальный патч, используя существующий `show_price_stock`, без дублирующего `hide_store_stock`.

### Проверены `all()/one()/fields()` production (2026-11-18)
`all()` SELECT id,parent_id,name,slug,sort_order,image_url,is_active (нет `show_price_stock`); `one()` SELECT * (поле есть); `fields()` валидирует name/parent/sort/HTTPS image и возвращает 4 элемента (не принимает checkbox). Теперь известны форма создания, форма редактирования, SQL INSERT/UPDATE и вспомогательные методы. Готовить минимальный патч на базе реально работающей версии, избегая перезаписи иных функций GitHub-версией; продумать API и Android отдельно. Поле `show_price_stock` уже есть в БД, все 102 = 1.

### Выполнен первый патч админки (2026-11-18)
В `Stran22/svetofor22`, ветка `feature/excel-column-mapping`, коммит `959e9ee`: исправлен GitHub `backend/app/Controllers/AdminCategoriesController.php` для production столбца `show_price_stock`: формы создания/редактирования, SQL INSERT/UPDATE, список и групповая настройка. **НЕ развёрнуто на сервере, НЕ проверено php -l/тестами.** GitHub-версия имеет дополнительный `saveVisibility()` и групповую форму, отсутствующие в присланных фрагментах production: при деплое нужна сверка маршрута `/admin/categories/visibility` и совместимости остального приложения. Не применять GitHub миграции вслепую. Следующее: проверить синтаксис, API, затем инструкции по точечному деплою.

### 2026-11-18 — исправление после ревизии патча
Повторно проверен GitHub `backend/app/Controllers/AdminCategoriesController.php`: первоначальный патч содержал двойное присваивание `$showPriceStock` в `create()` и не содержал присваивания в `update()`. Исправлено коммитом `783e0b81ac6ca0f4e7e7f59aa0dcdc2e1470f7e9`: по одному присваиванию в каждом методе. PHP lint/функциональные тесты ещё не выполнены, на production не развёрнуто.

### 2026-11-18 — PHP lint исправленного контроллера успешно пройден
Пользователь выполнил в терминале ISPmanager `/opt/php85/bin/php -l /tmp/svet22_AdminCategoriesController_check.php` после скачивания файла из коммита `783e0b81ac6ca0f4e7e7f59aa0dcdc2e1470f7e9`. Результат: `No syntax errors detected in /tmp/svet22_AdminCategoriesController_check.php`. Это **только синтаксическая проверка**, не тест БД/маршрутов/форм. Production контроллер не заменён. Следующий шаг: проверить наличие маршрута POST `/admin/categories/visibility` в production `backend/bootstrap/app.php` и при необходимости избежать 404 групповой формы.

### 2026-11-18 — маршрут групповой видимости
Production bootstrap: POST `/admin/categories/visibility` отсутствует (grep пользователя), тогда как GitHub bootstrap уже содержит его строкой 94. Новая групповая форма в GitHub использует этот POST и `saveVisibility()`. При деплое требуется **согласованное обновление** bootstrap и контроллера либо временно отключить групповую форму; не заменять production bootstrap вслепую, поскольку там могут быть другие изменения. Следующий этап: безопасный точечный перенос маршрута с проверкой PHP lint и аудит API. Не утверждать, что GitHub bootstrap нуждается в новом маршруте.

### 2026-11-18 — API категорий и GitHub visibility service
Production `ApiController::categories()` не выбирает и не возвращает `show_price_stock` (SQL `SELECT id,parent_id,name,slug,image_url,sort_order ... WHERE is_active=1`). В GitHub `ApiController.php` использует `CategoryVisibilityService`, который пока читает `categories.hide_store_stock` (отсутствует в production). Следующий кодовый шаг — исправить сервис для чтения `show_price_stock` с инверсией в API `hide_store_stock`, проверить `products()/product()` и Android-клиент перед деплоем.

### 2026-11-18 — проверен production список товаров
Production `ApiController::products()` напрямую возвращает `s.price`, `s.quantity`, `s.imported_on`; не учитывает `show_price_stock`. Сохранять поиск, фильтры, сортировку, пагинацию. В GitHub `ApiController` уже использует `CategoryVisibilityService` только в `categories()`/`product()`, но не в `products()`. Сам сервис использует отсутствующий production `hide_store_stock`; нужен переход на `show_price_stock` с инверсией и наследованием, а также маскировка списка/карточки на сервере. Пока только аудит, production не менялся.

### 2026-11-18 — production visibility service отсутствует; GitHub исправлен
`ls -l /var/www/prorock/data/www/baza.altaj-otdyh.ru/backend/app/Services/CategoryVisibilityService.php` на сервере вернул «Нет такого файла или каталога». В GitHub сервис существует; коммит `c3f1b4b` заменил чтение несуществующего `hide_store_stock` на `show_price_stock` с инверсией и наследованием, сохранив API-ключ `hide_store_stock`. Сервис не установлен на production, API список товаров ещё не маскирует данные. Нужны проверки синтаксиса и безопасное согласованное развёртывание.

### 2026-11-18 — PHP lint CategoryVisibilityService успешен
Пользователь выполнил `/opt/php85/bin/php -l /tmp/svet22_CategoryVisibilityService_check.php` для GitHub файла коммита `c3f1b4b644b53cdedf850cb9f8ce31f262bd4414`; вывод: `No syntax errors detected in /tmp/svet22_CategoryVisibilityService_check.php`. Это только проверка синтаксиса. Production файл по-прежнему отсутствует, сервис не развёрнут. Следующий шаг — реализовать/проверить маскировку price/quantity в API `products()` и `product()` и предотвратить обход через фильтры.

### 2026-11-18 — production stock schema проверена
`SHOW COLUMNS FROM stock` в `prorock_law` подтверждает `price decimal(12,2) NOT NULL`, `quantity decimal(12,3) NOT NULL DEFAULT 0.000`, `product_id`, `branch_id`, `imported_on`, `updated_at`. Для категорий с запретом показа скрывать `price`/`quantity` в API-ответе (`null`), не изменяя stock или импорт XLSX. Сохранять филиалы, поиск, пагинацию. Следующий этап — реализация маскировки в GitHub `ApiController.php`, затем lint и тесты; production пока не менять.

### 2026-11-18 — production branches schema подтверждена
`SHOW COLUMNS FROM branches` подтвердил `id`, `code` (UNIQUE), `name`, `address`, `is_active tinyint(1) NOT NULL DEFAULT 1`, `created_at`, `updated_at`. Правило `can_track`: есть хотя бы один активный филиал; для каждого активного филиала известен stock.quantity; во всех он равен 0. GitHub `ApiController::product()` уже содержит SQL с `branches b WHERE b.is_active=1` и подсчётами `branch_count`, `known_count`, `zero_count`, но production API не содержит `can_track`. Требуется проверка/доработка и развёртывание; production не изменён.

### 2026-11-18 — проверено наполнение филиалов
Production read-only SQL: 3 активных филиала (ID 1 «На Волочаевской», ID 2 «на Иркутской», ID 3 «На Байкальском»), в каждом по 4 записи stock. Неизвестно, совпадают ли product_id и заполнены ли записи по каждому товару во всех филиалах. Следующий шаг — read-only SQL сверки product_id и нулевых остатков; не включать `can_track` на основе одного лишь числа записей.

### 2026-11-18 — результат проверки can_track на production данных
Активных товаров в результате запроса 4: ID 5 молоко (положительный остаток в 3 филиалах), ID 6 кефир (3), ID 7 хлеб (2 положительных, 1 нулевой), ID 8 сахар (3). У всех есть записи stock по всем трём активным филиалам. Для каждого ожидается `can_track=false`; ни один товар не отсутствует во всех магазинах. Для положительного теста нужен отдельный безопасный тестовый сценарий; не менять реальные остатки без согласования. GitHub `ApiController::product()` имеет `can_track`, production не имеет. Следующий этап — согласовать серверный API и маскировку цены/остатка, затем тестировать.

### 2026-11-18 — дубликаты stock отсутствуют
Проверка `GROUP BY product_id,branch_id HAVING COUNT(*)>1` в production вернула 0 строк. Существующие записи подходят для подсчёта наличия по филиалам, но отсутствие дублей сейчас не подтверждает уникальный индекс. Следующий этап: перейти от read-only аудита к точечной правке GitHub `ApiController.php` для маскировки price/quantity и проверки can_track; production не изменять до lint/тестов.

### 2026-11-18 — UNIQUE stock подтверждён
Production `SHOW INDEX FROM stock`: UNIQUE `uq_stock_product_branch(product_id,branch_id)` уже есть. Также `PRIMARY(id)`, `idx_stock_branch_quantity(branch_id,quantity)`, `idx_stock_updated(updated_at)`. Дубли защищены на уровне БД, никаких миграций не требуется. Следующий шаг — исправить GitHub API маскировку цены/остатка в списке и карточке, сохранив фильтры/сортировку/пагинацию и защитив от косвенного раскрытия.

### 2026-11-18 — начата серверная маскировка в GitHub API
Коммит `767aa0d` обновил `backend/app/Controllers/ApiController.php`: список и карточка товара маскируют цену/остаток для скрытых категорий, возвращают `hide_store_stock`; карточка маскирует даты остатков. Не считать функцию законченной: `sort=price_*`, `in_stock` и связанные count/order всё ещё могут раскрывать скрытые данные косвенно. Нужно закрыть эти пути, проверить lint и тесты, согласовать production bootstrap/controller/service перед развёртыванием. Production не изменён.

### 2026-11-18 — PHP lint GitHub ApiController успешен
Пользователь скачал GitHub `ApiController.php` коммита `767aa0d6f14618408f274444f95e375125f0efb6` во временный файл и выполнил `/opt/php85/bin/php -l /tmp/svet22_ApiController_check.php`: `No syntax errors detected in /tmp/svet22_ApiController_check.php`. Только синтаксическая проверка; production не развёрнут. Следующий шаг — закрыть косвенное раскрытие через сортировку и `in_stock`.

### 2026-11-18 — GitHub API скрывает сортировку и in_stock
Коммит `3a14c33` меняет `ApiController::products()`: формирует скрытые category_id из `CategoryVisibilityService`, сортирует по `CASE WHEN hidden THEN NULL ELSE s.price END` вместо реальной цены, при `in_stock=true` исключает скрытые категории. Это устраняет прямое раскрытие через sort/in_stock, но функциональные тесты и PHP lint этого коммита не выполнены. Production не менялся. Следующий шаг: PHP lint новой версии API, затем тесты и согласованный деплой.

### 2026-11-18 — PHP lint ApiController с защитой сортировки успешен
Пользователь скачал `backend/app/Controllers/ApiController.php` из коммита `3a14c33589e8419b92560aefb91bc7d21f77425d` во временный файл `/tmp/svet22_ApiController_check.php` и выполнил `/opt/php85/bin/php -l`: результат `No syntax errors detected in /tmp/svet22_ApiController_check.php`. Это только синтаксическая проверка, функциональные тесты/деплой ещё не проведены. Следующий этап — проверить совместимость исходников и подготовить безопасную проверку API без замены production файлов.

### 2026-11-18 — baseline API подтверждён HTTP 200
Production `GET /api/v1/products?per_page=2` возвращает HTTP/2 200 и два stock-рядa товара ID 6 «Кефир 1%» (филиалы 1/2, цена 74.00/75.12, количество 5/12), `total=4,total_pages=2`. API считает total уникальных товаров, но LIMIT/OFFSET применяется к строкам product×stock. Это отдельный обнаруженный дефект пагинации; не менять одновременно с маскировкой без отдельного плана/тестов. `show_price_stock`/`hide_store_stock` в baseline отсутствуют. Следующий шаг: проверить JSON карточки товара, сравнить API и GitHub перед безопасным деплоем.

### 2026-11-18 — baseline карточки товара подтверждён
Production GET `/api/v1/products/6` HTTP/2 200: кефир ID 6, category_id 55, три branches с price/quantity (74/5, 75.12/12, 78/3), reviews_count=0, rating=null. Нет `hide_store_stock`/`can_track`. Ожидаемый `can_track=false` (все филиалы имеют положительный остаток). Сохранить форму ответа branches/reviews. Production не изменялся.

### 2026-11-18 — baseline API категорий подтверждён
GET `/api/v1/categories` production: HTTP/2 200, JSON `success:true`, список с `id,parent_id,name,slug,image_url,sort_order`. Иерархия ID 52→55/54. Нет `hide_store_stock`; новая GitHub версия API ещё не развёрнута. Все три read-only endpoint baseline (`categories`, `products`, `products/6`) проверены. Далее требуется согласовать версию production bootstrap, миграции/сервисы и сделать резервные копии до установки.

### 2026-11-18 — production bootstrap route check
Пользователь выполнил `grep -n -E 'categories/visibility|AdminCategoriesController|ApiController' /var/www/prorock/data/www/baza.altaj-otdyh.ru/backend/bootstrap/app.php`. Вывод: строка 6 `use Svet22\\Controllers\\ApiController;`, строка 29 `$api = new ApiController($db);`, строка 31 `$adminCategories = new \\Svet22\\Controllers\\AdminCategoriesController($db);`. `categories/visibility` не найден. Не делать вывод, что все маршруты категорий отсутствуют: regex их не охватывал. Нужно получить точный блок маршрутов перед точечной вставкой; не заменять bootstrap целиком. Production не изменён.

### 2026-11-18 — проверен блок production маршрутов 65–115
Пользователь показал `sed -n '65,115p' .../backend/bootstrap/app.php`. Маршруты категорий: GET `/admin/categories` index; POST `/admin/categories` create; POST `/admin/categories/{id}/toggle` toggle; POST `/admin/categories/{id}/delete` delete; GET `/admin/categories/{id}/edit` edit; POST `/admin/categories/{id}/edit` update. Маршрута POST `/admin/categories/visibility` нет. В GitHub контроллере он нужен для `saveVisibility()`. План: точечно вставить `$router->post('/admin/categories/visibility', [$adminCategories, 'saveVisibility']);` сразу после POST `/admin/categories` и до параметризованных маршрутов. Не заменять bootstrap целиком, чтобы сохранить импорт, акции, филиалы, пуши и пользователей. Перед правкой сделать резервную копию, после — PHP lint. Production ещё не менялся.

### 2026-11-18 — production bootstrap маршрут установлен
Пользователь выполнил точечную вставку в `/var/www/prorock/data/www/baza.altaj-otdyh.ru/backend/bootstrap/app.php`: `POST /admin/categories/visibility` → `AdminCategoriesController::saveVisibility()` после POST `/admin/categories`. Терминал: `Маршрут успешно добавлен.`; `/opt/php85/bin/php -l .../bootstrap/app.php`: `No syntax errors detected`. Перед изменением создана и проверена резервная копия `bootstrap/app.php.bak-20261118` (6,8K). ВАЖНО: production `AdminCategoriesController` ещё старый и метод `saveVisibility()` может отсутствовать; не использовать групповой POST до установки нового контроллера. Следующий шаг — подготовить резервные копии трёх PHP-файлов и синхронную замену; не менять bootstrap целиком.

### 2026-11-18 — резервные копии контроллеров production
Пользователь выполнил `cp -p` для трёх файлов: созданы `backend/app/Controllers/AdminCategoriesController.php.bak-20261118` и `backend/app/Controllers/ApiController.php.bak-20261118`; `backend/app/Services/CategoryVisibilityService.php` на production **отсутствует**, это новый GitHub сервис, его нужно создать при деплое. Production контроллеры пока не заменены. Production bootstrap уже имеет добавленный POST `/admin/categories/visibility` и резервную копию. Следующий шаг — скачать три файла во временный каталог, проверить lint и совместимость, затем атомарно заменить контроллеры и добавить сервис.

### 2026-11-18 — три PHP-файла скачаны во временную папку, lint успешен
Пользователь скачал из `feature/excel-column-mapping` в `/tmp/svet22-deploy-20261118/` файлы `AdminCategoriesController.php`, `ApiController.php`, `CategoryVisibilityService.php`. Все три `/opt/php85/bin/php -l` выдали `No syntax errors detected`. Production файлы ещё не заменены. Есть backups двух контроллеров и bootstrap; новый сервис отсутствует на production. Следующий шаг — проверка методов/ссылок на сервис и совместимости перед установкой.

### 2026-11-18 — проверены связи трёх временных PHP-файлов
Пользователь выполнил grep в `/tmp/svet22-deploy-20261118/*.php`. Подтверждены: `AdminCategoriesController::saveVisibility` (стр.125), формы `show_price_stock`, SQL INSERT/UPDATE/SELECT по `categories.show_price_stock`; `ApiController` импортирует `CategoryVisibilityService`, использует `all()` в categories/products и `forCategory()` в product, выдаёт `hide_store_stock`; `CategoryVisibilityService` читает `id,parent_id,show_price_stock` и наследует `hide_store_stock`. Все три PHP lint OK ранее. Production файлы пока не заменены. Перед установкой проверить сохранность публичных методов старых контроллеров, затем безопасная установка.

### 2026-11-18 — сравнение публичных методов production и staging
Пользователь сравнил `grep -E 'public function '` в рабочих и `/tmp/svet22-deploy-20261118` контроллерах. `AdminCategoriesController` сохраняет __construct,index,edit,create,update,toggle,delete и добавляет `saveVisibility`; `ApiController` сохраняет __construct,health,healthDb,branches,categories,products,product,productReviews,createProductReview,registerDevice,me,logout (12 включая конструктор). Синтаксис всех трёх файлов уже проверен. Следующий шаг — установить новый сервис в `backend/app/Services/` (он отсутствовал) с lint; затем отдельно синхронно установить контроллеры и проверить API. Production bootstrap маршрут уже установлен.

### 2026-11-18 — production CategoryVisibilityService установлен
Пользователь установил `/tmp/svet22-deploy-20261118/CategoryVisibilityService.php` в `backend/app/Services/CategoryVisibilityService.php` через `install -m 664`. Вывод: `No syntax errors detected in app/Services/CategoryVisibilityService.php`, `-rw-rw-r-- 1 prorock prorock 1,6K ноя 18 13:30`. Контроллеры production пока не заменены; их backups `.bak-20261118` готовы. Следующий шаг — установить оба контроллера, проверить lint и три API endpoints, не менять DB.

### 2026-11-18 — production контроллеры установлены
Пользователь выполнил `install -m 664` для `/tmp/svet22-deploy-20261118/AdminCategoriesController.php` → `backend/app/Controllers/AdminCategoriesController.php` и `ApiController.php` → `backend/app/Controllers/ApiController.php`. Оба `/opt/php85/bin/php -l` успешны (`No syntax errors detected`). `CategoryVisibilityService.php` установлен ранее, bootstrap маршрут `/admin/categories/visibility` установлен ранее. Backup двух контроллеров `.bak-20261118` и bootstrap сохранены. **Следующий шаг — проверить production GET API после деплоя; пока не менять `show_price_stock`.**

### 2026-11-18 — production GET /api/v1/products/6 после установки успешен
Пользователь выполнил `curl -sS -i https://baza.altaj-otdyh.ru/api/v1/products/6`; HTTP/2 200, `success:true`, продукт ID6 `Кефир 1%`, category_id=55, 3 branches: (1,74.00,5.000), (2,75.12,12.000), (3,78.00,3.000). Новые поля `hide_store_stock:false`, `can_track:false`; `reviews:{reviews_count:0,rating:null}`. Production ApiController и CategoryVisibilityService выполняются без ошибки на этой карточке. Следующий шаг — GET `/api/v1/categories` и затем `/api/v1/products?per_page=2`, затем безопасно проверить скрытие категории с возвратом настройки.

### 2026-11-18 — production GET /api/v1/categories после установки успешен
Пользователь выполнил read-only curl + PHP JSON decode: `success:true`, 102 категории. Категории 54 `Кисломолочные продукты`, 55 `Молоко и сливки`, 52 `Молочные продукты` имеют `hide_store_stock:false`. API категорий работает с новым полем. Следующий шаг — GET `/api/v1/products?per_page=2` и проверка цен/остатков и `hide_store_stock` без изменения БД.

### 2026-11-18 — production GET /api/v1/products?per_page=2 успешен
Пользователь выполнил curl + PHP JSON decode. `success:true`, 2 записи: `Кефир 1%` филиал 1 цена 74.00 остаток 5.000 hide_store_stock=false; филиал 2 цена 75.12 остаток 12.000 hide_store_stock=false. Вместе с предыдущими тестами подтверждены GET категорий (102), списка товаров и карточки товара (ID6). Следующий шаг — проверить админку категорий и сохранение настройки скрытия цен/остатков, затем наследование, с восстановлением исходного значения. Не считать POST проверенным до реального теста.
