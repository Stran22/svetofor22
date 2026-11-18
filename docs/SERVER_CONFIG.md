# Светофор — конфигурация и пути

Аудит 2026-11-18. Параметры ниже частично подтверждены исходниками, а частично ранее указанной конфигурацией сервера. Нельзя считать их текущими production-значениями без подключения к серверу.

## Публичные адреса и точки входа
- Домен: `https://baza.altaj-otdyh.ru`.
- Android Retrofit `API_BASE_URL=https://baza.altaj-otdyh.ru/api/v1/` (файл `android/app/src/main/java/ru/svet22/app/data/remote/RetrofitClient.kt`).
- Web root должен указывать на `backend/public/`; `backend/public/index.php` загружает `backend/bootstrap/app.php` (Apache rewrite).
- `backend/public/.htaccess` содержит `CGIPassAuth On`, правила rewrite и `SetEnv SVET_CONFIG_FILE /var/www/prorock/data/secure/svet22/config.php`. Последний путь — путь из репозитория; убедиться в его существовании на сервере.

## Backend
- `composer.json`: PHP `^8.5`, platform `8.5.12`; `phpoffice/phpspreadsheet ^2.2`, ext-json/pdo/pdo_mysql/fileinfo/mbstring/zip/xml/xmlreader/xmlwriter/simplexml.
- Исторически подтверждённая среда владельца: ISPmanager, узел `cassiopeia`, Apache 2.4.52, MySQL 8, PHP 8.5.12, Composer. Проверить перед развёртыванием.
- `backend/app/Core/Config.php` обязательно читает **внешний** конфиг по env `SVET_CONFIG_FILE`, настройки в `backend/config/config.example.php`.
- Ключи конфигурации: `app.env`, `app.debug`, `app.timezone=Asia/Barnaul`, `app.base_url`, `database.host/port/name/username/password/charset`, `firebase.project_id/credentials_path`, `security.token_bytes/health_token`; VK ID конфиг см. `docs/vk-id-setup.md`.
- `backend/app/Core/Database.php` использует PDO, prepared statements, фиксирует MySQL session `time_zone='+07:00'`.

## Android
- Открывать каталог `android/` в Android Studio Panda 4; JDK 17.
- `android/app/build.gradle.kts`: `applicationId=ru.svet22.app`, `compileSdk=34`, `minSdk=26`, `targetSdk=34`, Kotlin/Compose, Retrofit, Firebase Messaging, VK ID.
- Gradle Wrapper **8.7**, `android/gradlew.bat` на Windows, `android/gradlew` на Unix; `android/gradle/wrapper/gradle-wrapper.properties`.
- Firebase Android `google-services.json` хранится вне Git и в CI восстанавливается из секрета `GOOGLE_SERVICES_JSON_BASE64`.

## Операции
- PHP lint: `composer lint`. Unit tests: `composer test`.
- SQL migration CLI: `php bin/migrate.php`, но **только после проверки резервной копии** и `schema_migrations`; `007_product_wide_subscriptions.sql` удаляет subscriptions.
- FCM worker: оба файла `bin/process_push_queue.php` и `bin/process_push_queue_v2.php`, режим по умолчанию dry-run, `--send` отправляет реальные уведомления. **Не угадывать, какой стоит в ISPmanager Cron**. Исторически обсуждался запуск по расписанию, но код GitHub не подтверждает текущую cron-команду.
- CI workflows: `.github/workflows/php-checks.yml`, `excel-import-safety.yml`, `android-build.yml`.
- Никогда не коммитить пароль БД, service-account Firebase, токены VK, реальные API ключи, `google-services.json`.

## Безопасная диагностика
```bash
php -v
composer --version
git branch --show-current
git rev-parse HEAD
```
Проверку `schema_migrations` выполнять через read-only запросы из `docs/DATABASE.md`. Deploy, изменение Cron и отправка push только с разрешения владельца.

## Проверено по терминалу ISPmanager 2026-11-18 (без изменений на сервере)
- Текущая папка терминала при проверке: `/var/www/prorock/data` (не корень сайта). Для команд использовать абсолютные пути или явный `cd`.
- Путь сайта: `/var/www/prorock/data/www/baza.altaj-otdyh.ru/`.
- Найден действующий на диске файл `backend/app/Controllers/AdminCategoriesController.php`; также существуют `backend/app/Controllers/ApiController.php`, `backend/app/Controllers/SubscriptionController.php` и другие контроллеры.
- На диске отдельно есть `.deploy/backend/app/Controllers/AdminController.php` и `.deploy/backend/app/Controllers/ApiController.php`. **Не установлено**, какая копия реально подключена Apache: наличие файла не доказывает его использование.
- В серверной папке Android найден `android/app/src/main/java/ru/svet22/app/MainActivity.kt`; grep показал код филиалов/остатков, но не показал строк подписки. Это не подтверждает соответствие серверной Android-копии актуальному GitHub или установленному APK.
- Ранее подтверждено существование внешнего конфига `/var/www/prorock/data/secure/svet22/config.php`; секреты не читать и не публиковать. Для CLI, если требуется, `SVET_CONFIG_FILE` задаётся отдельно.
- На сервере ранее наблюдались лишь файлы миграций `001_initial_schema.sql`, `002_admin_panel.sql`, `006_search_analytics.sql`, хотя в GitHub их больше. Не переносить и не запускать миграции автоматически.
- База `prorock_law`: 28 таблиц, включая `categories`, `stock`, `subscriptions`, `excel_column_mappings`, `reviews`, `push_delivery_log`. По ранее полученному списку полей `categories` **не содержала** `hide_store_stock`; перепроверить `SHOW COLUMNS` непосредственно перед решением о миграции.
- **Вывод:** GitHub и сервер могут находиться на разных версиях. До деплоя нужны read-only сравнение файлов, веб-корня, миграций и резервная копия. Действующий Android UI не заменять упрощённой серверной копией.

