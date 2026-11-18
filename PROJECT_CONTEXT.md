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