## Подтверждено пользователем в ISPmanager — 2026-11-18
- **Корень домена `baza.altaj-otdyh.ru` в настройках ISPmanager:** `/backend/public` (относительно каталога сайта), полный путь `/var/www/prorock/data/www/baza.altaj-otdyh.ru/backend/public`.
- **Версия PHP, назначенная домену:** PHP 8.5 (ISPmanager). Отдельно CLI `/opt/php85/bin/php -v` показал **PHP 8.5.12**. Точный patch-level PHP для HTTP и SAPI пока не измерены, не приравнивать их автоматически к CLI.
- Файл активной точки входа `backend/public/index.php` подтверждён выводом `cat`: `<?php`, `declare(strict_types=1);`, `use Svet22\\Core\\Config;`, `require dirname(__DIR__) . '/bootstrap/app.php';`. Следовательно, он подключает `/var/www/prorock/data/www/baza.altaj-otdyh.ru/backend/bootstrap/app.php`.
- `public/` в корне проекта содержит лишь `.htaccess` с `SetEnv SVET_CONFIG_FILE /var/www/prorock/data/secure/svet22/config.php`; это **не** web-root домена. `public.backup-20261101/` — резервная папка.
- Для диагностики работающих маршрутов читать `backend/bootstrap/app.php` и используемые им контроллеры, а не `.deploy/`.

### Проверка загрузчика и контроллеров (вывод grep, 2026-11-18)
В **серверном** `backend/bootstrap/app.php` подтверждены: строки 6 `use Svet22\\Controllers\\ApiController;`, 9 `use Svet22\\Controllers\\SubscriptionController;`, 14 `use Svet22\\Core\\Router;`, 16 `require dirname(__DIR__, 2) . '/vendor/autoload.php';`, 25 `$router = new Router();`, 29 `$api = new ApiController($db);`, 31 `$adminCategories = new \\Svet22\\Controllers\\AdminCategoriesController($db);`, 35 `$subscriptions = new SubscriptionController($db);`. Следовательно, приложение использует Composer autoload в корне проекта и создаёт экземпляры основных контроллеров из namespace `Svet22`. Это подтверждает цепочку загрузки, но не все фактические зарегистрированные маршруты: для них требуется полный блок регистрации роутера.

### Реальные маршруты из `backend/bootstrap/app.php` (вывод grep пользователя, 2026-11-18)
Подтверждены: GET `/api/v1/categories` (40), GET `/api/v1/products` (41), GET `/api/v1/featured-products` (42), GET `/api/v1/home-promotions` (44), GET `/api/v1/contests` (45), GET `/api/v1/contests/{id}` (46), POST `/api/v1/contests/{id}/join` (47), GET `/api/v1/products/{id}` (49), GET/POST `/api/v1/products/{id}/reviews` (50–51), GET/POST `/api/v1/subscriptions` (56–57), POST `/api/v1/subscriptions/cancel` (58), GET `/admin/contests` и страницы/изображения/участники конкурсов (79–85), GET/POST `/admin/featured` (86–87), GET/POST `/admin/products` и фото (88–91), GET/POST `/admin/categories`, toggle, delete, edit/update (92–97). В предоставленном grep **нет** `/admin/categories/visibility`. Это отсутствие в выводе, а не доказательство отсутствия любого альтернативного маршрута/обработчика.

### Проверка серверного ApiController (18.11.2026)
Команда `grep -nE 'function product\\(|can_track|hide_store_stock|function categories\\(' backend/app/Controllers/ApiController.php` дала только строки 56 `public function categories(Request $request): never` и 188 `public function product(Request $request, array $params): never`. **Буквальные идентификаторы `can_track` и `hide_store_stock` отсутствуют в этом работающем серверном файле.** Это не доказывает отсутствие эквивалентной логики в других файлах. Требуется проверка ответов API и сверка реализации до синхронизации.

### Фактический `ApiController::product()` на сервере (18.11.2026)
Пользователь предоставил `sed -n '188,270p' backend/app/Controllers/ApiController.php`. Метод `product()` выбирает активный товар из `products` (id, barcode, article, name, category_id, manufacturer, unit, image_url, description), при отсутствии отвечает `PRODUCT_NOT_FOUND`/404; затем `SELECT branch_id, price, quantity, imported_on, updated_at FROM stock WHERE product_id = ? ORDER BY branch_id` без JOIN `branches` и без `branches.is_active`; добавляет `branches` и агрегат `reviews` (`COUNT(*)`, `AVG(rating)` только `status='published'`); вызывает `Response::success($product)`. В этом методе **нет `can_track` или `hide_store_stock`**, а `stock` не фильтруется по активным филиалам. Далее начинается отдельный метод `productReviews()`. Сохранить контракт `branches`/`reviews` при исправлениях.
